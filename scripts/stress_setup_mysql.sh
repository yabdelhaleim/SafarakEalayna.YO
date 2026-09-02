#!/usr/bin/env bash
#
# scripts/stress_setup_mysql.sh — Create safarak_stress schema + run migrations.
#
# Usage:
#   bash scripts/stress_setup_mysql.sh

set -e
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PROJECT_ROOT"

echo "→ Safety check…"
php tests/scripts/stress_safety_check.php mysql || { echo "🛑 Safety failed."; exit 2; }

echo "→ Setting up safarak_stress…"
php tests/scripts/stress_setup_mysql.php

echo "✅ MySQL stress schema ready."
