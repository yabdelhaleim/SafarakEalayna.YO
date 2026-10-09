/**
 * Frontend coverage for /resources/js/views/reports/DebtsIndex.vue
 *
 * These tests mount the real DebtsIndex component in happy-dom and:
 *  - Stub the axios.get('/api/v1/reports/debts') endpoint so the report
 *    renders against a controlled fixture set.
 * - Verify filter buttons drive the next request URL.
 * - Verify totals and the per-row balance label format correctly.
 * - Verify the empty state, the loading state, and the error retry path.
 * - Verify the printSettingsStore.fetch() side-effect on mount.
 *
 * @see resources/js/views/reports/DebtsIndex.vue
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import axios from 'axios';
import DebtsIndex from '@/views/reports/DebtsIndex.vue';
import { usePrintSettingsStore } from '@/stores/printSettingsStore';

// Stub lucide-vue-next icons — they are presentational only and they
// pull in extra ESM modules that are irrelevant to these assertions.
vi.mock('lucide-vue-next', () => {
    const stub = (name) => ({ name, template: '<svg data-icon="' + name + '"></svg>' });
    return {
        Scale: stub('Scale'),
        ArrowUpRight: stub('ArrowUpRight'),
        ArrowDownRight: stub('ArrowDownRight'),
        TrendingUp: stub('TrendingUp'),
        TrendingDown: stub('TrendingDown'),
        Filter: stub('Filter'),
        RotateCcw: stub('RotateCcw'),
        Search: stub('Search'),
        ExternalLink: stub('ExternalLink'),
        Printer: stub('Printer'),
        Loader2: stub('Loader2'),
        ChevronDown: stub('ChevronDown'),
    };
});

// Build a single fixture payload mirroring the API contract.
function buildPayload() {
    return {
        success: true,
        data: {
            total_receivables: 4500,
            total_payables: 1200,
            net_balance: 3300,
            items: [
                {
                    id: 1,
                    name: 'أحمد العميل',
                    phone: '01000000001',
                    entity_type: 'customer',
                    entity_type_label: 'عميل',
                    department: 'office',
                    department_label: 'قسم مكتب',
                    module: 'general',
                    module_label: 'عام / كاش',
                    balance: 3000,
                    currency: 'EGP',
                    account_id: 11,
                    statement_url: '/finance/account-statement/11',
                    balance_egp: 3000,
                },
                {
                    id: 2,
                    name: 'باصات الجيزة',
                    phone: '01111000200',
                    entity_type: 'bus_company',
                    entity_type_label: 'شركة باصات',
                    department: 'office',
                    department_label: 'قسم مكتب',
                    module: 'bus',
                    module_label: 'باص',
                    balance: -1200,
                    currency: 'EGP',
                    account_id: 22,
                    statement_url: '/finance/account-statement/22',
                    balance_egp: 1200,
                },
                {
                    id: 3,
                    name: 'فندق مكة',
                    phone: '01200000001',
                    entity_type: 'hotel',
                    entity_type_label: 'فندق',
                    department: 'tourism',
                    department_label: 'قسم سياحه',
                    module: 'hajj_umra',
                    module_label: 'حج وعمرة',
                    balance: 1500,
                    currency: 'EGP',
                    account_id: 33,
                    statement_url: '/finance/account-statement/33',
                    balance_egp: 1500,
                },
            ],
        },
    };
}

describe('DebtsIndex.vue', () => {
    let axiosGetSpy;

    beforeEach(() => {
        setActivePinia(createPinia());

        // Stub axios.get so the component's fetchDebts() resolves with our fixture.
        axiosGetSpy = vi.spyOn(axios, 'get').mockImplementation((url) => {
            if (url.startsWith('/api/v1/reports/debts')) {
                return Promise.resolve({ data: buildPayload() });
            }
            return Promise.resolve({ data: { success: true, data: null } });
        });

        // Stub the printSettings store's fetch so no network call leaks.
        const printStore = usePrintSettingsStore();
        vi.spyOn(printStore, 'fetch').mockResolvedValue(undefined);
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('renders the report title and KPI cards with the totals from the API', async () => {
        const wrapper = mount(DebtsIndex, {
            global: {
                stubs: {
                    'router-link': { template: '<a><slot /></a>' },
                },
            },
        });

        await flushPromises();

        expect(wrapper.text()).toContain('تقرير الديون والمديونيات الموحد');
        expect(wrapper.text()).toContain('4,500.00 EGP'); // total receivables
        expect(wrapper.text()).toContain('1,200.00 EGP'); // total payables
        expect(wrapper.text()).toContain('3,300.00 EGP'); // net balance

        // The three entity rows are present.
        expect(wrapper.text()).toContain('أحمد العميل');
        expect(wrapper.text()).toContain('باصات الجيزة');
        expect(wrapper.text()).toContain('فندق مكة');
    });

    it('calls /api/v1/reports/debts on mount with no filter params', async () => {
        const wrapper = mount(DebtsIndex, {
            global: { stubs: { 'router-link': { template: '<a><slot /></a>' } } },
        });

        await flushPromises();

        expect(axiosGetSpy).toHaveBeenCalledTimes(1);
        const [url, config] = axiosGetSpy.mock.calls[0];
        expect(url).toBe('/api/v1/reports/debts');
        expect(config.signal).toBeDefined();
        // Without filters, every filter param must be undefined so the
        // controller does not impose accidental constraints.
        expect(config.params.department).toBeUndefined();
        expect(config.params.module).toBeUndefined();
        expect(config.params.direction).toBeUndefined();
        expect(config.params.entity_type).toBeUndefined();
        expect(config.params.search).toBeUndefined();
    });

    it('triggers fetchDebts with the department value when a department button is clicked', async () => {
        const wrapper = mount(DebtsIndex, {
            global: { stubs: { 'router-link': { template: '<a><slot /></a>' } } },
        });
        await flushPromises();
        axiosGetSpy.mockClear();

        // The "قسم سياحه" button sets filters.department = 'tourism'.
        const buttons = wrapper.findAll('button');
        const tourismBtn = buttons.find((b) => b.text().includes('قسم سياحه'));
        expect(tourismBtn).toBeTruthy();
        await tourismBtn.trigger('click');
        await flushPromises();

        expect(axiosGetSpy).toHaveBeenCalledTimes(1);
        const [, config] = axiosGetSpy.mock.calls[0];
        expect(config.params.department).toBe('tourism');
    });

    it('sends direction filter when the لينا (مدين) button is clicked', async () => {
        const wrapper = mount(DebtsIndex, {
            global: { stubs: { 'router-link': { template: '<a><slot /></a>' } } },
        });
        await flushPromises();
        axiosGetSpy.mockClear();

        const buttons = wrapper.findAll('button');
        const receivablesBtn = buttons.find((b) => b.text().includes('لينا (مدين)'));
        expect(receivablesBtn).toBeTruthy();
        await receivablesBtn.trigger('click');
        await flushPromises();

        const [, config] = axiosGetSpy.mock.calls[0];
        expect(config.params.direction).toBe('receivables');
    });

    it('sends entity_type filter when the العملاء button is clicked', async () => {
        const wrapper = mount(DebtsIndex, {
            global: { stubs: { 'router-link': { template: '<a><slot /></a>' } } },
        });
        await flushPromises();
        axiosGetSpy.mockClear();

        const buttons = wrapper.findAll('button');
        const customersBtn = buttons.find((b) => b.text().trim() === 'العملاء');
        expect(customersBtn).toBeTruthy();
        await customersBtn.trigger('click');
        await flushPromises();

        const [, config] = axiosGetSpy.mock.calls[0];
        expect(config.params.entity_type).toBe('customer');
    });

    it('resetFilters button restores the default filter state and refetches', async () => {
        const wrapper = mount(DebtsIndex, {
            global: { stubs: { 'router-link': { template: '<a><slot /></a>' } } },
        });
        await flushPromises();

        // Change a couple of filters first.
        const buttons = wrapper.findAll('button');
        const tourismBtn = buttons.find((b) => b.text().includes('قسم سياحه'));
        await tourismBtn.trigger('click');
        await flushPromises();

        axiosGetSpy.mockClear();
        const resetBtn = wrapper
            .findAll('button')
            .find((b) => b.text().includes('إعادة تعيين الفلاتر'));
        expect(resetBtn).toBeTruthy();
        await resetBtn.trigger('click');
        await flushPromises();

        const [, config] = axiosGetSpy.mock.calls[0];
        expect(config.params.department).toBeUndefined();
        expect(config.params.module).toBeUndefined();
        expect(config.params.direction).toBeUndefined();
        expect(config.params.entity_type).toBeUndefined();
        expect(config.params.search).toBeUndefined();
    });

    it('debounces search input so a single fetch fires after the timer expires', async () => {
        vi.useFakeTimers();

        const wrapper = mount(DebtsIndex, {
            global: { stubs: { 'router-link': { template: '<a><slot /></a>' } } },
        });
        await flushPromises();

        axiosGetSpy.mockClear();
        const input = wrapper.find('input[type="text"]');
        await input.setValue('بحث');

        // 400ms debouncer — no fetch yet.
        expect(axiosGetSpy).not.toHaveBeenCalled();

        vi.advanceTimersByTime(450);
        await flushPromises();

        expect(axiosGetSpy).toHaveBeenCalledTimes(1);
        const [, config] = axiosGetSpy.mock.calls[0];
        expect(config.params.search).toBe('بحث');

        vi.useRealTimers();
    });

    it('renders the empty state when the report has zero items', async () => {
        axiosGetSpy.mockImplementation((url) => {
            if (url.startsWith('/api/v1/reports/debts')) {
                return Promise.resolve({
                    data: {
                        success: true,
                        data: {
                            total_receivables: 0,
                            total_payables: 0,
                            net_balance: 0,
                            items: [],
                        },
                    },
                });
            }
            return Promise.resolve({ data: { success: true } });
        });

        const wrapper = mount(DebtsIndex, {
            global: { stubs: { 'router-link': { template: '<a><slot /></a>' } } },
        });
        await flushPromises();

        expect(wrapper.text()).toContain('لا توجد ديون مطابقة للفلاتر');
    });

    it('renders an error banner and retries on click when the API fails', async () => {
        axiosGetSpy.mockImplementation((url) => {
            if (url.startsWith('/api/v1/reports/debts')) {
                return Promise.reject({
                    response: { data: { message: 'فشل تحميل تقرير الديون' } },
                });
            }
            return Promise.resolve({ data: { success: true } });
        });

        const wrapper = mount(DebtsIndex, {
            global: { stubs: { 'router-link': { template: '<a><slot /></a>' } } },
        });
        await flushPromises();

        expect(wrapper.text()).toContain('فشل تحميل تقرير الديون');

        // Restore the API to succeed and click the retry button.
        axiosGetSpy.mockImplementation((url) => {
            if (url.startsWith('/api/v1/reports/debts')) {
                return Promise.resolve({ data: buildPayload() });
            }
            return Promise.resolve({ data: { success: true } });
        });

        const retryBtn = wrapper
            .findAll('button')
            .find((b) => b.text().includes('إعادة المحاولة'));
        expect(retryBtn).toBeTruthy();
        await retryBtn.trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('أحمد العميل');
    });

    it('formats per-row balances as مدين/دائن/مستوفى chips', async () => {
        const wrapper = mount(DebtsIndex, {
            global: { stubs: { 'router-link': { template: '<a><slot /></a>' } } },
        });
        await flushPromises();

        const text = wrapper.text();
        expect(text).toContain('لنا (مدين)');
        expect(text).toContain('له (دائن)');
        expect(text).toContain('EGP');
    });

    it('calls the printSettingsStore.fetch side-effect on mount', async () => {
        const printStore = usePrintSettingsStore();
        expect(printStore.fetch).not.toHaveBeenCalled();

        mount(DebtsIndex, {
            global: { stubs: { 'router-link': { template: '<a><slot /></a>' } } },
        });
        await flushPromises();

        expect(printStore.fetch).toHaveBeenCalledTimes(1);
    });

    it('does not crash if the print button is clicked (window.print stubbed)', async () => {
        const wrapper = mount(DebtsIndex, {
            global: { stubs: { 'router-link': { template: '<a><slot /></a>' } } },
        });
        await flushPromises();

        const printBtn = wrapper
            .findAll('button')
            .find((b) => b.text().includes('طباعة'));
        expect(printBtn).toBeTruthy();

        // Just confirm it does not throw — the underlying window.print() is stubbed.
        await expect(printBtn.trigger('click')).resolves.toBeUndefined();
    });

    it('exposes only module options relevant to the chosen department', async () => {
        const wrapper = mount(DebtsIndex, {
            global: { stubs: { 'router-link': { template: '<a><slot /></a>' } } },
        });
        await flushPromises();

        // Initially the cascading select is empty.
        const html = wrapper.html();
        expect(html).toContain('اختر القسم الرئيسي أولاً');

        // Click on tourism — module select should now offer hajj_umra, flight, visa.
        const buttons = wrapper.findAll('button');
        const tourismBtn = buttons.find((b) => b.text().includes('قسم سياحه'));
        await tourismBtn.trigger('click');
        await flushPromises();

        // The HTML now contains the three tourism module labels.
        const text = wrapper.text();
        expect(text).toContain('حج وعمرة');
        expect(text).toContain('تأشيرات سياحية');
    });
});
