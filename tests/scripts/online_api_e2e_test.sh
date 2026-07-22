#!/bin/bash
# Online Services API end-to-end smoke test

set -e
TOKEN="${ONLINE_API_TOKEN:-}"
BASE="http://127.0.0.1:8000/api/v1"
HEADERS=(-H "Authorization: Bearer $TOKEN" -H "Accept: application/json" -H "Content-Type: application/json")

PASS=0
FAIL=0
FAILED_TESTS=()

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

CUST_ID=$(php -r "require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo App\\Models\\Customer::where('phone', '01620020001')->value('id');" 2>/dev/null)
echo "Using customer_id=$CUST_ID"
echo ""

echo "=== Auth-required GET endpoints ==="
check "GET /api/v1/online/transactions" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" $BASE/online/transactions)" "200"
check "GET /api/v1/online/transactions/1" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" $BASE/online/transactions/1)" "200"
check "GET /api/v1/online/customer-balances" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" $BASE/online/customer-balances)" "200"
check "GET /api/v1/online/customer-statement?customer_id=$CUST_ID" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" "$BASE/online/customer-statement?customer_id=$CUST_ID")" "200"
check "GET /api/v1/online/providers" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" $BASE/online/providers)" "200"
check "GET /api/v1/online/providers/active" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" $BASE/online/providers/active)" "200"
check "GET /api/v1/online/providers/1" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" $BASE/online/providers/1)" "200"
check "GET /api/v1/online/service-types" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" $BASE/online/service-types)" "200"
check "GET /api/v1/online/service-types/active" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" $BASE/online/service-types/active)" "200"
check "GET /api/v1/online/service-types/1" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" $BASE/online/service-types/1)" "200"
check "GET /api/v1/online/settings/accounts" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" $BASE/online/settings/accounts)" "200"
check "GET /api/v1/online/settings/employees" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" $BASE/online/settings/employees)" "200"

echo ""
echo "=== Error handling ==="
check "GET /api/v1/online/transactions/99999 (not found)" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" $BASE/online/transactions/99999)" "404"
check "GET /api/v1/online/providers/99999 (not found)" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" $BASE/online/providers/99999)" "404"
check "GET /api/v1/online/service-types/99999 (not found)" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" $BASE/online/service-types/99999)" "404"

echo ""
echo "=== POST/PUT/DELETE endpoints ==="
SERVICE_TYPE_ID=1  # stamps
PROVIDER_ID=1      # momtaz
ACCOUNT_ID=$(php -r "require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo App\\Models\\Account::where('name', 'خزينة الخدمات الإلكترونية النقدية')->value('id');" 2>/dev/null)
echo "Using service_type=$SERVICE_TYPE_ID, provider=$PROVIDER_ID, account=$ACCOUNT_ID"

# Create transaction (completed with registered customer)
RESULT=$(curl -s -X POST "${HEADERS[@]}" $BASE/online/transactions \
    -d "{\"service_type_id\":$SERVICE_TYPE_ID,\"provider_id\":$PROVIDER_ID,\"customer_id\":$CUST_ID,\"purchase_price\":180,\"selling_price\":220,\"amount_paid\":220,\"payment_method\":\"cash\",\"account_id\":$ACCOUNT_ID,\"reference_number\":\"ONLINE-API-001\",\"notes\":\"API test\"}")
TX_ID=$(extract_id "$RESULT")
if [ -n "$TX_ID" ] && [ "$TX_ID" != "None" ]; then
    echo "✅ POST /api/v1/online/transactions (created id=$TX_ID)"
    PASS=$((PASS+1))
else
    echo "❌ POST — $RESULT" | head -c 300
    echo ""
    FAIL=$((FAIL+1))
fi

# Update transaction
if [ -n "$TX_ID" ] && [ "$TX_ID" != "None" ]; then
    RESULT=$(curl -s -X PUT "${HEADERS[@]}" $BASE/online/transactions/$TX_ID \
        -d '{"notes":"updated via API","selling_price":250}')
    if echo "$RESULT" | grep -q '"success":true'; then
        echo "✅ PUT /api/v1/online/transactions/$TX_ID"
        PASS=$((PASS+1))
    else
        echo "❌ PUT — $RESULT" | head -c 300
        echo ""
        FAIL=$((FAIL+1))
    fi
fi

# Cancel transaction
if [ -n "$TX_ID" ] && [ "$TX_ID" != "None" ]; then
    RESULT=$(curl -s -X DELETE "${HEADERS[@]}" $BASE/online/transactions/$TX_ID)
    if echo "$RESULT" | grep -q '"success":true'; then
        echo "✅ DELETE /api/v1/online/transactions/$TX_ID"
        PASS=$((PASS+1))
    else
        echo "❌ DELETE — $RESULT" | head -c 300
        echo ""
        FAIL=$((FAIL+1))
    fi
fi

# Create service type
RESULT=$(curl -s -X POST "${HEADERS[@]}" $BASE/online/service-types \
    -d '{"code":"api_st","name_ar":"نوع API","name_en":"API Type","color":"#ABCDEF","is_active":1,"order":99}')
ST_ID=$(extract_id "$RESULT")
if [ -n "$ST_ID" ] && [ "$ST_ID" != "None" ]; then
    echo "✅ POST /api/v1/online/service-types (created id=$ST_ID)"
    PASS=$((PASS+1))
    # Delete it
    RESULT=$(curl -s -X DELETE "${HEADERS[@]}" $BASE/online/service-types/$ST_ID)
    if echo "$RESULT" | grep -q '"success":true'; then
        echo "✅ DELETE /api/v1/online/service-types/$ST_ID"
        PASS=$((PASS+1))
    fi
fi

# Create provider
RESULT=$(curl -s -X POST "${HEADERS[@]}" $BASE/online/providers \
    -d '{"code":"api_prov","name_ar":"مزود API","name_en":"API Provider","color":"#FEDCBA","is_active":1,"order":99}')
PR_ID=$(extract_id "$RESULT")
if [ -n "$PR_ID" ] && [ "$PR_ID" != "None" ]; then
    echo "✅ POST /api/v1/online/providers (created id=$PR_ID)"
    PASS=$((PASS+1))
    RESULT=$(curl -s -X DELETE "${HEADERS[@]}" $BASE/online/providers/$PR_ID)
    if echo "$RESULT" | grep -q '"success":true'; then
        echo "✅ DELETE /api/v1/online/providers/$PR_ID"
        PASS=$((PASS+1))
    fi
fi

echo ""
echo "=== Pagination & filtering ==="
check "GET /api/v1/online/transactions?per_page=5" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" "$BASE/online/transactions?per_page=5")" "200"
check "GET /api/v1/online/transactions?status=completed" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" "$BASE/online/transactions?status=completed")" "200"
check "GET /api/v1/online/transactions?payment_method=cash" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" "$BASE/online/transactions?payment_method=cash")" "200"

echo ""
echo "=== Final summary ==="
echo "✅ PASS: $PASS"
echo "❌ FAIL: $FAIL"
if [ $FAIL -gt 0 ]; then
    echo "Failed tests: ${FAILED_TESTS[@]}"
    exit 1
fi
echo "🎉 All Online API tests passed!"
