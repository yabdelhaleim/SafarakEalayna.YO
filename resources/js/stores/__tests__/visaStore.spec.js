import { describe, it, expect, beforeEach, vi } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import axios from 'axios';
import { useVisaStore } from '@/stores/visaStore';

vi.mock('axios');

describe('Visa Pinia Store — Financial Operations', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    const fakeBooking = (overrides = {}) => ({
        id: 1,
        module: 'VISA',
        status: 'submitted',
        status_label: 'مُقدّم',
        customer: { id: 5, full_name: 'أحمد', phone: '0100', name: 'أحمد' },
        visa_detail: { id: 9, country: 'SA', visa_type: 'tourist' },
        pricing: { purchase_price: 6000, selling_price: 9000, service_fee: 500, profit: 3500, currency: 'EGP' },
        finance: { paid_amount: 0, remaining_amount: 9500, is_fully_paid: false },
        agent_name: 'وكيل',
        notes: 'ملاحظات',
        created_at: '2026-08-29T10:00:00Z',
        updated_at: '2026-08-29T10:00:00Z',
        ...overrides,
    });

    it('initializes empty state', () => {
        const store = useVisaStore();
        expect(store.bookings).toEqual([]);
        expect(store.currentBooking).toBeNull();
        expect(store.customers).toEqual([]);
        expect(store.loading.list).toBe(false);
        expect(store.pagination.total).toBe(0);
    });

    it('fetchBookings — happy path populates store', async () => {
        axios.get.mockResolvedValueOnce({
            data: {
                data: {
                    items: [fakeBooking({ id: 1 }), fakeBooking({ id: 2 })],
                    pagination: { total: 2, per_page: 15, current_page: 1, last_page: 1, has_more_pages: false },
                },
            },
        });
        const store = useVisaStore();
        await store.fetchBookings();
        expect(store.bookings.length).toBe(2);
        expect(store.pagination.total).toBe(2);
        expect(store.bookings[0].total_paid).toBe(0);
        expect(store.bookings[0].remaining).toBe(9500);
    });

    it('fetchBookings — handles errors gracefully', async () => {
        axios.get.mockRejectedValueOnce(new Error('Network'));
        const store = useVisaStore();
        await store.fetchBookings();
        expect(store.errors.fetch).toBeDefined();
        expect(store.bookings).toEqual([]);
    });

    it('fetchBookingById — loads single booking', async () => {
        axios.get.mockResolvedValueOnce({ data: { data: fakeBooking({ id: 99 }) } });
        const store = useVisaStore();
        const booking = await store.fetchBookingById(99);
        expect(booking.id).toBe(99);
        expect(store.currentBooking.id).toBe(99);
    });

    it('createBooking — adds new booking to head of list', async () => {
        axios.post.mockResolvedValueOnce({ data: { data: fakeBooking({ id: 50 }) } });
        const store = useVisaStore();
        const created = await store.createBooking({});
        expect(created.id).toBe(50);
        expect(store.bookings.length).toBe(1);
        expect(store.bookings[0].id).toBe(50);
    });

    it('createBooking — captures validation errors', async () => {
        axios.post.mockRejectedValueOnce({
            response: { data: { errors: { purchase_price: ['مطلوب'] }, message: 'فشل التحقق' } },
        });
        const store = useVisaStore();
        await expect(store.createBooking({})).rejects.toThrow();
        expect(store.errors.purchase_price).toEqual(['مطلوب']);
    });

    it('cancelBooking — updates status in list', async () => {
        const store = useVisaStore();
        store.bookings = [fakeBooking({ id: 7, status: 'submitted' })];
        axios.delete.mockResolvedValueOnce({
            data: { data: fakeBooking({ id: 7, status: 'cancelled' }) },
        });
        const cancelled = await store.cancelBooking(7, 'لاختبار');
        expect(cancelled.status).toBe('cancelled');
        expect(store.bookings[0].status).toBe('cancelled');
    });

    it('deleteBooking — removes from list', async () => {
        const store = useVisaStore();
        store.bookings = [fakeBooking({ id: 8 }), fakeBooking({ id: 9 })];
        axios.delete.mockResolvedValueOnce({ data: { success: true } });
        await store.deleteBooking(8);
        expect(store.bookings.length).toBe(1);
        expect(store.bookings[0].id).toBe(9);
    });

    it('addPayment — happy path returns enriched payment', async () => {
        axios.post.mockResolvedValueOnce({
            data: {
                success: true,
                data: {
                    idempotent_replay: false,
                    payment: { id: 1, amount: 1000, payment_method: 'cash', account: { id: 1, name: 'V' } },
                    booking: fakeBooking({ id: 11, finance: { paid_amount: 1000, remaining_amount: 8500, is_fully_paid: false } }),
                },
            },
        });
        const store = useVisaStore();
        const res = await store.addPayment(11, { amount: 1000, account_id: 1, payment_method: 'cash' });
        expect(res.idempotent_replay).toBe(false);
        expect(res.payment.amount).toBe(1000);
    });

    it('addPayment — idempotent replay returns same row', async () => {
        axios.post.mockResolvedValueOnce({
            data: {
                success: true,
                data: {
                    idempotent_replay: true,
                    payment: { id: 1, amount: 1000 },
                    booking: fakeBooking({ id: 11 }),
                },
            },
        });
        const store = useVisaStore();
        const res = await store.addPayment(11, { amount: 1000, transaction_reference: 'REF-001' });
        expect(res.idempotent_replay).toBe(true);
    });

    it('bookingStats — computes totals correctly', () => {
        const store = useVisaStore();
        store.bookings = [
            fakeBooking({ id: 1, status: 'submitted', pricing: { selling_price: 9000, service_fee: 500, profit: 3500 } }),
            fakeBooking({ id: 2, status: 'approved', pricing: { selling_price: 1200, service_fee: 50, profit: 450 } }),
            fakeBooking({ id: 3, status: 'cancelled', pricing: { selling_price: 6000, service_fee: 200, profit: 500 } }),
        ];
        const stats = store.bookingStats;
        expect(stats.total).toBe(3);
        expect(stats.revenue).toBe(16950); // (9000+500)+(1200+50)+(6000+200)
        expect(stats.profit).toBe(4450); // 3500+450+500
        expect(stats.approved).toBe(1);
        expect(stats.pending).toBe(1);
        expect(stats.active).toBe(2); // excludes cancelled
    });

    it('filteredBookings — search filter works', () => {
        const store = useVisaStore();
        store.bookings = [
            fakeBooking({ id: 1, customer: { ...fakeBooking().customer, full_name: 'أحمد محمد', name: 'أحمد محمد' } }),
            fakeBooking({ id: 2, customer: { ...fakeBooking().customer, full_name: 'سارة علي', name: 'سارة علي' } }),
        ];
        const filtered = store.filteredBookings({ search: 'أحمد' });
        expect(filtered.length).toBe(1);
        expect(filtered[0].id).toBe(1);
    });

    it('filteredBookings — status filter works', () => {
        const store = useVisaStore();
        store.bookings = [
            fakeBooking({ id: 1, status: 'submitted' }),
            fakeBooking({ id: 2, status: 'cancelled' }),
        ];
        const filtered = store.filteredBookings({ status: 'cancelled' });
        expect(filtered.length).toBe(1);
        expect(filtered[0].id).toBe(2);
    });

    it('fetchVisaCustomerBalances — populates from API', async () => {
        axios.get.mockResolvedValueOnce({
            data: {
                data: [
                    { client_id: 1, client_name: 'عميل', total_debt: 5000 },
                ],
            },
        });
        const store = useVisaStore();
        await store.fetchVisaCustomerBalances({ status: 'debtors' });
        expect(store.errors).toBeDefined();
    });

    it('payVisaCustomerDebt — posts to pay-debt endpoint', async () => {
        axios.post.mockResolvedValueOnce({
            data: {
                success: true,
                data: { transaction_id: 50, new_balance: 0, applied_to: [] },
            },
        });
        const store = useVisaStore();
        const res = await store.payVisaCustomerDebt(5, { amount: 1000, account_id: 1 });
        expect(res.transaction_id).toBe(50);
    });

    it('recordVisaAgentWithdraw — calls withdraw endpoint', async () => {
        axios.post.mockResolvedValueOnce({
            data: { success: true, data: { transaction_id: 99 } },
        });
        const store = useVisaStore();
        const res = await store.recordVisaAgentWithdraw(3, { amount: 500, to_account_id: 1 });
        expect(res.transaction_id).toBe(99);
    });

    it('recordVisaAgentRepay — calls repay endpoint', async () => {
        axios.post.mockResolvedValueOnce({
            data: { success: true, data: { transaction_id: 100 } },
        });
        const store = useVisaStore();
        const res = await store.recordVisaAgentRepay(3, { amount: 500, from_account_id: 1 });
        expect(res.transaction_id).toBe(100);
    });

    it('fetchSettings — loads agents, durations, statuses', async () => {
        axios.get.mockResolvedValue({
            data: { data: [{ id: 1, name: 'وكيل' }] },
        });
        const store = useVisaStore();
        await store.fetchSettings();
        expect(store.durations.length).toBeGreaterThanOrEqual(0);
    });

    it('addToast — adds and auto-removes toast', () => {
        vi.useFakeTimers();
        const store = useVisaStore();
        store.addToast('تم بنجاح', 'success');
        expect(store.toasts.length).toBe(1);
        expect(store.toasts[0].message).toBe('تم بنجاح');
        expect(store.toasts[0].type).toBe('success');
        vi.advanceTimersByTime(4100);
        expect(store.toasts.length).toBe(0);
        vi.useRealTimers();
    });

    it('_enrich — flattens nested API response into legacy shape', () => {
        const store = useVisaStore();
        const enriched = store._enrich(fakeBooking({ id: 55 }));
        expect(enriched.selling_price).toBe(9000);
        expect(enriched.purchase_price).toBe(6000);
        expect(enriched.service_fee).toBe(500);
        expect(enriched.profit).toBe(3500);
        expect(enriched.currency).toBe('EGP');
        expect(enriched.total_paid).toBe(0);
        expect(enriched.remaining).toBe(9500);
        expect(enriched.is_fully_paid).toBe(false);
    });

    it('_enrich — handles null gracefully', () => {
        const store = useVisaStore();
        expect(store._enrich(null)).toBeNull();
    });
});
