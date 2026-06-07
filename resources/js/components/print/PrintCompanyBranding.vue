<script setup>
import { computed } from 'vue';
import { usePrintSettingsStore } from '@/stores/printSettingsStore';

const props = defineProps({
  module: { type: String, required: true },
  documentType: { type: String, required: true },
  position: { type: String, default: 'header' },
  variant: { type: String, default: 'light' },
  balanceDue: { type: [Number, String], default: null },
  balanceLabel: { type: String, default: 'المستحق لنا' },
});

const printStore = usePrintSettingsStore();

const visible = computed(() => printStore.shouldShow(props.module, props.documentType));
const settings = computed(() => printStore.settings || {});
const phoneLines = computed(() => printStore.phoneLines);

const showFooterBalance = computed(
  () =>
    props.position === 'footer' &&
    settings.value.show_amount_due &&
    props.balanceDue != null &&
    Number(props.balanceDue) > 0
);

const isDark = computed(() => props.variant === 'dark');
</script>

<template>
  <div v-if="visible && printStore.hasCompanyInfo" class="print-company-branding">
    <!-- Header: company identity -->
    <template v-if="position === 'header'">
      <div
        class="text-right leading-snug"
        :class="isDark ? 'text-sky-100' : 'text-slate-700'"
        :style="isDark ? 'text-align:right;' : 'text-align:right;'"
      >
        <div
          v-if="settings.company_name_en"
          class="font-black tracking-wide uppercase"
          :style="isDark ? 'font-size:18px;color:#d4a843;font-family:Segoe UI,Arial,sans-serif;' : 'font-size:16px;color:#0f172a;font-family:Segoe UI,Arial,sans-serif;'"
        >
          {{ settings.company_name_en }}
        </div>
        <div
          v-if="settings.company_name_ar"
          :style="isDark ? 'font-size:13px;font-weight:800;color:#bae6fd;margin-top:2px;' : 'font-size:13px;font-weight:800;color:#334155;margin-top:2px;'"
        >
          {{ settings.company_name_ar }}
        </div>
        <div
          v-for="(phone, idx) in phoneLines"
          :key="`phone-${idx}`"
          class="font-mono"
          :style="isDark ? 'font-size:11px;color:#93c5fd;margin-top:4px;direction:ltr;text-align:right;' : 'font-size:11px;color:#475569;margin-top:4px;direction:ltr;text-align:right;'"
          dir="ltr"
        >
          {{ phone }}
        </div>
        <div
          v-if="settings.address"
          :style="isDark ? 'font-size:11px;color:#93c5fd;margin-top:4px;line-height:1.5;' : 'font-size:11px;color:#475569;margin-top:4px;line-height:1.5;'"
        >
          {{ settings.address }}
        </div>
      </div>
    </template>

    <!-- Footer: finance signature + balance -->
    <template v-else-if="position === 'footer'">
      <div
        style="display:flex;justify-content:space-between;align-items:flex-end;gap:16px;margin-top:12px;padding-top:12px;border-top:1px dashed #cbd5e1;"
      >
        <div style="text-align:right;">
          <div style="font-size:11px;font-weight:800;color:#475569;">{{ settings.finance_label || 'المالية والمحاسب' }}</div>
          <div style="margin-top:28px;width:160px;border-top:1px solid #94a3b8;"></div>
          <div style="font-size:9px;color:#64748b;margin-top:4px;">توقيع</div>
        </div>
        <div v-if="showFooterBalance" style="text-align:left;">
          <div style="font-size:10px;font-weight:700;color:#64748b;letter-spacing:0.08em;text-transform:uppercase;">{{ balanceLabel }}</div>
          <div style="font-size:18px;font-weight:900;color:#dc2626;font-family:monospace;" dir="ltr">{{ balanceDue }}</div>
        </div>
      </div>
    </template>
  </div>
</template>
