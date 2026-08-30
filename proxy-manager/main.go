// Command proxy-manager is the Go service that owns proxy/user-agent leasing and
// health scoring. main.go is wiring only: config, pool load, routes, lifecycle.
// The pool and its state machine live in pool.go; the HTTP layer in handlers.go.
package main

import (
	"context"
	"encoding/json"
	"errors"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"strconv"
	"strings"
	"syscall"
	"time"
)

// reapInterval is how often the background reaper runs. Lease and Report also
// reap lazily, so this ticker only matters when the service is receiving no
// traffic at all. It is a hardcoded constant, not an env var, on purpose: it
// is an internal safety net for that zero-traffic case, not a tuning knob. The
// value that actually governs behaviour — how long an unreported lease is held
// before it is considered abandoned — is LEASE_TTL_SECONDS, and that is
// already configurable; how often we sweep for expired ones is not worth an
// operational surface.
const reapInterval = 30 * time.Second

func main() {
	logger := slog.New(slog.NewJSONHandler(os.Stdout, &slog.HandlerOptions{
		Level: parseLevel(getenv("LOG_LEVEL", "info")),
	}))
	slog.SetDefault(logger)

	poolFile := poolFileFromEnv()
	seeds, err := loadSeedFile(poolFile)
	if err != nil {
		slog.Error("cannot load proxy pool", "error", err)
		os.Exit(1)
	}
	pool, err := NewPool(seeds, poolConfigFromEnv())
	if err != nil {
		slog.Error("invalid proxy pool", "error", err, "file", poolFile)
		os.Exit(1)
	}
	slog.Info("proxy pool loaded", "file", poolFile, "entries", len(seeds))

	port := getenv("PORT", "8080")
	srv := &http.Server{
		Addr:              ":" + port,
		Handler:           logRequests(newMux(pool)),
		ReadHeaderTimeout: 5 * time.Second,
		ReadTimeout:       15 * time.Second,
		WriteTimeout:      15 * time.Second,
		IdleTimeout:       60 * time.Second,
	}

	// Serve until SIGINT/SIGTERM, then drain in-flight requests before exiting.
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	go reapLoop(ctx, pool)

	go func() {
		slog.Info("proxy-manager listening", "addr", srv.Addr)
		if err := srv.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
			slog.Error("server failed", "error", err)
			stop()
		}
	}()

	<-ctx.Done()
	slog.Info("shutdown signal received")

	shutdownCtx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	if err := srv.Shutdown(shutdownCtx); err != nil {
		slog.Error("graceful shutdown failed", "error", err)
		os.Exit(1)
	}
	slog.Info("stopped")
}

// poolConfigFromEnv reads the circuit-breaker tunables from the environment,
// each falling back to the default documented in CONTRACTS.md §2. Split out of
// main() so a test can exercise those defaults: docker-compose.yml now sets all
// four explicitly, so a typo in one of these literals would otherwise never be
// hit anywhere.
func poolConfigFromEnv() PoolConfig {
	return PoolConfig{
		FailureThreshold: getenvInt("FAILURE_THRESHOLD", 3),
		CooldownBase:     time.Duration(getenvInt("COOLDOWN_BASE_SECONDS", 30)) * time.Second,
		CooldownMax:      time.Duration(getenvInt("COOLDOWN_MAX_SECONDS", 600)) * time.Second,
		LeaseTTL:         time.Duration(getenvInt("LEASE_TTL_SECONDS", 120)) * time.Second,
	}
}

// poolFileFromEnv is separate from poolConfigFromEnv because the seed file path
// is a main-package concern, not pool state — PoolConfig has no business
// carrying a filesystem path. Split out for the same reason: compose now sets
// PROXY_POOL_FILE explicitly, so a test is the only place the default is hit.
func poolFileFromEnv() string {
	return getenv("PROXY_POOL_FILE", "/app/proxies.json")
}

// reapLoop clears abandoned leases while the service is idle.
func reapLoop(ctx context.Context, pool *Pool) {
	t := time.NewTicker(reapInterval)
	defer t.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-t.C:
			pool.ReapNow()
		}
	}
}

func writeJSON(w http.ResponseWriter, status int, body any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(body)
}

func logRequests(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		start := time.Now()
		next.ServeHTTP(w, r)
		slog.Debug("request",
			"method", r.Method,
			"path", r.URL.Path,
			"duration_ms", time.Since(start).Milliseconds(),
		)
	})
}

func getenv(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}

func getenvInt(key string, fallback int) int {
	v := os.Getenv(key)
	if v == "" {
		return fallback
	}
	n, err := strconv.Atoi(v)
	if err != nil {
		slog.Warn("invalid integer env var; using default", "key", key, "value", v, "default", fallback)
		return fallback
	}
	return n
}

func parseLevel(s string) slog.Level {
	switch strings.ToLower(s) {
	case "debug":
		return slog.LevelDebug
	case "warn":
		return slog.LevelWarn
	case "error":
		return slog.LevelError
	default:
		return slog.LevelInfo
	}
}
