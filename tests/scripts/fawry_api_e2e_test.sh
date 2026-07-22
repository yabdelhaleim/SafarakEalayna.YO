#!/bin/bash
# Fawry API end-to-end smoke test

set -e
TOKEN="${FAWRY_API_TOKEN:-}"
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

CUST_ID=$(php -r "require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo App\\Models\\Customer::where('phone', '01510010001')->value('id');" 2>/dev/null)
echo "Using customer_id=$CUST_ID"
echo ""

echo "=== Auth-required GET endpoints ==="
check "GET /api/v1/fawry/dashboard" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" $BASE/fawry/dashboard)" "200"
check "GET /api/v1/fawry/transactions" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" $BASE/fawry/transactions)" "200"
check "GET /api/v1/fawry/transactions/daily-summary?date=2026-07-20" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" "$BASE/fawry/transactions/daily-summary?date=2026-07-20")" "200"
check "GET /api/v1/fawry/transactions/1" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" $BASE/fawry/transactions/1)" "200"
check "GET /api/v1/fawry/customer-balances" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" $BASE/fawry/customer-balances)" "200"
check "GET /api/v1/fawry/customer-statement?customer_id=$CUST_ID" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" "$BASE/fawry/customer-statement?customer_id=$CUST_ID")" "200"
check "GET /api/v1/fawry/accounts" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" $BASE/fawry/accounts)" "200"
check "GET /api/v1/fawry/settings/operation-types" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" $BASE/fawry/settings/operation-types)" "200"
check "GET /api/v1/fawry/settings/payment-methods" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" $BASE/fawry/settings/payment-methods)" "200"
check "GET /api/v1/fawry/settings/currencies" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" $BASE/fawry/settings/currencies)" "200"
check "GET /api/v1/fawry/settings/all" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" $BASE/fawry/settings/all)" "200"
check "GET /api/v1/fawry/treasury/overview" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" $BASE/fawry/treasury/overview)" "200"

echo ""
echo "=== Error handling ==="
check "GET /api/v1/fawry/transactions/99999 (not found)" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" $BASE/fawry/transactions/99999)" "404"

echo ""
echo "=== Machine endpoints ==="
check "GET /api/v1/fawry/machines" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" $BASE/fawry/machines)" "200"
check "GET /api/v1/fawry/machines/5/transactions" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" $BASE/fawry/machines/5/transactions)" "200"

echo ""
echo "=== POST endpoints ==="
# Get IDs for creation
MACHINE_ID=5
ACCOUNT_ID=$(php -r "require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo App\\Models\\Account::where('name', 'خزينة فوري النقدية')->value('id');" 2>/dev/null)
CURRENCY_ID=$(php -r "require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo App\\Models\\Setting\\Currency::where('code', 'EGP')->value('id');" 2>/dev/null)
USER_ID=$(php -r "require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo App\\Models\\User::where('is_active', true)->value('id');" 2>/dev/null)
echo "Account=$ACCOUNT_ID Currency=$CURRENCY_ID User=$USER_ID"

# Create transaction
RESULT=$(curl -s -X POST "${HEADERS[@]}" $BASE/fawry/transactions \
    -d "{\"client_id\":$CUST_ID,\"operation_type\":\"withdrawal\",\"client_amount\":500,\"fawry_price\":475,\"selling_price\":500,\"employee_id\":$USER_ID,\"account_id\":$ACCOUNT_ID,\"fawry_machine_id\":$MACHINE_ID,\"payment_method\":\"cash\",\"amount\":500,\"reference_number\":\"FW-API-001\",\"notes\":\"API test\"}")
TX_ID=$(extract_id "$RESULT")
if [ -n "$TX_ID" ] && [ "$TX_ID" != "None" ]; then
    echo "✅ POST /api/v1/fawry/transactions (created id=$TX_ID)"
    PASS=$((PASS+1))
else
    echo "❌ POST transactions — $RESULT" | head -c 300
    echo ""
    FAIL=$((FAIL+1))
fi

# Recharge machine
RESULT=$(curl -s -X POST "${HEADERS[@]}" $BASE/fawry/machines/$MACHINE_ID/recharge \
    -d "{\"from_account_id\":$ACCOUNT_ID,\"amount\":1000,\"notes\":\"API test recharge\"}")
if echo "$RESULT" | grep -q '"success":true'; then
    echo "✅ POST /api/v1/fawry/machines/$MACHINE_ID/recharge"
    PASS=$((PASS+1))
else
    echo "❌ POST recharge — $RESULT" | head -c 300
    echo ""
    FAIL=$((FAIL+1))
fi

echo ""
echo "=== PUT/DELETE endpoints ==="
if [ -n "$TX_ID" ] && [ "$TX_ID" != "None" ]; then
    RESULT=$(curl -s -X PUT "${HEADERS[@]}" $BASE/fawry/transactions/$TX_ID \
        -d '{"notes":"updated via API","selling_price":510,"fawry_price":475}')
    if echo "$RESULT" | grep -q '"success":true'; then
        echo "✅ PUT /api/v1/fawry/transactions/$TX_ID"
        PASS=$((PASS+1))
    else
        echo "❌ PUT — $RESULT" | head -c 300
        echo ""
        FAIL=$((FAIL+1))
    fi

    # DELETE (soft-delete)
    RESULT=$(curl -s -X DELETE "${HEADERS[@]}" $BASE/fawry/transactions/$TX_ID)
    if echo "$RESULT" | grep -q '"success":true'; then
        echo "✅ DELETE /api/v1/fawry/transactions/$TX_ID"
        PASS=$((PASS+1))
    else
        echo "❌ DELETE — $RESULT" | head -c 300
        echo ""
        FAIL=$((FAIL+1))
    fi
fi

echo ""
echo "=== Pagination & filtering ==="
check "GET /api/v1/fawry/transactions?per_page=5" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" "$BASE/fawry/transactions?per_page=5")" "200"
check "GET /api/v1/fawry/transactions?operation_type=deposit" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" "$BASE/fawry/transactions?operation_type=deposit")" "200"
check "GET /api/v1/fawry/transactions?payment_method=cash" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" "$BASE/fawry/transactions?payment_method=cash")" "200"
check "GET /api/v1/fawry/transactions?from_date=2026-07-01" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" "$BASE/fawry/transactions?from_date=2026-07-01")" "200"
check "GET /api/v1/fawry/transactions?search=test" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" "$BASE/fawry/transactions?search=test")" "200"

echo ""
echo "=== Final summary ==="
echo "✅ PASS: $PASS"
echo "❌ FAIL: $FAIL"
if [ $FAIL -gt 0 ]; then
    echo "Failed tests: ${FAILED_TESTS[@]}"
    exit 1
fi
echo "🎉 All Fawry API tests passed!"
