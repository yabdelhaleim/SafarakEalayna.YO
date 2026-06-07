export function findTreasuryAccount(accounts, accountId) {
  if (accountId === '' || accountId == null) return null;
  const id = Number(accountId);
  return (accounts || []).find((acc) => Number(acc.id) === id) || null;
}

export function currenciesMatch(a, b) {
  return String(a || 'EGP').toUpperCase() === String(b || 'EGP').toUpperCase();
}

export function computeConvertedAmount(amount, exchangeRate, fromCurrency, toCurrency) {
  const amt = Number(amount) || 0;
  if (currenciesMatch(fromCurrency, toCurrency)) return amt;
  const rate = Number(exchangeRate) || 0;
  return amt * rate;
}

export function canExecuteCrossCurrencyTransfer({
  fromAccountId,
  toAccountId,
  fromAccount,
  toAccount,
  amount,
  exchangeRate,
}) {
  if (!fromAccountId || !toAccountId) return false;
  if (Number(fromAccountId) === Number(toAccountId)) return false;
  if (!fromAccount || !toAccount) return false;

  const amt = Number(amount);
  if (!amt || amt <= 0) return false;
  if (Number(fromAccount.balance) < amt) return false;

  if (!currenciesMatch(fromAccount.currency, toAccount.currency)) {
    const rate = Number(exchangeRate);
    if (!rate || rate <= 0) return false;
  }

  return true;
}

export function buildTransferApiPayload({
  from_account_id,
  to_account_id,
  amount,
  notes,
  exchange_rate = 1,
  fromAccount,
  toAccount,
}) {
  const payload = {
    from_account_id: Number(from_account_id),
    to_account_id: Number(to_account_id),
    amount: Number(amount),
    notes: notes || '',
  };

  if (fromAccount && toAccount) {
    const sameCurrency = currenciesMatch(fromAccount.currency, toAccount.currency);
    const converted = computeConvertedAmount(
      amount,
      exchange_rate,
      fromAccount.currency,
      toAccount.currency
    );
    payload.converted_amount = converted;
    if (!sameCurrency) {
      payload.exchange_rate = Number(exchange_rate);
    }
  }

  return payload;
}
