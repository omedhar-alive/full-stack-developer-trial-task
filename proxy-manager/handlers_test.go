package main

import (
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

// newTestServer builds the real mux over a pool of the given ids and returns a
// running httptest server.
func newTestServer(t *testing.T, ids ...string) *httptest.Server {
	t.Helper()
	seeds := make([]seedEntry, len(ids))
	for i, id := range ids {
		seeds[i] = seedEntry{ID: id, UserAgent: "UA-" + id}
	}
	p, err := NewPool(seeds, PoolConfig{
		FailureThreshold: 3,
		CooldownBase:     30 * time.Second,
		CooldownMax:      600 * time.Second,
		LeaseTTL:         120 * time.Second,
	})
	if err != nil {
		t.Fatalf("NewPool: %v", err)
	}
	srv := httptest.NewServer(newMux(p))
	t.Cleanup(srv.Close)
	return srv
}

func TestHealthzExactBody(t *testing.T) {
	srv := newTestServer(t, "a")
	resp, err := http.Get(srv.URL + "/healthz")
	if err != nil {
		t.Fatal(err)
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		t.Fatalf("status = %d, want 200", resp.StatusCode)
	}
	if ct := resp.Header.Get("Content-Type"); ct != "application/json" {
		t.Fatalf("Content-Type = %q, want application/json", ct)
	}
	body, _ := io.ReadAll(resp.Body)
	if strings.TrimSpace(string(body)) != `{"status":"ok"}` {
		t.Fatalf("body = %q, want %q", body, `{"status":"ok"}`)
	}
}

func TestLeaseResponseShape(t *testing.T) {
	srv := newTestServer(t, "a", "b")

	get := func() map[string]json.RawMessage {
		resp, err := http.Get(srv.URL + "/lease")
		if err != nil {
			t.Fatal(err)
		}
		defer resp.Body.Close()
		if resp.StatusCode != http.StatusOK {
			t.Fatalf("status = %d, want 200", resp.StatusCode)
		}
		var m map[string]json.RawMessage
		if err := json.NewDecoder(resp.Body).Decode(&m); err != nil {
			t.Fatalf("decode: %v", err)
		}
		return m
	}

	first := get()
	for _, k := range []string{"lease_id", "proxy_url", "user_agent"} {
		if _, ok := first[k]; !ok {
			t.Fatalf("lease response missing key %q", k)
		}
	}
	if got := string(first["proxy_url"]); got != "null" {
		t.Fatalf("proxy_url = %s, want null (not empty string)", got)
	}
	var leaseID, ua string
	_ = json.Unmarshal(first["lease_id"], &leaseID)
	_ = json.Unmarshal(first["user_agent"], &ua)
	if leaseID == "" || ua == "" {
		t.Fatalf("empty lease_id (%q) or user_agent (%q)", leaseID, ua)
	}

	// Second lease must be a different entry (LRU, pool of two).
	second := get()
	var ua2 string
	_ = json.Unmarshal(second["user_agent"], &ua2)
	if ua2 == ua {
		t.Fatalf("two consecutive leases returned the same entry (%q)", ua)
	}
}

func TestReportStatusCodes(t *testing.T) {
	srv := newTestServer(t, "a")

	lease := func() string {
		resp, err := http.Get(srv.URL + "/lease")
		if err != nil {
			t.Fatal(err)
		}
		defer resp.Body.Close()
		var body struct {
			LeaseID string `json:"lease_id"`
		}
		_ = json.NewDecoder(resp.Body).Decode(&body)
		return body.LeaseID
	}
	report := func(payload string) int {
		resp, err := http.Post(srv.URL+"/report", "application/json", strings.NewReader(payload))
		if err != nil {
			t.Fatal(err)
		}
		defer resp.Body.Close()
		return resp.StatusCode
	}

	id := lease()

	if got := report(`{"lease_id":"` + id + `","ok":true,"status_code":200,"latency_ms":12}`); got != http.StatusNoContent {
		t.Fatalf("valid report: status = %d, want 204", got)
	}
	if got := report(`{"lease_id":"` + id + `","ok":true,"status_code":200,"latency_ms":12}`); got != http.StatusNotFound {
		t.Fatalf("second report on same lease: status = %d, want 404", got)
	}
	if got := report(`{"lease_id":"does-not-exist","ok":true,"status_code":200,"latency_ms":0}`); got != http.StatusNotFound {
		t.Fatalf("unknown lease: status = %d, want 404", got)
	}
	if got := report(`{ this is not json `); got != http.StatusBadRequest {
		t.Fatalf("malformed body: status = %d, want 400", got)
	}
}

func TestMetricsShapeAndCounters(t *testing.T) {
	srv := newTestServer(t, "a", "b", "c")

	type entryRow struct {
		ID            string  `json:"id"`
		Healthy       bool    `json:"healthy"`
		Failures      int     `json:"failures"`
		CooldownUntil *string `json:"cooldown_until"`
		LastUsed      *string `json:"last_used"`
	}
	type metrics struct {
		PoolSize        int        `json:"pool_size"`
		Healthy         int        `json:"healthy"`
		Benched         int        `json:"benched"`
		LeasesIssued    int64      `json:"leases_issued"`
		ReportsReceived int64      `json:"reports_received"`
		Entries         []entryRow `json:"entries"`
	}
	fetch := func() metrics {
		resp, err := http.Get(srv.URL + "/metrics")
		if err != nil {
			t.Fatal(err)
		}
		defer resp.Body.Close()
		if resp.StatusCode != http.StatusOK {
			t.Fatalf("status = %d, want 200", resp.StatusCode)
		}
		var m metrics
		if err := json.NewDecoder(resp.Body).Decode(&m); err != nil {
			t.Fatalf("decode: %v", err)
		}
		return m
	}

	before := fetch()
	if before.PoolSize != 3 || before.Healthy != 3 || before.Benched != 0 {
		t.Fatalf("initial pool: size=%d healthy=%d benched=%d, want 3/3/0", before.PoolSize, before.Healthy, before.Benched)
	}
	if before.LeasesIssued != 0 || before.ReportsReceived != 0 {
		t.Fatalf("initial counters: leases=%d reports=%d, want 0/0", before.LeasesIssued, before.ReportsReceived)
	}
	if len(before.Entries) != 3 {
		t.Fatalf("entries length = %d, want 3", len(before.Entries))
	}
	for _, e := range before.Entries {
		if e.ID == "" || !e.Healthy || e.CooldownUntil != nil || e.LastUsed != nil {
			t.Fatalf("unexpected initial entry row: %+v", e)
		}
	}

	// One lease + one report must move both counters.
	resp, err := http.Get(srv.URL + "/lease")
	if err != nil {
		t.Fatal(err)
	}
	var lease struct {
		LeaseID string `json:"lease_id"`
	}
	_ = json.NewDecoder(resp.Body).Decode(&lease)
	resp.Body.Close()

	rr, err := http.Post(srv.URL+"/report", "application/json",
		strings.NewReader(`{"lease_id":"`+lease.LeaseID+`","ok":true,"status_code":200,"latency_ms":30}`))
	if err != nil {
		t.Fatal(err)
	}
	rr.Body.Close()

	after := fetch()
	if after.LeasesIssued != 1 || after.ReportsReceived != 1 {
		t.Fatalf("after lease+report: leases=%d reports=%d, want 1/1", after.LeasesIssued, after.ReportsReceived)
	}
	var used int
	for _, e := range after.Entries {
		if e.LastUsed != nil {
			used++
		}
	}
	if used != 1 {
		t.Fatalf("entries with non-null last_used = %d, want 1", used)
	}
}
