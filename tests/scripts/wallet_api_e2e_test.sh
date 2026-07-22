#!/bin/bash
# Wallet API end-to-end smoke test

set -e
TOKEN="${WALLET_API_TOKEN:-}"
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

CUST_ID=$(php -r "require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo App\\Models\\Customer::where('phone', '01730030001')->value('id');" 2>/dev/null)
EMP_ID=$(php -r "require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo App\\Models\\Employee::first()->id;" 2>/dev/null)
echo "Using customer_id=$CUST_ID employee_id=$EMP_ID"
echo ""

echo "=== Auth-required GET endpoints ==="
check "GET /api/v1/wallet/dashboard" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" $BASE/wallet/dashboard)" "200"
check "GET /api/v1/wallet/transactions" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" $BASE/wallet/transactions)" "200"
check "GET /api/v1/wallet/types" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" $BASE/wallet/types)" "200"
check "GET /api/v1/wallet/customer-balances" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" $BASE/wallet/customer-balances)" "200"
check "GET /api/v1/wallet/customer-statement?customer_id=$CUST_ID" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" "$BASE/wallet/customer-statement?customer_id=$CUST_ID")" "200"
check "GET /api/v1/wallet/treasury/overview" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" $BASE/wallet/treasury/overview)" "200"
check "GET /api/v1/wallet/transactions/daily-summary?date=2026-07-20" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" "$BASE/wallet/transactions/daily-summary?date=2026-07-20")" "200"

echo ""
echo "=== Error handling ==="
check "GET /api/v1/wallet/transactions/99999 (not found)" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" $BASE/wallet/transactions/99999)" "404"

echo ""
echo "=== POST endpoints ==="
WT_ID=1  # vodafone_cash
WALLET_ID=$(php -r "require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo App\\Models\\Account::where('name', 'like', '%فودافون كاش%')->value('id');" 2>/dev/null)
CASH_ID=$(php -r "require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo App\\Models\\Account::where('name', 'خزينة المحافظ النقدية')->value('id');" 2>/dev/null)
echo "Using wallet_type=$WT_ID wallet=$WALLET_ID cash=$CASH_ID"

# Create transaction (send)
RESULT=$(curl -s -X POST "${HEADERS[@]}" $BASE/wallet/transactions \
    -d "{\"wallet_type_id\":$WT_ID,\"customer_id\":$CUST_ID,\"customer_name\":\"API Test Send\",\"wallet_number\":\"01099999001\",\"type\":\"send\",\"amount\":100,\"service_fee\":1,\"amount_paid\":101,\"wallet_account_id\":$WALLET_ID,\"cash_account_id\":$CASH_ID,\"employee_id\":$EMP_ID,\"notes\":\"API test send\"}")
TX_ID=$(extract_id "$RESULT")
if [ -n "$TX_ID" ] && [ "$TX_ID" != "None" ]; then
    echo "✅ POST /api/v1/wallet/transactions (created id=$TX_ID)"
    PASS=$((PASS+1))
else
    echo "❌ POST — $RESULT" | head -c 300
    echo ""
    FAIL=$((FAIL+1))
fi

# Update
if [ -n "$TX_ID" ] && [ "$TX_ID" != "None" ]; then
    RESULT=$(curl -s -X PUT "${HEADERS[@]}" $BASE/wallet/transactions/$TX_ID \
        -d '{"notes":"updated via API","amount":150}')
    if echo "$RESULT" | grep -q '"success":true'; then
        echo "✅ PUT /api/v1/wallet/transactions/$TX_ID"
        PASS=$((PASS+1))
    else
        echo "❌ PUT — $RESULT" | head -c 300
        echo ""
        FAIL=$((FAIL+1))
    fi
fi

# Pagination & filter
check "GET /api/v1/wallet/transactions?per_page=5" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" "$BASE/wallet/transactions?per_page=5")" "200"
check "GET /api/v1/wallet/transactions?type=send" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" "$BASE/wallet/transactions?type=send")" "200"
check "GET /api/v1/wallet/transactions?wallet_type_id=$WT_ID" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" "$BASE/wallet/transactions?wallet_type_id=$WT_ID")" "200"
check "GET /api/v1/wallet/transactions?search=API" "$(curl -s -o /dev/null -w '%{http_code}' "${HEADERS[@]}" "$BASE/wallet/transactions?search=API")" "200"

echo ""
echo "=== Final summary ==="
echo "✅ PASS: $PASS"
echo "❌ FAIL: $FAIL"
if [ $FAIL -gt 0 ]; then
    echo "Failed tests: ${FAILED_TESTS[@]}"
    exit 1
fi
echo "🎉 All Wallet API tests passed!"
