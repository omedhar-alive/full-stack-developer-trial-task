package main

import (
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"strconv"
)

// api binds the HTTP handlers to a pool.
type api struct{ pool *Pool }

// newMux wires every route. main.go wraps the returned mux with logRequests.
func newMux(p *Pool) *http.ServeMux {
	a := &api{pool: p}
	mux := http.NewServeMux()
	mux.HandleFunc("GET /healthz", handleHealthz)
	mux.HandleFunc("GET /lease", a.handleLease)
	mux.HandleFunc("POST /report", a.handleReport)
	mux.HandleFunc("GET /metrics", a.handleMetrics)
	return mux
}

// handleHealthz is liveness only — no pool state, no dependencies. The body is
// exactly {"status":"ok"} and phase 1's exit check depends on that.
func handleHealthz(w http.ResponseWriter, _ *http.Request) {
	writeJSON(w, http.StatusOK, map[string]string{"status": "ok"})
}

type leaseResponse struct {
	LeaseID   string  `json:"lease_id"`
	ProxyURL  *string `json:"proxy_url"`
	UserAgent string  `json:"user_agent"`
}

func (a *api) handleLease(w http.ResponseWriter, _ *http.Request) {
	l, err := a.pool.Lease()
	if err != nil {
		var noHealthy *NoHealthyError
		if errors.As(err, &noHealthy) {
			w.Header().Set("Retry-After", strconv.Itoa(noHealthy.RetryAfter))
			writeJSON(w, http.StatusServiceUnavailable, map[string]any{
				"error":               "no_healthy_entries",
				"retry_after_seconds": noHealthy.RetryAfter,
			})
			return
		}
		writeJSON(w, http.StatusInternalServerError, map[string]string{"error": "internal"})
		return
	}
	writeJSON(w, http.StatusOK, leaseResponse{
		LeaseID:   l.LeaseID,
		ProxyURL:  l.ProxyURL,
		UserAgent: l.UserAgent,
	})
}

type reportRequest struct {
	LeaseID    string `json:"lease_id"`
	OK         *bool  `json:"ok"`
	StatusCode int    `json:"status_code"`
	LatencyMS  int    `json:"latency_ms"`
}

func (a *api) handleReport(w http.ResponseWriter, r *http.Request) {
	defer r.Body.Close()

	var req reportRequest
	// Cap the body; a report is a handful of fields. lease_id and ok are
	// required — their absence is a malformed body.
	if err := json.NewDecoder(io.LimitReader(r.Body, 1<<16)).Decode(&req); err != nil ||
		req.LeaseID == "" || req.OK == nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "malformed body"})
		return
	}

	switch err := a.pool.Report(req.LeaseID, *req.OK, req.StatusCode); {
	case errors.Is(err, ErrUnknownLease):
		writeJSON(w, http.StatusNotFound, map[string]string{"error": "unknown or already-reported lease"})
	case err != nil:
		writeJSON(w, http.StatusInternalServerError, map[string]string{"error": "internal"})
	default:
		w.WriteHeader(http.StatusNoContent) // 204, nothing to return
	}
}

func (a *api) handleMetrics(w http.ResponseWriter, _ *http.Request) {
	writeJSON(w, http.StatusOK, a.pool.Snapshot())
}
