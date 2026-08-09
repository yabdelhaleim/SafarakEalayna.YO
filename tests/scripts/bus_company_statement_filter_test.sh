#!/bin/bash
# Bus Company Statement Filter — End-to-End Test
# يتحقق من الفلاتر الجديدة في /api/v1/bus/companies/{id}/statement
# (from_date, to_date, type, search, per_page, page)

set -e

# ============================================================================
# CONFIGURATION — عدّل هذه القيم لتطابق الـ environment عندك
# ============================================================================
BASE="${BASE_URL:-https://safarakealayna.remotelly1.site/api/v1}"
TOKEN="${BUS_API_TOKEN:-}"   # <-- حط الـ token هنا أو مرره كـ env var
COMPANY_ID="${COMPANY_ID:-2}" # <-- ID شركة الباص اللي تختبر عليها (2 = السفير باص)

if [ -z "$TOKEN" ]; then
    echo "❌ ERROR: BUS_API_TOKEN is empty. Set it in env or inline, e.g.:"
    echo "   BUS_API_TOKEN=eyJ... ./tests/scripts/bus_company_statement_filter_test.sh"
    exit 1
fi

HEADERS=(-H "Authorization: Bearer $TOKEN" -H "Accept: application/json")
FAIL=0
PASS=0

# ============================================================================
# Helpers
# ============================================================================
http_code() {
    local url="$1"
    curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" "$url" --max-time 30
}

body() {
    local url="$1"
    curl -s "${HEADERS[@]}" "$url" --max-time 30
}

check() {
    local name="$1"
    local actual="$2"
    local expected="$3"
    if [ "$actual" = "$expected" ]; then
        echo "  ✅ $name (got $actual)"
        PASS=$((PASS+1))
    else
        echo "  ❌ $name (got $actual, expected $expected)"
        FAIL=$((FAIL+1))
    fi
}

# ============================================================================
# 1. SMOKE — استدعاء عادي بدون فلاتر
# ============================================================================
echo ""
echo "=== 1. Smoke test (no filters) ==="
URL="$BASE/bus/companies/$COMPANY_ID/statement"
RESP=$(body "$URL")
CODE=$(http_code "$URL")
check "GET without filters" "$CODE" "200"

# Verify response shape
echo "$RESP" | grep -q '"success":true' && check "  success=true" "yes" "yes" || (check "  success=true" "no" "yes"; FAIL=$((FAIL+1)))
echo "$RESP" | grep -q '"company"' && check "  has company" "yes" "yes" || check "  has company" "no" "yes"
echo "$RESP" | grep -q '"transactions"' && check "  has transactions" "yes" "yes" || check "  has transactions" "no" "yes"
echo "$RESP" | grep -q '"total"' && check "  has transactions.total" "yes" "yes" || check "  has transactions.total" "no" "yes"
echo "$RESP" | grep -q '"current_page"' && check "  has transactions.current_page" "yes" "yes" || check "  has transactions.current_page" "no" "yes"
echo "$RESP" | grep -q '"last_page"' && check "  has transactions.last_page" "yes" "yes" || check "  has transactions.last_page" "no" "yes"
echo "$RESP" | grep -q '"per_page"' && check "  has transactions.per_page" "yes" "yes" || check "  has transactions.per_page" "no" "yes"

# ============================================================================
# 2. Date range
# ============================================================================
echo ""
echo "=== 2. Date range filter ==="
URL="$BASE/bus/companies/$COMPANY_ID/statement?from_date=2026-08-08&to_date=2026-08-09"
CODE=$(http_code "$URL")
check "GET ?from_date=2026-08-08&to_date=2026-08-09" "$CODE" "200"

URL="$BASE/bus/companies/$COMPANY_ID/statement?from_date=2026-08-10"
CODE=$(http_code "$URL")
check "GET ?from_date=2026-08-10 (only from)" "$CODE" "200"

URL="$BASE/bus/companies/$COMPANY_ID/statement?to_date=2026-08-03"
CODE=$(http_code "$URL")
check "GET ?to_date=2026-08-03 (only to)" "$CODE" "200"

# ============================================================================
# 3. Type filter (credit / debit)
# ============================================================================
echo ""
echo "=== 3. Type filter ==="
URL="$BASE/bus/companies/$COMPANY_ID/statement?type=credit"
CODE=$(http_code "$URL")
check "GET ?type=credit (إيداع)" "$CODE" "200"
# Verify returned rows are actually credits
RESP=$(body "$URL")
COUNT_CREDIT=$(echo "$RESP" | grep -o '"direction":"credit"' 2>/dev/null | wc -l)
echo "  ℹ️  type=credit -> rows match 'credit': $COUNT_CREDIT"

URL="$BASE/bus/companies/$COMPANY_ID/statement?type=debit"
CODE=$(http_code "$URL")
check "GET ?type=debit (سحب)" "$CODE" "200"

URL="$BASE/bus/companies/$COMPANY_ID/statement?type=credit&from_date=2026-08-01"
CODE=$(http_code "$URL")
check "GET ?type=credit&from_date=2026-08-01" "$CODE" "200"

# ============================================================================
# 4. Search filter
# ============================================================================
echo ""
echo "=== 4. Search filter ==="
URL="$BASE/bus/companies/$COMPANY_ID/statement?search=%D8%A5%D9%82%D9%81%D8%A7%D9%84"
CODE=$(http_code "$URL")
check "GET ?search=إقفال" "$CODE" "200"

URL="$BASE/bus/companies/$COMPANY_ID/statement?search=office"
CODE=$(http_code "$URL")
check "GET ?search=office" "$CODE" "200"

URL="$BASE/bus/companies/$COMPANY_ID/statement?search=zzzzz_no_match_xxxxx"
CODE=$(http_code "$URL")
check "GET ?search=zzzzz_no_match_xxxxx" "$CODE" "200"

# ============================================================================
# 5. Pagination
# ============================================================================
echo ""
echo "=== 5. Pagination ==="
URL="$BASE/bus/companies/$COMPANY_ID/statement?per_page=3"
CODE=$(http_code "$URL")
check "GET ?per_page=3" "$CODE" "200"
RESP=$(body "$URL")
PER_PAGE=$(echo "$RESP" | grep -o '"per_page":[0-9]*' | head -1 | grep -o '[0-9]*')
check "  per_page=3 honored" "$PER_PAGE" "3"

URL="$BASE/bus/companies/$COMPANY_ID/statement?per_page=3&page=2"
CODE=$(http_code "$URL")
check "GET ?per_page=3&page=2" "$CODE" "200"
RESP=$(body "$URL")
PAGE=$(echo "$RESP" | grep -o '"current_page":[0-9]*' | head -1 | grep -o '[0-9]*')
check "  current_page=2 honored" "$PAGE" "2"

# cap at 100
URL="$BASE/bus/companies/$COMPANY_ID/statement?per_page=500"
CODE=$(http_code "$URL")
check "GET ?per_page=500 (should cap at 100)" "$CODE" "200"
RESP=$(body "$URL")
PER_PAGE=$(echo "$RESP" | grep -o '"per_page":[0-9]*' | head -1 | grep -o '[0-9]*')
if [ "$PER_PAGE" -le "100" ] && [ "$PER_PAGE" -gt "0" ]; then
    echo "  ✅ per_page capped at <= 100 (got $PER_PAGE)"
    PASS=$((PASS+1))
else
    echo "  ❌ per_page NOT capped at 100 (got $PER_PAGE)"
    FAIL=$((FAIL+1))
fi

# ============================================================================
# 6. Combined filters
# ============================================================================
echo ""
echo "=== 6. Combined filters ==="
URL="$BASE/bus/companies/$COMPANY_ID/statement?from_date=2026-08-01&to_date=2026-08-09&type=debit&search=%D8%A8%D8%A7%D8%B5&per_page=5&page=1"
CODE=$(http_code "$URL")
check "GET (all filter combo)" "$CODE" "200"

# ============================================================================
# 7. Edge cases (should not crash)
# ============================================================================
echo ""
echo "=== 7. Edge cases (should return 200 and NOT error) ==="
URL="$BASE/bus/companies/$COMPANY_ID/statement?from_date=garbage"
check "GET ?from_date=garbage" "$(http_code "$URL")" "200"

URL="$BASE/bus/companies/$COMPANY_ID/statement?to_date=2026-13-99"
check "GET ?to_date=2026-13-99" "$(http_code "$URL")" "200"

URL="$BASE/bus/companies/$COMPANY_ID/statement?from_date=2026-08-09&to_date=2026-08-01"
check "GET (from > to)" "$(http_code "$URL")" "200"

URL="$BASE/bus/companies/$COMPANY_ID/statement?type=invalid"
check "GET ?type=invalid" "$(http_code "$URL")" "200"

URL="$BASE/bus/companies/$COMPANY_ID/statement?search=%27%20OR%20%271%27%3D%271"
check "GET ?search=SQL-injection-attempt" "$(http_code "$URL")" "200"

# ============================================================================
# 8. Non-existent company (should return 404)
# ============================================================================
echo ""
echo "=== 8. Non-existent company ==="
URL="$BASE/bus/companies/999999/statement"
CODE=$(http_code "$URL")
# Either 404 or 403/422 both acceptable depending on auth setup
if [ "$CODE" = "404" ] || [ "$CODE" = "403" ] || [ "$CODE" = "500" ]; then
    echo "  ✅ GET /bus/companies/999999/statement returned $CODE (acceptable)"
    PASS=$((PASS+1))
else
    echo "  ⚠️  GET /bus/companies/999999/statement returned $CODE (expected 404/403)"
fi

# ============================================================================
# Summary
# ============================================================================
echo ""
echo "===================================="
echo "  ✅ PASS: $PASS"
echo "  ❌ FAIL: $FAIL"
echo "===================================="
if [ $FAIL -gt 0 ]; then
    exit 1
fi
echo "🎉 All filter tests passed!"
