const DEFAULT_CURRENCY_SUFFIX = 'جنيه';

export function formatMoneyAmount(val, options = {}) {
  const amount = Math.abs(parseFloat(val) || 0);
  const suffix = options.suffix ?? DEFAULT_CURRENCY_SUFFIX;
  const locale = options.locale ?? 'en-US';
  const formatted = amount.toLocaleString(locale, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });

  return suffix ? `${formatted} ${suffix}` : formatted;
}

/**
 * Ledger convention: positive = entity owes us (debit), negative = we owe entity (credit).
 */
export function formatLedgerBalance(balance, type = 'customer') {
  const bal = parseFloat(balance) || 0;

  if (bal > 0.009) {
    return {
      text: formatMoneyAmount(bal),
      label: type === 'group' ? '(مستحق لهم)' : '(مدين — عليه)',
      shortLabel: type === 'group' ? 'مستحق لهم' : 'مدين — عليه',
      class: 'text-error font-bold',
      direction: 'debit',
      raw: bal,
    };
  }

  if (bal < -0.009) {
    return {
      text: formatMoneyAmount(Math.abs(bal)),
      label: type === 'group' ? '(مستحق لنا)' : '(دائن — له)',
      shortLabel: type === 'group' ? 'مستحق لنا' : 'دائن — له',
      class: 'text-success font-bold',
      direction: 'credit',
      raw: bal,
    };
  }

  return {
    text: '0.00 — مستوفى',
    label: '',
    shortLabel: 'مستوفى',
    class: 'text-muted',
    direction: 'zero',
    raw: 0,
  };
}

export function projectedLedgerBalance(currentBalance, sellingPrice, initialPayment = 0) {
  const cur = parseFloat(currentBalance) || 0;
  const sell = parseFloat(sellingPrice) || 0;
  const pay = parseFloat(initialPayment) || 0;

  return cur + sell - pay;
}

export function useLedgerBalance() {
  return {
    formatLedgerBalance,
    formatMoneyAmount,
    projectedLedgerBalance,
  };
}
