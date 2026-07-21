#!/bin/bash
# Bus API end-to-end smoke test
# Tests the most important bus API endpoints

set -e
TOKEN="${BUS_API_TOKEN:-}"
BASE="http://127.0.0.1:8000/api/v1"
HEADERS=(-H "Authorization: Bearer $TOKEN" -H "Accept: application/json" -H "Content-Type: application/json")

PASS=0
FAIL=0
FAILED_TESTS=()

# Use python for proper JSON ID extraction
extract_id() {
    echo "$1" | python -c "import sys, json; d=json.load(sys.stdin); data=d.get('data'); print(data.get('id') if isinstance(data,dict) else data)" 2>/dev/null
}

check() {
    local name="$1"
    local code="$2"
    local expected="$3"
    if [ "$code" = "$expected" ]; then
        echo "✅ $name (HTTP $code)"
        PASS=$((PASS+1))
    else
        echo "❌ $name (HTTP $code, expected $expected)"
        FAIL=$((FAIL+1))
        FAILED_TESTS+=("$name")
    fi
}

# Get IDs from existing seeded data
CUST_ID=$(php -r "require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo App\\Models\\Customer::orderBy('id')->value('id');" 2>/dev/null)
echo "Using customer_id=$CUST_ID"
echo ""

echo "=== Auth-required GET endpoints ==="
check "GET /api/v1/bus/dashboard" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" $BASE/bus/dashboard)" "200"
check "GET /api/v1/bus/companies" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" $BASE/bus/companies)" "200"
check "GET /api/v1/bus/bookings" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" $BASE/bus/bookings)" "200"
check "GET /api/v1/bus/bookings/stats" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" $BASE/bus/bookings/stats)" "200"
check "GET /api/v1/bus/inventories" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" $BASE/bus/inventories)" "200"
check "GET /api/v1/bus/inventories/2" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" $BASE/bus/inventories/2)" "200"
check "GET /api/v1/bus/bookings/15" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" $BASE/bus/bookings/15)" "200"
check "GET /api/v1/bus/companies/2" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" $BASE/bus/companies/2)" "200"
check "GET /api/v1/bus/customers" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" $BASE/bus/customers)" "200"
check "GET /api/v1/bus/companies/2/statement" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" $BASE/bus/companies/2/statement)" "200"

echo ""
echo "=== Error handling ==="
check "GET /api/v1/bus/bookings/99999 (not found)" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" $BASE/bus/bookings/99999)" "404"
check "GET /api/v1/bus/companies/99999 (not found)" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" $BASE/bus/companies/99999)" "404"

echo ""
echo "=== POST endpoints (create flows) ==="

# Create company
RESULT=$(curl -s -X POST "${HEADERS[@]}" $BASE/bus/companies \
    -d '{"name":"شركة اختبار API V3","phone":"0234567800","address":"اختبار","is_active":true}')
COMPANY_ID=$(extract_id "$RESULT")
if [ -n "$COMPANY_ID" ] && [ "$COMPANY_ID" != "None" ]; then
    echo "✅ POST /api/v1/bus/companies (created id=$COMPANY_ID)"
    PASS=$((PASS+1))
else
    echo "❌ POST /api/v1/bus/companies — response: $RESULT" | head -c 300
    echo ""
    FAIL=$((FAIL+1))
fi

# Create inventory using Mode B
if [ -n "$COMPANY_ID" ] && [ "$COMPANY_ID" != "None" ]; then
    RESULT=$(curl -s -X POST "${HEADERS[@]}" $BASE/bus/inventories \
        -d "{\"company_id\":$COMPANY_ID,\"route\":\"API V3 - الإسكندرية\",\"travel_date\":\"2026-09-01\",\"departure_time\":\"10:00\",\"total_tickets\":40,\"cost_per_ticket\":100,\"selling_price\":150,\"payment_type\":\"deferred\"}")
    INV_ID=$(extract_id "$RESULT")
    if [ -n "$INV_ID" ] && [ "$INV_ID" != "None" ]; then
        echo "✅ POST /api/v1/bus/inventories (created id=$INV_ID)"
        PASS=$((PASS+1))
    else
        echo "❌ POST /api/v1/bus/inventories — response: $RESULT" | head -c 300
        echo ""
        FAIL=$((FAIL+1))
    fi
fi

# Create booking using Mode A (existing inventory)
if [ -n "$INV_ID" ] && [ "$INV_ID" != "None" ]; then
    RESULT=$(curl -s -X POST "${HEADERS[@]}" $BASE/bus/bookings \
        -d "{\"inventory_id\":$INV_ID,\"customer_id\":$CUST_ID,\"quantity\":2,\"notes\":\"اختبار API V3\"}")
    BOOK_ID=$(extract_id "$RESULT")
    if [ -n "$BOOK_ID" ] && [ "$BOOK_ID" != "None" ]; then
        echo "✅ POST /api/v1/bus/bookings (created id=$BOOK_ID)"
        PASS=$((PASS+1))
    else
        echo "❌ POST /api/v1/bus/bookings — response: $RESULT" | head -c 300
        echo ""
        FAIL=$((FAIL+1))
    fi
fi

# Pay booking
if [ -n "$BOOK_ID" ] && [ "$BOOK_ID" != "None" ]; then
    RESULT=$(curl -s -X POST "${HEADERS[@]}" $BASE/bus/bookings/$BOOK_ID/pay \
        -d '{"amount":300,"payment_method":"cash","account_id":1}')
    if echo "$RESULT" | grep -q '"success":true'; then
        echo "✅ POST /api/v1/bus/bookings/$BOOK_ID/pay"
        PASS=$((PASS+1))
    else
        echo "❌ POST pay — response: $RESULT" | head -c 200
        echo ""
        FAIL=$((FAIL+1))
    fi

    # Cancel booking
    RESULT=$(curl -s -X POST "${HEADERS[@]}" $BASE/bus/bookings/$BOOK_ID/cancel \
        -d '{"company_penalty":50,"office_penalty":20,"account_id":1,"notes":"إلغاء API V3"}')
    if echo "$RESULT" | grep -q '"success":true'; then
        echo "✅ POST /api/v1/bus/bookings/$BOOK_ID/cancel"
        PASS=$((PASS+1))
    else
        echo "❌ POST cancel — response: $RESULT" | head -c 200
        echo ""
        FAIL=$((FAIL+1))
    fi
fi

echo ""
echo "=== PUT/DELETE endpoints ==="
# Update company
if [ -n "$COMPANY_ID" ] && [ "$COMPANY_ID" != "None" ]; then
    RESULT=$(curl -s -X PUT "${HEADERS[@]}" $BASE/bus/companies/$COMPANY_ID \
        -d '{"name":"شركة اختبار API V3 (معدلة)","is_active":true}')
    if echo "$RESULT" | grep -q '"success":true'; then
        echo "✅ PUT /api/v1/bus/companies/$COMPANY_ID"
        PASS=$((PASS+1))
    else
        echo "❌ PUT company — response: $RESULT" | head -c 200
        echo ""
        FAIL=$((FAIL+1))
    fi
fi

# Update inventory
if [ -n "$INV_ID" ] && [ "$INV_ID" != "None" ]; then
    RESULT=$(curl -s -X PUT "${HEADERS[@]}" $BASE/bus/inventories/$INV_ID \
        -d '{"selling_price":175}')
    if echo "$RESULT" | grep -q '"success":true'; then
        echo "✅ PUT /api/v1/bus/inventories/$INV_ID"
        PASS=$((PASS+1))
    else
        echo "❌ PUT inventory — response: $RESULT" | head -c 200
        echo ""
        FAIL=$((FAIL+1))
    fi
fi

echo ""
echo "=== Pagination & filtering ==="
check "GET /api/v1/bus/bookings?per_page=5" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" "$BASE/bus/bookings?per_page=5")" "200"
check "GET /api/v1/bus/bookings?status=paid" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" "$BASE/bus/bookings?status=paid")" "200"
check "GET /api/v1/bus/bookings?search=محمد" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" "$BASE/bus/bookings?search=%D9%85%D8%AD%D9%85%D8%AF")" "200"
check "GET /api/v1/bus/inventories?per_page=3" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" "$BASE/bus/inventories?per_page=3")" "200"

echo ""
echo "=== Final summary ==="
echo "✅ PASS: $PASS"
echo "❌ FAIL: $FAIL"
if [ $FAIL -gt 0 ]; then
    echo "Failed tests: ${FAILED_TESTS[@]}"
    exit 1
fi
echo "🎉 All bus API tests passed!"
