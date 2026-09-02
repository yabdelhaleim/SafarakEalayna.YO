import { describe, it, expect, beforeEach, vi } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import axios from 'axios';
import { useHajjUmraStore } from '@/stores/hajjUmraStore';

vi.mock('axios');
vi.mock('@/utils/api', () => ({
    isRequestCanceled: vi.fn(() => false),
}));
vi.mock('@/composables/useTreasuryAccountGroups', () => ({
    fetchSettlementAccounts: vi.fn(() => Promise.resolve([])),
    filterSettlementAccountsByModule: vi.fn(() => []),
}));

describe('HajjUmra Pinia Store — Financial Operations', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    const fakeBooking = (overrides = {}) => ({
        id: 1,
        module: 'hajj_umra',
        status: 'confirmed',
        status_label: 'مؤكد',
        customer: { id: 5, full_name: 'محمد', phone: '0100' },
        program: { id: 1, program_name: 'حج 2026', program_type: 'hajj' },
        pricing: {
            purchase_price: 42000,
            selling_price: 50000,
            companion_purchase_price: 0,
            companion_selling_price: 0,
            profit: 8000,
            currency: 'EGP',
            per_person: true,
            accommodation_choice: 'standard',
            accommodation_extra_charge: 0,
        },
        finance: {
            paid_amount: 0,
            remaining_amount: 50000,
            is_fully_paid: false,
            expense_transaction_id: 100,
            income_transaction_id: 101,
            account: { id: 4, name: 'خزينة EGP', type: 'cashbox', currency: 'EGP' },
        },
        agent_name: 'محمد',
        notes: '',
        payments: [],
        created_at: '2026-08-29T10:00:00Z',
        updated_at: '2026-08-29T10:00:00Z',
        ...overrides,
    });

    it('initializes empty state', () => {
        const store = useHajjUmraStore();
        expect(store.bookings).toEqual([]);
        expect(store.currentBooking).toBeNull();
        expect(store.customers).toEqual([]);
        expect(store.loading.list).toBe(false);
        expect(store.pagination.total).toBe(0);
    });

    it('fetchBookings — populates bookings and pagination', async () => {
        axios.get.mockResolvedValueOnce({
            data: {
                data: {
                    items: [fakeBooking({ id: 1 }), fakeBooking({ id: 2 })],
                    pagination: { total: 2, per_page: 15, current_page: 1, last_page: 1 },
                },
            },
        });
        const store = useHajjUmraStore();
        await store.fetchBookings();
        expect(store.bookings.length).toBe(2);
        expect(store.pagination.total).toBe(2);
        expect(store.pagination.lastPage).toBe(1);
    });

    it('fetchBookings — handles network error', async () => {
        axios.get.mockRejectedValueOnce(new Error('Network'));
        const store = useHajjUmraStore();
        await store.fetchBookings();
        expect(store.errors.fetch).toBeDefined();
        expect(store.bookings).toEqual([]);
    });

    it('fetchBookingById — loads single booking', async () => {
        axios.get.mockResolvedValueOnce({ data: { data: fakeBooking({ id: 99 }) } });
        const store = useHajjUmraStore();
        const booking = await store.fetchBookingById(99);
        expect(booking.id).toBe(99);
        expect(store.currentBooking.id).toBe(99);
    });

    it('createBooking — adds to head of list', async () => {
        axios.post.mockResolvedValueOnce({ data: { data: fakeBooking({ id: 50 }) } });
        const store = useHajjUmraStore();
        const created = await store.createBooking({});
        expect(created.id).toBe(50);
        expect(store.bookings.length).toBe(1);
        expect(store.bookings[0].id).toBe(50);
    });

    it('cancelBooking — updates booking status in list', async () => {
        axios.post.mockResolvedValueOnce({
            data: { data: fakeBooking({ id: 1, status: 'cancelled', status_label: 'ملغي' }) },
        });
        const store = useHajjUmraStore();
        store.bookings = [fakeBooking({ id: 1, status: 'confirmed' })];
        await store.cancelBooking(1, 'test');
        expect(store.bookings[0].status).toBe('cancelled');
    });

    it('deleteBooking — removes from list', async () => {
        axios.delete.mockResolvedValueOnce({ data: { success: true } });
        const store = useHajjUmraStore();
        store.bookings = [fakeBooking({ id: 1 }), fakeBooking({ id: 2 })];
        await store.deleteBooking(1);
        expect(store.bookings.length).toBe(1);
        expect(store.bookings[0].id).toBe(2);
    });

    it('addPayment — happy path updates booking', async () => {
        axios.post.mockResolvedValueOnce({
            data: {
                success: true,
                data: {
                    payment: { id: 200, amount: 25000 },
                    booking: fakeBooking({
                        id: 1,
                        finance: {
                            paid_amount: 25000,
                            remaining_amount: 25000,
                            is_fully_paid: false,
                            expense_transaction_id: 100,
                            income_transaction_id: 101,
                            account: { id: 4, name: 'خزينة EGP', type: 'cashbox', currency: 'EGP' },
                        },
                    }),
                    idempotent_replay: false,
                },
            },
        });
        const store = useHajjUmraStore();
        store.bookings = [fakeBooking({ id: 1 })];
        const result = await store.addPayment(1, { amount: 25000 });
        expect(axios.post).toHaveBeenCalled();
        expect(result).toBeDefined();
        expect(store.bookings[0].finance.paid_amount).toBe(25000);
    });

    it('addPayment — idempotent replay does not double-update', async () => {
        axios.post.mockResolvedValueOnce({
            data: {
                success: true,
                data: {
                    payment: { id: 200, amount: 25000 },
                    booking: fakeBooking({
                        id: 1,
                        finance: {
                            paid_amount: 25000,
                            remaining_amount: 25000,
                            is_fully_paid: false,
                            expense_transaction_id: 100,
                            income_transaction_id: 101,
                            account: { id: 4, name: 'خزينة EGP', type: 'cashbox', currency: 'EGP' },
                        },
                    }),
                    idempotent_replay: true,
                },
            },
        });
        const store = useHajjUmraStore();
        store.bookings = [fakeBooking({ id: 1, finance: { paid_amount: 0, remaining_amount: 50000, expense_transaction_id: 100, income_transaction_id: 101, account: null } })];
        await store.addPayment(1, { amount: 25000, idempotency_key: 'abc' });
        expect(store.bookings[0].finance.paid_amount).toBe(25000);
    });

    it('bookingStats — computes totals correctly', () => {
        const store = useHajjUmraStore();
        store.bookings = [
            fakeBooking({ id: 1, status: 'confirmed', pricing: { selling_price: 50000, profit: 8000 } }),
            fakeBooking({ id: 2, status: 'cancelled', pricing: { selling_price: 60000, profit: 10000 } }),
            fakeBooking({ id: 3, status: 'in_progress', pricing: { selling_price: 40000, profit: 6000 } }),
        ];
        const stats = store.bookingStats;
        expect(stats.total).toBe(3);
        expect(stats.revenue).toBe(150000);
        expect(stats.profit).toBe(24000);
        expect(stats.active).toBe(2); // confirmed + in_progress
    });

    it('bookingStats — empty list returns zeros', () => {
        const store = useHajjUmraStore();
        const stats = store.bookingStats;
        expect(stats.total).toBe(0);
        expect(stats.revenue).toBe(0);
        expect(stats.profit).toBe(0);
        expect(stats.active).toBe(0);
    });

    it('filteredBookings — filter by status', () => {
        const store = useHajjUmraStore();
        store.bookings = [
            fakeBooking({ id: 1, status: 'confirmed' }),
            fakeBooking({ id: 2, status: 'cancelled' }),
            fakeBooking({ id: 3, status: 'confirmed' }),
        ];
        const filtered = store.filteredBookings({ status: 'confirmed' });
        expect(filtered.length).toBe(2);
    });

    it('filteredBookings — filter by programType', () => {
        const store = useHajjUmraStore();
        store.bookings = [
            fakeBooking({ id: 1, program: { program_type: 'hajj' } }),
            fakeBooking({ id: 2, program: { program_type: 'umrah' } }),
            fakeBooking({ id: 3, program: { program_type: 'hajj' } }),
        ];
        const filtered = store.filteredBookings({ programType: 'umrah' });
        expect(filtered.length).toBe(1);
    });

    it('fetchSettings — populates settings', async () => {
        // fetchSettings calls 5 axios.get in Promise.all
        axios.get
            .mockResolvedValueOnce({ data: { data: [{ id: 1, program_name: 'P1' }] } })           // programs
            .mockResolvedValueOnce({ data: { data: [{ id: 1, name: 'EC1' }] } })                // executing companies
            .mockResolvedValueOnce({ data: { data: [] } })                                       // trip supervisors
            .mockResolvedValueOnce({ data: { data: [] } })                                       // accommodation types
            .mockResolvedValueOnce({ data: { data: { hajj_umra: [], visa: [], visa_types: [], visa_entry_types: [] } } }); // statuses
        const store = useHajjUmraStore();
        await store.fetchSettings();
        expect(store.programs.length).toBe(1);
        expect(store.executingCompanies.length).toBe(1);
    });

    it('addToast — adds toast to queue', () => {
        const store = useHajjUmraStore();
        store.addToast('تم بنجاح', 'success');
        expect(store.toasts.length).toBe(1);
        expect(store.toasts[0].message).toBe('تم بنجاح');
        expect(store.toasts[0].type).toBe('success');
    });

    it('addToast — defaults to success type', () => {
        const store = useHajjUmraStore();
        store.addToast('default');
        expect(store.toasts[0].type).toBe('success');
    });

    it('fetchCustomers — populates customers', async () => {
        axios.get.mockResolvedValueOnce({
            data: { data: [{ id: 1, full_name: 'محمد', phone: '0100' }] },
        });
        const store = useHajjUmraStore();
        await store.fetchCustomers();
        expect(store.customers.length).toBe(1);
        expect(store.customers[0].full_name).toBe('محمد');
    });
});
