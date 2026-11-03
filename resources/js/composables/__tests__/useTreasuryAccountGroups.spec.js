/**
 * Tests for `filterSettlementAccountsByCurrency` helper.
 *
 * The helper is used by the Flight RefundWizard (resources/js/components/flights/RefundWizard.vue)
 * to pre-filter the settlement-accounts dropdown so users only see accounts whose currency
 * matches the booking's currency. This matches the server-side validation in
 * app/Services/Flight/FlightBookingService.php::cancelBooking() (Bug #B6 fix) which
 * rejects refunds paid from an account whose currency differs from the booking currency.
 */
import { describe, it, expect } from 'vitest';
import {
    filterSettlementAccountsByCurrency,
    normalizeAccountCurrency,
} from '@/composables/useTreasuryAccountGroups';

describe('filterSettlementAccountsByCurrency', () => {
    const accounts = [
        { id: 1, name: 'CIB Bank EGP', currency: 'EGP', balance: 201500 },
        { id: 2, name: 'Barid Bank EGP', currency: 'EGP', balance: 142000 },
        { id: 3, name: 'Cash Wallet 66 EGP', currency: 'EGP', balance: 202000 },
        { id: 4, name: 'Misr Bank EGP', currency: 'EGP', balance: 105000 },
        { id: 5, name: 'Cash KWD drawer 1', currency: 'KWD', balance: 1000 },
        { id: 6, name: 'Cash KWD drawer 2', currency: 'KWD', balance: 500 },
        { id: 7, name: 'Cash IQD office', currency: 'IQD', balance: 50000 },
    ];

    it('returns only accounts matching the booking currency (KWD)', () => {
        const result = filterSettlementAccountsByCurrency(accounts, 'KWD');
        expect(result).toHaveLength(2);
        expect(result.map((a) => a.id)).toEqual([5, 6]);
    });

    it('returns only accounts matching the booking currency (EGP)', () => {
        const result = filterSettlementAccountsByCurrency(accounts, 'EGP');
        expect(result).toHaveLength(4);
        expect(result.map((a) => a.id)).toEqual([1, 2, 3, 4]);
    });

    it('returns empty array when no account matches (e.g. SAR with no SAR accounts)', () => {
        const result = filterSettlementAccountsByCurrency(accounts, 'SAR');
        expect(result).toEqual([]);
    });

    it('is case-insensitive on the target currency', () => {
        const result = filterSettlementAccountsByCurrency(accounts, 'kwd');
        expect(result).toHaveLength(2);
        expect(result.every((a) => a.currency.toUpperCase() === 'KWD')).toBe(true);
    });

    it('treats lowercase/mixed-case account currencies as matching the uppercase target', () => {
        const mixed = [
            { id: 1, currency: 'egp' },
            { id: 2, currency: 'Egp' },
            { id: 3, currency: 'KWD' },
        ];
        const result = filterSettlementAccountsByCurrency(mixed, 'EGP');
        expect(result.map((a) => a.id)).toEqual([1, 2]);
    });

    it('falls back to EGP when target currency is null/undefined/empty', () => {
        expect(normalizeAccountCurrency(null)).toBe('EGP');
        expect(normalizeAccountCurrency(undefined)).toBe('EGP');
        expect(normalizeAccountCurrency('')).toBe('EGP');
        expect(normalizeAccountCurrency('   ')).toBe('EGP');

        const result = filterSettlementAccountsByCurrency(accounts, null);
        expect(result.map((a) => a.id)).toEqual([1, 2, 3, 4]);
    });

    it('treats accounts with missing/empty currency as EGP (matches EGP fallback semantics)', () => {
        // Documented behavior: normalizeAccountCurrency() defaults missing/empty
        // values to 'EGP', so dirty accounts are caught by an EGP filter.
        // This is a permissive contract — backend validation is the strict gate.
        const dirty = [
            { id: 1, currency: 'EGP' },
            { id: 2, currency: '' },
            { id: 3 }, // no currency key
            { id: 4, currency: null },
            { id: 5, currency: 'KWD' },
        ];
        const result = filterSettlementAccountsByCurrency(dirty, 'EGP');
        expect(result.map((a) => a.id)).toEqual([1, 2, 3, 4]);
    });

    it('returns empty array when accounts list is null or undefined', () => {
        expect(filterSettlementAccountsByCurrency(null, 'EGP')).toEqual([]);
        expect(filterSettlementAccountsByCurrency(undefined, 'EGP')).toEqual([]);
    });

    it('never mutates the input array', () => {
        const snapshot = JSON.parse(JSON.stringify(accounts));
        filterSettlementAccountsByCurrency(accounts, 'KWD');
        expect(accounts).toEqual(snapshot);
    });

    it('does not lose accounts when target currency has surrounding whitespace', () => {
        const result = filterSettlementAccountsByCurrency(accounts, '  KWD  ');
        expect(result).toHaveLength(2);
    });
});
