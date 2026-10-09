#!/usr/bin/env bash
#
# scripts/stress_run.sh — Phase 25 phase orchestrator.
#
# Usage:
#   bash scripts/stress_run.sh --phase=A --tier=sqlite
#   bash scripts/stress_run.sh --phase=B --tier=mysql --workers=25
#   bash scripts/stress_run.sh --phase=C --tier=mysql --workers=50
#
# This script wraps tests/scripts/stress_run_phase.php with a single
# safety-check pre-flight and pretty banner output. It does NOT bypass
# the safety guard inside the script — both layers run.

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PROJECT_ROOT"

PHASE="A"
TIER="sqlite"
WORKERS=""

for arg in "$@"; do
    case "$arg" in
        --phase=*)  PHASE="${arg#*=}" ;;
        --tier=*)   TIER="${arg#*=}" ;;
        --workers=*) WORKERS="${arg#*=}" ;;
    esac
done

if [ -n "$WORKERS" ]; then
    WORKERS_ARG="--workers=$WORKERS"
else
    WORKERS_ARG=""
fi

echo "═══════════════════════════════════════════════════════════"
echo "  Phase 25 — scripts/stress_run.sh"
echo "  Phase: $PHASE   Tier: $TIER   Workers: ${WORKERS:-default}"
echo "═══════════════════════════════════════════════════════════"

# 1. Pre-flight safety check
echo "→ Pre-flight safety check…"
php tests/scripts/stress_safety_check.php "$TIER" || {
    echo "🛑 Safety check failed — aborting."
    exit 2
}

# 2. Phase runner
echo "→ Running phase…"
php -d memory_limit=2G tests/scripts/stress_run_phase.php --phase="$PHASE" --tier="$TIER" $WORKERS_ARG

echo "✅ Done."
