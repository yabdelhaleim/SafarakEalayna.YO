/**
 * عرض مضغوط للمسافرين على التذكرة:
 * 1. YASSER MOH    كبير    30K
 */

export const PASSENGER_TYPE_COMPACT_LABELS = {
  adult: 'كبير',
  child: 'طفل',
  infant: 'رضيع',
};

export function passengerFirstName(passenger) {
  const first = String(passenger?.firstName || passenger?.first_name || '').trim();
  if (first) return first;
  const parts = String(passenger?.name || '').trim().split(/\s+/).filter(Boolean);
  return parts[0] || '';
}

export function passengerLastName(passenger) {
  const last = String(passenger?.lastName || passenger?.last_name || '').trim();
  if (last) return last;
  const parts = String(passenger?.name || '').trim().split(/\s+/).filter(Boolean);
  return parts.length > 1 ? parts.slice(1).join(' ') : '';
}

/** اسم مختصر: الاسم الأول + أول 3 حروف من الأخير (بأحرف كبيرة) */
export function compactPassengerName(passenger) {
  const first = passengerFirstName(passenger).toUpperCase();
  const last = passengerLastName(passenger).toUpperCase();
  if (!first && !last) return '—';
  const lastShort = last ? (last.length > 3 ? last.slice(0, 3) : last) : '';
  return [first, lastShort].filter(Boolean).join(' ');
}

export function compactPassengerTypeLabel(type) {
  const key = String(type || 'adult').toLowerCase();
  return PASSENGER_TYPE_COMPACT_LABELS[key] || key;
}

export function compactBaggageLabel(passenger) {
  const kg = Number(passenger?.baggageAllowanceKg ?? passenger?.baggage_allowance_kg ?? 0) || 0;
  if (kg <= 0) return '—';
  return `${kg}K`;
}

export function compactPassengerLine(index, passenger) {
  const num = Number(index) + 1;
  const name = compactPassengerName(passenger);
  const type = compactPassengerTypeLabel(passenger?.type);
  const baggage = compactBaggageLabel(passenger);
  return `${num}. ${name}    ${type}    ${baggage}`;
}
