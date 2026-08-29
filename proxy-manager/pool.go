package main

import (
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"sync"
	"time"
)

// Entry is one member of the pool: a proxy identity (URL + user-agent) together
// with its circuit-breaker health state.
//
// Per CONTRACTS.md the pool holds both fields so real proxies drop in later with
// no code change; today ProxyURL is nil on every seed and only the user-agent
// rotates.
type Entry struct {
	ID        string
	ProxyURL  *string // nil = direct connection; serialised as JSON null, never ""
	UserAgent string

	// Failures is the count of consecutive proxy-attributable failures since the
	// last success or bench. Reset by a success and by a bench.
	Failures int

	// BenchCount is how many times this entry has been benched since its last
	// success. It drives cooldown length and is reset ONLY by a successful trial,
	// never by the failure counter resetting.
	BenchCount int

	// CooldownUntil is the instant the entry may be leased again. Zero means the
	// entry is not benched.
	CooldownUntil time.Time

	// LastUsed is the instant the entry was last leased. It is the LRU selection
	// key. Zero means never leased.
	LastUsed time.Time

	// Probation is true from the moment a cooldown elapses until the next report
	// about the entry. While on probation a single proxy-attributable failure
	// re-benches immediately.
	Probation bool
}

// seedEntry is the JSON shape of one element of the pool seed file.
type seedEntry struct {
	ID        string  `json:"id"`
	ProxyURL  *string `json:"proxy_url"`
	UserAgent string  `json:"user_agent"`
}

// PoolConfig carries the tunables from the environment (CONTRACTS.md section 2).
type PoolConfig struct {
	FailureThreshold int
	CooldownBase     time.Duration
	CooldownMax      time.Duration
	LeaseTTL         time.Duration
}

// outstandingLease records a lease that has been issued but not yet reported.
type outstandingLease struct {
	entryID  string
	issuedAt time.Time
}

// Pool is the proxy-identity pool and its circuit-breaker state machine. It is
// safe for concurrent use and does not depend on the HTTP layer, so it can be
// unit-tested without starting a server.
type Pool struct {
	mu      sync.RWMutex
	entries []*Entry
	byID    map[string]*Entry
	leases  map[string]outstandingLease

	failureThreshold int
	cooldownBase     time.Duration
	cooldownMax      time.Duration
	leaseTTL         time.Duration

	leasesIssued    int64
	reportsReceived int64

	// now and nextID are injectable so tests can drive time and lease ids
	// deterministically. Production wiring leaves the defaults.
	now    func() time.Time
	nextID func() string
}

// Lease is the value returned to a caller of GET /lease.
type Lease struct {
	LeaseID   string
	ProxyURL  *string
	UserAgent string
}

// NoHealthyError is returned by Lease when every entry is cooling down (or the
// pool is empty). RetryAfter is the seconds until the soonest cooldown expiry.
type NoHealthyError struct{ RetryAfter int }

func (e *NoHealthyError) Error() string { return "no healthy entries" }

// ErrUnknownLease is returned by Report for a lease id that is unknown, already
// reported, or reaped.
var ErrUnknownLease = errors.New("unknown or already-reported lease")

// NewPool validates the seeds and builds a pool. It returns an error rather than
// falling back to a hardcoded list, so a misconfigured seed file surfaces at
// startup instead of hiding behind apparently-working behaviour.
func NewPool(seeds []seedEntry, cfg PoolConfig) (*Pool, error) {
	if len(seeds) == 0 {
		return nil, errors.New("proxy pool seed contains no entries")
	}

	entries := make([]*Entry, 0, len(seeds))
	byID := make(map[string]*Entry, len(seeds))
	for i, s := range seeds {
		switch {
		case s.ID == "":
			return nil, fmt.Errorf("seed entry %d: missing id", i)
		case s.UserAgent == "":
			return nil, fmt.Errorf("seed entry %q: missing user_agent", s.ID)
		case byID[s.ID] != nil:
			return nil, fmt.Errorf("seed entry %d: duplicate id %q", i, s.ID)
		}
		e := &Entry{ID: s.ID, ProxyURL: s.ProxyURL, UserAgent: s.UserAgent}
		entries = append(entries, e)
		byID[s.ID] = e
	}

	if cfg.FailureThreshold < 1 {
		cfg.FailureThreshold = 1
	}

	return &Pool{
		entries:          entries,
		byID:             byID,
		leases:           make(map[string]outstandingLease),
		failureThreshold: cfg.FailureThreshold,
		cooldownBase:     cfg.CooldownBase,
		cooldownMax:      cfg.CooldownMax,
		leaseTTL:         cfg.LeaseTTL,
		now:              time.Now,
		nextID:           randomID,
	}, nil
}

// loadSeedFile reads and parses the pool seed file. Any problem — missing,
// unreadable, not a JSON array of the expected shape, trailing garbage — is an
// error; the caller exits on it.
func loadSeedFile(path string) ([]seedEntry, error) {
	f, err := os.Open(path)
	if err != nil {
		return nil, fmt.Errorf("open proxy pool file %q: %w", path, err)
	}
	defer f.Close()

	dec := json.NewDecoder(f)
	dec.DisallowUnknownFields()

	var seeds []seedEntry
	if err := dec.Decode(&seeds); err != nil {
		return nil, fmt.Errorf("parse proxy pool file %q: %w", path, err)
	}
	if dec.More() {
		return nil, fmt.Errorf("parse proxy pool file %q: trailing data after JSON array", path)
	}
	return seeds, nil
}

// Lease picks the least-recently-leased entry that is not cooling down, stamps
// its LastUsed under the write lock, and records an outstanding lease. Stamping
// under the lock is what guarantees a recovering entry goes to exactly one
// caller: the next concurrent lease sees the fresh LastUsed and picks elsewhere.
func (p *Pool) Lease() (Lease, error) {
	p.mu.Lock()
	defer p.mu.Unlock()

	now := p.now()
	p.promoteLocked(now)
	p.reapLocked(now)

	var pick *Entry
	for _, e := range p.entries {
		if e.benched(now) {
			continue
		}
		if pick == nil || e.LastUsed.Before(pick.LastUsed) {
			pick = e
		}
	}
	if pick == nil {
		return Lease{}, &NoHealthyError{RetryAfter: p.retryAfterLocked(now)}
	}

	pick.LastUsed = now
	id := p.nextID()
	p.leases[id] = outstandingLease{entryID: pick.ID, issuedAt: now}
	p.leasesIssued++

	return Lease{LeaseID: id, ProxyURL: pick.ProxyURL, UserAgent: pick.UserAgent}, nil
}

// Report consumes a lease and applies the health rule. A lease is single-use:
// the second report for the same id gets ErrUnknownLease. Failure attribution
// (isProxyFailure) lives in Go, not the caller.
func (p *Pool) Report(leaseID string, ok bool, statusCode int) error {
	p.mu.Lock()
	defer p.mu.Unlock()

	now := p.now()
	p.promoteLocked(now)
	p.reapLocked(now)

	l, exists := p.leases[leaseID]
	if !exists {
		return ErrUnknownLease
	}
	delete(p.leases, leaseID)
	p.reportsReceived++ // counted only for reports that matched a live lease

	e := p.byID[l.entryID]
	if e == nil {
		return nil // pool is static, so this cannot happen; guard anyway
	}

	if isProxyFailure(ok, statusCode) {
		e.Failures++
		if e.Probation || e.Failures >= p.failureThreshold {
			p.benchLocked(e, now)
		}
		return nil
	}

	// Not proxy-attributable. A clean success (ok:true) clears probation and
	// resets both counters — "consecutive, not cumulative". A non-attributable
	// failure (404/410/3xx with ok:false) is evidence about the URL, not the
	// proxy, so it leaves the entry's state untouched.
	if ok {
		e.Failures = 0
		e.BenchCount = 0
		e.Probation = false
	}
	return nil
}

// ReapNow discards leases older than the TTL. Lease and Report also reap lazily;
// this exists for the idle case, where a service receiving no traffic would
// otherwise never clear an abandoned lease.
func (p *Pool) ReapNow() {
	p.mu.Lock()
	defer p.mu.Unlock()
	p.reapLocked(p.now())
}

// reapLocked drops outstanding leases past the TTL. A reaped lease is discarded
// and does NOT count as a failure: no report means no evidence about the entry.
func (p *Pool) reapLocked(now time.Time) {
	for id, l := range p.leases {
		if now.Sub(l.issuedAt) >= p.leaseTTL {
			delete(p.leases, id)
		}
	}
}

// promoteLocked returns any entry whose cooldown has elapsed to the rotation, on
// probation. BenchCount is deliberately preserved — it only resets on a success.
func (p *Pool) promoteLocked(now time.Time) {
	for _, e := range p.entries {
		if !e.CooldownUntil.IsZero() && !now.Before(e.CooldownUntil) {
			e.CooldownUntil = time.Time{}
			e.Probation = true
		}
	}
}

// benchLocked puts an entry on the naughty step. Cooldown length is driven by
// BenchCount (times benched), not by the failure counter: base, then doubling
// each subsequent bench, capped at cooldownMax.
func (p *Pool) benchLocked(e *Entry, now time.Time) {
	e.CooldownUntil = now.Add(cooldownFor(p.cooldownBase, p.cooldownMax, e.BenchCount))
	e.BenchCount++
	e.Probation = false
	e.Failures = 0 // the bench consumes the failure streak
}

// cooldownFor is base * 2^priorBenches, clamped to max. The loop bails at max,
// so it cannot overflow for any realistic bench count.
func cooldownFor(base, max time.Duration, priorBenches int) time.Duration {
	d := base
	for i := 0; i < priorBenches; i++ {
		d *= 2
		if d >= max {
			return max
		}
	}
	if d > max {
		return max
	}
	return d
}

// retryAfterLocked is the seconds until the soonest cooldown expiry, rounded up,
// minimum 1. With nothing cooling down (e.g. an empty pool) it is 1.
func (p *Pool) retryAfterLocked(now time.Time) int {
	var soonest time.Time
	for _, e := range p.entries {
		if e.CooldownUntil.IsZero() || !now.Before(e.CooldownUntil) {
			continue
		}
		if soonest.IsZero() || e.CooldownUntil.Before(soonest) {
			soonest = e.CooldownUntil
		}
	}
	if soonest.IsZero() {
		return 1
	}
	d := soonest.Sub(now)
	secs := int(d / time.Second)
	if d%time.Second != 0 {
		secs++
	}
	if secs < 1 {
		secs = 1
	}
	return secs
}

// benched reports whether the entry is currently cooling down.
func (e *Entry) benched(now time.Time) bool {
	return !e.CooldownUntil.IsZero() && now.Before(e.CooldownUntil)
}

// isProxyFailure is the failure-attribution rule from CONTRACTS.md section 3 —
// the one place it lives. A report only counts against an entry when it is
// proxy-attributable: ok:false with a status of 0 (no response), 403, 407, 408,
// 429, 502, 503 or 504. A 404/410 is a bad URL, and any 2xx/3xx is not a
// failure at all.
func isProxyFailure(ok bool, statusCode int) bool {
	if ok {
		return false
	}
	switch statusCode {
	case 0, 403, 407, 408, 429, 502, 503, 504:
		return true
	default:
		return false
	}
}

// Metrics is the GET /metrics response shape (CONTRACTS.md section 3).
type Metrics struct {
	PoolSize        int           `json:"pool_size"`
	Healthy         int           `json:"healthy"`
	Benched         int           `json:"benched"`
	LeasesIssued    int64         `json:"leases_issued"`
	ReportsReceived int64         `json:"reports_received"`
	Entries         []EntryMetric `json:"entries"`
}

// EntryMetric is one row of Metrics.Entries. CooldownUntil and LastUsed are
// pointers so they serialise as JSON null when absent rather than as the zero
// time.
type EntryMetric struct {
	ID            string  `json:"id"`
	Healthy       bool    `json:"healthy"`
	Failures      int     `json:"failures"`
	CooldownUntil *string `json:"cooldown_until"`
	LastUsed      *string `json:"last_used"`
}

// Snapshot builds the metrics value under a read lock and returns it. The
// caller encodes it after the lock is released — no lock is held across a JSON
// encode.
func (p *Pool) Snapshot() Metrics {
	p.mu.RLock()
	defer p.mu.RUnlock()

	now := p.now()
	m := Metrics{
		PoolSize:        len(p.entries),
		LeasesIssued:    p.leasesIssued,
		ReportsReceived: p.reportsReceived,
		Entries:         make([]EntryMetric, 0, len(p.entries)),
	}
	for _, e := range p.entries {
		benched := e.benched(now)
		if benched {
			m.Benched++
		} else {
			m.Healthy++
		}
		row := EntryMetric{ID: e.ID, Healthy: !benched, Failures: e.Failures}
		if benched {
			s := e.CooldownUntil.UTC().Format(time.RFC3339)
			row.CooldownUntil = &s
		}
		if !e.LastUsed.IsZero() {
			s := e.LastUsed.UTC().Format(time.RFC3339)
			row.LastUsed = &s
		}
		m.Entries = append(m.Entries, row)
	}
	return m
}

// randomID is the production lease-id generator: an opaque 128-bit hex string.
func randomID() string {
	var b [16]byte
	if _, err := rand.Read(b[:]); err != nil {
		// crypto/rand failure is effectively impossible; degrade rather than panic.
		return fmt.Sprintf("lease-%d", time.Now().UnixNano())
	}
	return hex.EncodeToString(b[:])
}
