#!/usr/bin/env bash
#
# scripts/stress_teardown.sh — Drop safarak_stress + delete stress.sqlite + artifacts.
#
# Usage:
#   bash scripts/stress_teardown.sh

set -e
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PROJECT_ROOT"

echo "→ Teardown…"
php tests/scripts/stress_teardown.php

echo "✅ Teardown complete."
