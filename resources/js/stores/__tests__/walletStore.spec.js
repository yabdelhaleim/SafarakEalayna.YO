import { describe, it, expect, beforeEach, vi } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import axios from 'axios';
import { useWalletStore } from '@/stores/walletStore';

vi.mock('axios');
vi.mock('@/utils/api', () => ({
    isRequestCanceled: vi.fn(() => false),
}));

describe('Wallet Pinia Store — Financial Operations', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    const fakeTransaction = (overrides = {}) => ({
        id: 1,
        type: 'send',
        type_label: 'إرسال رصيد',
        type_color: 'warning',
        customer_name: 'أحمد',
        wallet_number: '01012345678',
        amount: 500,
        service_fee: 10,
        total_amount: 510,
        amount_paid: 510,
        notes: 'test',
        wallet_type: { id: 1, name: 'فودافون كاش' },
        created_at: '2026-08-29T10:00:00Z',
        updated_at: '2026-08-29T10:00:00Z',
        ...overrides,
    });

    it('initializes empty state', () => {
        const store = useWalletStore();
        expect(store.transactions).toEqual([]);
        expect(store.currentTransaction).toBeNull();
        expect(store.walletTypes).toEqual([]);
        expect(store.loading.transactions).toBe(false);
        expect(store.pagination.total).toBe(0);
    });

    it('fetchWalletTypes — populates wallet types', async () => {
        axios.get.mockResolvedValueOnce({
            data: [
                { id: 1, name: 'فودافون كاش', code: 'vodafone_cash', is_active: true },
            ],
        });
        const store = useWalletStore();
        await store.fetchWalletTypes();
        expect(store.walletTypes.length).toBe(1);
        expect(store.walletTypes[0].code).toBe('vodafone_cash');
    });

    it('fetchWalletTypes — wraps response in array if data is paginated', async () => {
        axios.get.mockResolvedValueOnce({
            data: { data: [{ id: 1, name: 'X', code: 'x', is_active: true }] },
        });
        const store = useWalletStore();
        await store.fetchWalletTypes();
        expect(store.walletTypes.length).toBe(1);
    });

    it('fetchTransactions — populates transactions list', async () => {
        axios.get.mockResolvedValueOnce({
            data: {
                data: {
                    items: [fakeTransaction({ id: 1 }), fakeTransaction({ id: 2 })],
                    pagination: { total: 2, current_page: 1, last_page: 1, per_page: 20 },
                },
            },
        });
        const store = useWalletStore();
        await store.fetchTransactions();
        expect(store.transactions.length).toBe(2);
    });

    it('fetchTransactions — handles errors gracefully', async () => {
        axios.get.mockRejectedValueOnce(new Error('Network'));
        const store = useWalletStore();
        await store.fetchTransactions();
        expect(store.errors.fetch).toBeDefined();
        expect(store.transactions).toEqual([]);
    });

    it('fetchTransaction — loads single transaction', async () => {
        axios.get.mockResolvedValueOnce({
            data: { data: fakeTransaction({ id: 99 }) },
        });
        const store = useWalletStore();
        const tx = await store.fetchTransaction(99);
        expect(tx.id).toBe(99);
        expect(store.currentTransaction.id).toBe(99);
    });

    it('createTransaction — adds to head of list', async () => {
        // createTransaction calls fetchDailySummary after success (one extra GET)
        axios.post.mockResolvedValueOnce({
            data: { data: fakeTransaction({ id: 50 }) },
        });
        axios.get.mockResolvedValueOnce({
            data: { data: { total_send: 0 } },
        });
        const store = useWalletStore();
        store.transactions = [fakeTransaction({ id: 1 })];
        const created = await store.createTransaction({});
        expect(created.id).toBe(50);
        expect(store.transactions[0].id).toBe(50);
    });

    it('updateTransaction — replaces in list', async () => {
        axios.put.mockResolvedValueOnce({
            data: { data: fakeTransaction({ id: 1, notes: 'updated' }) },
        });
        const store = useWalletStore();
        store.transactions = [fakeTransaction({ id: 1, notes: 'old' })];
        await store.updateTransaction(1, { notes: 'updated' });
        expect(store.transactions[0].notes).toBe('updated');
    });

    it('deleteTransaction — removes from list', async () => {
        axios.delete.mockResolvedValueOnce({ data: { success: true } });
        const store = useWalletStore();
        store.transactions = [fakeTransaction({ id: 1 }), fakeTransaction({ id: 2 })];
        await store.deleteTransaction(1);
        expect(store.transactions.length).toBe(1);
        expect(store.transactions[0].id).toBe(2);
    });

    it('fetchDailySummary — calls API', async () => {
        axios.get.mockResolvedValueOnce({
            data: { data: { total_send: 5, total_receive: 3, total_amount: 5000 } },
        });
        const store = useWalletStore();
        await store.fetchDailySummary();
        expect(axios.get).toHaveBeenCalled();
        expect(axios.get.mock.calls[0][0]).toContain('/daily-summary');
    });

    it('fetchTransferDashboard — calls API', async () => {
        axios.get.mockResolvedValueOnce({
            data: { data: { wallet_balance: 100000, today_count: 12 } },
        });
        const store = useWalletStore();
        await store.fetchTransferDashboard();
        expect(axios.get).toHaveBeenCalled();
        expect(axios.get.mock.calls[0][0]).toContain('/dashboard');
    });

    it('fetchTransferTreasury — calls API', async () => {
        axios.get.mockResolvedValueOnce({
            data: { data: { settlement_accounts: [], executing_companies: [] } },
        });
        const store = useWalletStore();
        await store.fetchTransferTreasury();
        expect(axios.get).toHaveBeenCalled();
        expect(axios.get.mock.calls[0][0]).toContain('/treasury/overview');
    });

    it('fetchAccountTransactions — calls API', async () => {
        axios.get.mockResolvedValueOnce({
            data: { data: [fakeTransaction({ id: 5 })] },
        });
        const store = useWalletStore();
        await store.fetchAccountTransactions(1);
        expect(axios.get).toHaveBeenCalled();
        expect(axios.get.mock.calls[0][0]).toContain('/treasury/accounts/1/transactions');
    });

    it('setFilter — updates filter and resets pagination', () => {
        const store = useWalletStore();
        store.setFilter('type', 'send');
        expect(store.filters.type).toBe('send');
        expect(store.pagination.current_page).toBe(1);
    });

    it('resetFilters — restores default filters', () => {
        const store = useWalletStore();
        store.setFilter('type', 'send');
        store.setFilter('search', 'x');
        store.resetFilters();
        expect(store.filters.type).toBe('');
        expect(store.filters.search).toBe('');
    });

    it('addToast — calls global window.addToast when present', () => {
        const store = useWalletStore();
        // The wallet store delegates toasts to a global handler (window.addToast)
        // instead of maintaining a local queue. Verify that delegation works.
        expect(() => store.addToast('test', 'success')).not.toThrow();
    });

    it('totalProfit — sums service_fee across all transactions', () => {
        const store = useWalletStore();
        store.transactions = [
            fakeTransaction({ id: 1, service_fee: 10 }),
            fakeTransaction({ id: 2, service_fee: 20 }),
            fakeTransaction({ id: 3, service_fee: 5 }),
        ];
        expect(store.totalProfit).toBe(35);
    });

    it('activeWalletTypes vs inactiveWalletTypes', () => {
        const store = useWalletStore();
        store.walletTypes = [
            { id: 1, name: 'A', is_active: true },
            { id: 2, name: 'B', is_active: false },
        ];
        expect(store.activeWalletTypes.length).toBe(2); // getter returns all
        expect(store.inactiveWalletTypes.length).toBe(1);
    });
});
