import { defineStore } from 'pinia';
import axios from 'axios';

const defaultModules = () => ({
  flight: { ticket: true, invoice: true },
  bus: { ticket: true, invoice: true },
  hajj_umra: { ticket: true, invoice: true },
  visa: { ticket: true, invoice: true },
  online: { ticket: true, invoice: true },
  fawry: { ticket: true, invoice: true },
  wallet: { ticket: true, invoice: true },
  service: { ticket: true, invoice: true },
  general: { ticket: true, invoice: true },
});

export const usePrintSettingsStore = defineStore('printSettings', {
  state: () => ({
    loaded: false,
    loading: false,
    saving: false,
    settings: {
      company_name_ar: 'سفرك علينا',
      company_name_en: 'Safarak Ealayna',
      address: '',
      phones: '',
      finance_label: 'المالية والمحاسب',
      show_amount_due: true,
      modules: defaultModules(),
    },
    moduleOptions: [],
    documentOptions: [
      { key: 'ticket', label: 'تذكرة' },
      { key: 'invoice', label: 'فاتورة / سند / كشف' },
    ],
  }),

  getters: {
    phoneLines: (state) =>
      String(state.settings?.phones || '')
        .split(/\r?\n|,/)
        .map((p) => p.trim())
        .filter(Boolean),

    hasCompanyInfo: (state) =>
      Boolean(
        state.settings?.company_name_ar ||
          state.settings?.company_name_en ||
          state.settings?.address ||
          state.settings?.phones
      ),
  },

  actions: {
    shouldShow(module, documentType) {
      const modules = this.settings?.modules || {};
      return Boolean(modules?.[module]?.[documentType]);
    },

    async fetch(force = false) {
      if (this.loaded && !force) return this.settings;
      this.loading = true;
      try {
        const { data } = await axios.get('/api/v1/settings/print');
        const payload = data?.data || data;
        if (payload) {
          this.settings = {
            ...this.settings,
            ...payload,
            modules: { ...defaultModules(), ...(payload.modules || {}) },
          };
          this.moduleOptions = payload.module_options || this.moduleOptions;
          this.documentOptions = payload.document_options || this.documentOptions;
        }
        this.loaded = true;
        return this.settings;
      } finally {
        this.loading = false;
      }
    },

    async save(form) {
      this.saving = true;
      try {
        const { data } = await axios.put('/api/v1/settings/print', form);
        const payload = data?.data || data;
        if (payload) {
          this.settings = {
            ...this.settings,
            ...payload,
            modules: { ...defaultModules(), ...(payload.modules || {}) },
          };
        }
        this.loaded = true;
        return payload;
      } finally {
        this.saving = false;
      }
    },
  },
});
