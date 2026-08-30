package main

import (
	"testing"
	"time"
)

// The defaults poolConfigFromEnv falls back to are the values CONTRACTS.md §2
// documents and docker-compose.yml now sets explicitly. Because compose always
// overrides them in the running stack, a literal here drifting from the
// contract would not surface anywhere else — this test is the only thing that
// exercises the bare defaults.
func TestPoolConfigFromEnvDefaults(t *testing.T) {
	// Empty is treated as unset by getenvInt; set rather than unset so an
	// ambient value in the shell or CI cannot leak in.
	t.Setenv("FAILURE_THRESHOLD", "")
	t.Setenv("COOLDOWN_BASE_SECONDS", "")
	t.Setenv("COOLDOWN_MAX_SECONDS", "")
	t.Setenv("LEASE_TTL_SECONDS", "")

	cfg := poolConfigFromEnv()

	if cfg.FailureThreshold != 3 {
		t.Errorf("FailureThreshold = %d, want 3", cfg.FailureThreshold)
	}
	if cfg.CooldownBase != 30*time.Second {
		t.Errorf("CooldownBase = %s, want 30s", cfg.CooldownBase)
	}
	if cfg.CooldownMax != 600*time.Second {
		t.Errorf("CooldownMax = %s, want 600s", cfg.CooldownMax)
	}
	if cfg.LeaseTTL != 120*time.Second {
		t.Errorf("LeaseTTL = %s, want 120s", cfg.LeaseTTL)
	}
}

// PROXY_POOL_FILE's default is read by poolFileFromEnv, not poolConfigFromEnv,
// so the test above does not reach it. Compose sets it explicitly, leaving this
// as the only exercise of the /app/proxies.json literal.
func TestPoolFileFromEnvDefault(t *testing.T) {
	// Set empty rather than unset so an ambient value cannot leak in.
	t.Setenv("PROXY_POOL_FILE", "")

	if got := poolFileFromEnv(); got != "/app/proxies.json" {
		t.Errorf("poolFileFromEnv() = %q, want /app/proxies.json", got)
	}
}

// A non-integer value must fall back to the field's default, not panic:
// getenvInt logs a warning and returns the fallback.
func TestPoolConfigFromEnvNonIntegerFallsBack(t *testing.T) {
	t.Setenv("FAILURE_THRESHOLD", "not-a-number")
	t.Setenv("COOLDOWN_BASE_SECONDS", "")
	t.Setenv("COOLDOWN_MAX_SECONDS", "")
	t.Setenv("LEASE_TTL_SECONDS", "")

	cfg := poolConfigFromEnv()

	if cfg.FailureThreshold != 3 {
		t.Errorf("FailureThreshold = %d, want the default 3 after an unparseable value", cfg.FailureThreshold)
	}
}
