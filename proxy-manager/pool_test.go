package main

import (
	"fmt"
	"strings"
	"sync"
	"testing"
	"time"
)

// fakeClock is an injectable, race-safe clock for driving cooldowns without
// real waits.
type fakeClock struct {
	mu sync.Mutex
	t  time.Time
}

func (c *fakeClock) Now() time.Time {
	c.mu.Lock()
	defer c.mu.Unlock()
	return c.t
}

func (c *fakeClock) Advance(d time.Duration) {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.t = c.t.Add(d)
}

const (
	testThreshold    = 3
	testCooldownBase = 30 * time.Second
	testCooldownMax  = 600 * time.Second
	testLeaseTTL     = 120 * time.Second
)

// newTestPool builds a pool of the given ids with a fake clock and deterministic
// lease ids (L1, L2, ...).
func newTestPool(t *testing.T, ids ...string) (*Pool, *fakeClock) {
	t.Helper()
	seeds := make([]seedEntry, len(ids))
	for i, id := range ids {
		seeds[i] = seedEntry{ID: id, UserAgent: "UA-" + id}
	}
	p, err := NewPool(seeds, PoolConfig{
		FailureThreshold: testThreshold,
		CooldownBase:     testCooldownBase,
		CooldownMax:      testCooldownMax,
		LeaseTTL:         testLeaseTTL,
	})
	if err != nil {
		t.Fatalf("NewPool: %v", err)
	}
	clk := &fakeClock{t: time.Date(2026, 1, 1, 0, 0, 0, 0, time.UTC)}
	p.now = clk.Now
	var seq int
	p.nextID = func() string { seq++; return fmt.Sprintf("L%d", seq) }
	return p, clk
}

func mustLease(t *testing.T, p *Pool) Lease {
	t.Helper()
	l, err := p.Lease()
	if err != nil {
		t.Fatalf("Lease: unexpected error %v", err)
	}
	return l
}

func leasedID(l Lease) string { return strings.TrimPrefix(l.UserAgent, "UA-") }

// leaseAndReport leases (expecting success) then reports the given outcome.
func leaseAndReport(t *testing.T, p *Pool, ok bool, status int) {
	t.Helper()
	l := mustLease(t, p)
	if err := p.Report(l.LeaseID, ok, status); err != nil {
		t.Fatalf("Report: unexpected error %v", err)
	}
}

func TestLRUSelectionCyclesBeforeRepeat(t *testing.T) {
	ids := []string{"a", "b", "c", "d"}
	p, clk := newTestPool(t, ids...)

	seen := map[string]int{}
	for i := 0; i < len(ids); i++ {
		seen[leasedID(mustLease(t, p))]++
		clk.Advance(time.Second) // strict LastUsed ordering
	}
	for _, id := range ids {
		if seen[id] != 1 {
			t.Fatalf("entry %q leased %d times in first cycle, want 1 (full cycle before repeat)", id, seen[id])
		}
	}

	// The 5th lease must be the entry leased first — the oldest LastUsed.
	if got := leasedID(mustLease(t, p)); got != "a" {
		t.Fatalf("5th lease = %q, want %q (LRU repeats the oldest first)", got, "a")
	}
}

func TestFailureAttribution(t *testing.T) {
	cases := []struct {
		ok        bool
		status    int
		wantCount int
	}{
		{false, 0, 1},
		{false, 403, 1},
		{false, 407, 1},
		{false, 408, 1},
		{false, 429, 1},
		{false, 502, 1},
		{false, 503, 1},
		{false, 504, 1},
		{false, 404, 0},
		{false, 410, 0},
		{true, 200, 0},
		{true, 301, 0},
	}
	for _, c := range cases {
		name := fmt.Sprintf("ok=%v/status=%d", c.ok, c.status)
		t.Run(name, func(t *testing.T) {
			p, _ := newTestPool(t, "solo")
			leaseAndReport(t, p, c.ok, c.status)
			if got := p.entries[0].Failures; got != c.wantCount {
				t.Fatalf("Failures = %d, want %d", got, c.wantCount)
			}
			if isProxyFailure(c.ok, c.status) != (c.wantCount == 1) {
				t.Fatalf("isProxyFailure(%v,%d) disagrees with expected count", c.ok, c.status)
			}
		})
	}
}

func TestConsecutiveNotCumulative(t *testing.T) {
	p, _ := newTestPool(t, "solo")
	e := p.entries[0]

	leaseAndReport(t, p, false, 429)
	leaseAndReport(t, p, false, 503)
	if e.Failures != 2 {
		t.Fatalf("after two failures: Failures = %d, want 2", e.Failures)
	}
	if e.benched(p.now()) {
		t.Fatal("entry benched after two failures; threshold is three")
	}

	leaseAndReport(t, p, true, 200) // success resets
	if e.Failures != 0 || e.BenchCount != 0 {
		t.Fatalf("after success: Failures=%d BenchCount=%d, want 0/0", e.Failures, e.BenchCount)
	}

	leaseAndReport(t, p, false, 429)
	leaseAndReport(t, p, false, 429)
	if e.Failures != 2 || e.benched(p.now()) {
		t.Fatalf("counter did not restart from zero after the success: Failures=%d benched=%v", e.Failures, e.benched(p.now()))
	}
}

func TestThreeConsecutiveFailuresBench(t *testing.T) {
	p, clk := newTestPool(t, "solo")
	e := p.entries[0]

	for i := 0; i < testThreshold; i++ {
		leaseAndReport(t, p, false, 503)
	}
	if !e.benched(clk.Now()) {
		t.Fatal("entry not benched after three consecutive attributable failures")
	}
	if got := e.CooldownUntil.Sub(clk.Now()); got != testCooldownBase {
		t.Fatalf("first cooldown = %v, want %v", got, testCooldownBase)
	}

	// A benched entry is not leased.
	if _, err := p.Lease(); err == nil {
		t.Fatal("Lease succeeded while the only entry is benched")
	} else if _, ok := err.(*NoHealthyError); !ok {
		t.Fatalf("Lease error = %T, want *NoHealthyError", err)
	}
}

func TestCooldownDoublesAndCaps(t *testing.T) {
	p, clk := newTestPool(t, "solo")
	e := p.entries[0]

	want := []time.Duration{
		30 * time.Second,
		60 * time.Second,
		120 * time.Second,
		240 * time.Second,
		480 * time.Second,
		600 * time.Second, // 960 clamped
		600 * time.Second, // stays clamped
	}

	for i, w := range want {
		if i == 0 {
			// First bench needs the full threshold of failures.
			for j := 0; j < testThreshold; j++ {
				leaseAndReport(t, p, false, 503)
			}
		} else {
			// Cooldown has elapsed -> entry is on probation -> one failure re-benches.
			clk.Advance(want[i-1])
			leaseAndReport(t, p, false, 503)
		}
		if got := e.CooldownUntil.Sub(clk.Now()); got != w {
			t.Fatalf("bench %d: cooldown = %v, want %v", i+1, got, w)
		}
		if e.BenchCount != i+1 {
			t.Fatalf("bench %d: BenchCount = %d, want %d", i+1, e.BenchCount, i+1)
		}
	}
}

func TestProbationOneFailureRebenches(t *testing.T) {
	p, clk := newTestPool(t, "solo")
	e := p.entries[0]

	for i := 0; i < testThreshold; i++ {
		leaseAndReport(t, p, false, 503)
	}
	clk.Advance(testCooldownBase) // cooldown elapses

	l := mustLease(t, p) // promotes to probation
	if !e.Probation {
		t.Fatal("entry not on probation after cooldown elapsed and a lease")
	}
	if err := p.Report(l.LeaseID, false, 503); err != nil {
		t.Fatalf("Report: %v", err)
	}
	if !e.benched(clk.Now()) {
		t.Fatal("single attributable failure on probation did not re-bench")
	}
	if got := e.CooldownUntil.Sub(clk.Now()); got != 60*time.Second {
		t.Fatalf("re-bench cooldown = %v, want 60s (next backoff step)", got)
	}
}

func TestProbationSuccessClearsAndResets(t *testing.T) {
	p, clk := newTestPool(t, "solo")
	e := p.entries[0]

	for i := 0; i < testThreshold; i++ {
		leaseAndReport(t, p, false, 503)
	}
	clk.Advance(testCooldownBase)

	l := mustLease(t, p)
	if !e.Probation {
		t.Fatal("expected probation")
	}
	if err := p.Report(l.LeaseID, true, 200); err != nil {
		t.Fatalf("Report: %v", err)
	}
	if e.Probation || e.Failures != 0 || e.BenchCount != 0 {
		t.Fatalf("success on probation did not fully reset: Probation=%v Failures=%d BenchCount=%d",
			e.Probation, e.Failures, e.BenchCount)
	}
	if e.benched(clk.Now()) {
		t.Fatal("entry still benched after a successful trial")
	}
}

func TestEveryEntryBenchedNoLeaseWithRetryAfter(t *testing.T) {
	p, clk := newTestPool(t, "a", "b")
	// LRU spreads these evenly: testThreshold failures land on each of the two
	// entries, benching both.
	for i := 0; i < 2*testThreshold; i++ {
		leaseAndReport(t, p, false, 503)
	}

	_, err := p.Lease()
	nh, ok := err.(*NoHealthyError)
	if !ok {
		t.Fatalf("Lease error = %T, want *NoHealthyError", err)
	}
	if nh.RetryAfter != 30 {
		t.Fatalf("RetryAfter = %d, want 30 (soonest cooldown expiry)", nh.RetryAfter)
	}

	clk.Advance(10 * time.Second)
	_, err = p.Lease()
	nh, _ = err.(*NoHealthyError)
	if nh == nil || nh.RetryAfter != 20 {
		t.Fatalf("RetryAfter after 10s = %v, want 20", nh)
	}
}

func TestEmptyPoolLeaseReturns503RetryAfter1(t *testing.T) {
	// NewPool refuses an empty seed, so construct the degenerate pool directly
	// to exercise the guard.
	p := &Pool{
		byID:   map[string]*Entry{},
		leases: map[string]outstandingLease{},
		now:    func() time.Time { return time.Unix(0, 0) },
		nextID: func() string { return "x" },
	}
	_, err := p.Lease()
	nh, ok := err.(*NoHealthyError)
	if !ok {
		t.Fatalf("Lease error = %T, want *NoHealthyError", err)
	}
	if nh.RetryAfter != 1 {
		t.Fatalf("RetryAfter = %d, want 1", nh.RetryAfter)
	}
}

func TestReapedLeaseDoesNotCountAsFailure(t *testing.T) {
	p, clk := newTestPool(t, "solo")
	e := p.entries[0]

	l := mustLease(t, p)
	clk.Advance(testLeaseTTL) // lease is now reapable
	p.ReapNow()

	err := p.Report(l.LeaseID, false, 503)
	if err != ErrUnknownLease {
		t.Fatalf("Report on reaped lease: err = %v, want ErrUnknownLease", err)
	}
	if e.Failures != 0 {
		t.Fatalf("reaped lease affected failure count: Failures = %d, want 0", e.Failures)
	}
}

func TestDoubleReportSecondIsUnknown(t *testing.T) {
	p, _ := newTestPool(t, "solo")
	l := mustLease(t, p)

	if err := p.Report(l.LeaseID, true, 200); err != nil {
		t.Fatalf("first Report: %v", err)
	}
	if err := p.Report(l.LeaseID, true, 200); err != ErrUnknownLease {
		t.Fatalf("second Report: err = %v, want ErrUnknownLease", err)
	}
}

func TestConcurrentAccessIsRaceFree(t *testing.T) {
	ids := make([]string, 8)
	for i := range ids {
		ids[i] = fmt.Sprintf("e%d", i)
	}
	p, _ := newTestPool(t, ids...)
	p.now = time.Now // real clock; this test is about the lock, not cooldowns

	const workers, iters = 12, 200
	var wg sync.WaitGroup
	wg.Add(workers)
	for w := 0; w < workers; w++ {
		go func() {
			defer wg.Done()
			for i := 0; i < iters; i++ {
				l, err := p.Lease()
				if err != nil {
					continue
				}
				_ = p.Report(l.LeaseID, true, 200)
				_ = p.Snapshot()
			}
		}()
	}
	wg.Wait()

	m := p.Snapshot()
	if m.LeasesIssued != m.ReportsReceived {
		t.Fatalf("leases_issued=%d reports_received=%d; every lease was reported ok", m.LeasesIssued, m.ReportsReceived)
	}
	if m.LeasesIssued == 0 {
		t.Fatal("no leases issued")
	}
}
