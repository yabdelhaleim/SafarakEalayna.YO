<script setup>
import { ref, computed, onMounted, reactive } from 'vue';
import { 
  Printer, 
  Save, 
  Building2, 
  Phone, 
  MapPin, 
  ToggleLeft, 
  ToggleRight, 
  Eye, 
  FileText, 
  CheckCircle,
  HelpCircle
} from 'lucide-vue-next';
import { usePrintSettingsStore } from '@/stores/printSettingsStore';
import { useAuthStore } from '@/stores/authStore';

const printStore = usePrintSettingsStore();
const authStore = useAuthStore();

const previewTab = ref('ticket'); // 'ticket' or 'invoice'

const form = reactive({
  company_name_ar: '',
  company_name_en: '',
  address: '',
  phones: '',
  finance_label: 'المالية والمحاسب',
  show_amount_due: true,
  modules: {},
});

const moduleRows = computed(() => {
  const options = printStore.moduleOptions?.length
    ? printStore.moduleOptions
    : Object.keys(form.modules || {}).map((key) => ({ key, label: key }));

  return options.map((opt) => ({
    key: opt.key,
    label: opt.label,
    ticket: Boolean(form.modules?.[opt.key]?.ticket),
    invoice: Boolean(form.modules?.[opt.key]?.invoice),
  }));
});

const toggleModule = (moduleKey, docType) => {
  if (!form.modules[moduleKey]) {
    form.modules[moduleKey] = { ticket: false, invoice: false };
  }
  form.modules[moduleKey][docType] = !form.modules[moduleKey][docType];
};

const toggleAllForDoc = (docType, value) => {
  moduleRows.value.forEach((row) => {
    if (!form.modules[row.key]) form.modules[row.key] = { ticket: false, invoice: false };
    form.modules[row.key][docType] = value;
  });
};

const hydrateForm = () => {
  const s = printStore.settings;
  form.company_name_ar = s.company_name_ar || '';
  form.company_name_en = s.company_name_en || '';
  form.address = s.address || '';
  form.phones = s.phones || '';
  form.finance_label = s.finance_label || 'المالية والمحاسب';
  form.show_amount_due = s.show_amount_due !== false;
  form.modules = JSON.parse(JSON.stringify(s.modules || {}));
};

const save = async () => {
  try {
    await printStore.save({ ...form });
    window.addToast?.('تم حفظ إعدادات الطباعة بنجاح', 'success');
  } catch (error) {
    console.error(error);
    window.addToast?.('فشل حفظ إعدادات الطباعة', 'error');
  }
};

onMounted(async () => {
  await printStore.fetch(true);
  hydrateForm();
});
</script>

<template>
  <div class="mx-auto max-w-7xl space-y-6">
    <!-- Hero Header Banner -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between bg-gradient-to-l from-slate-900 via-[#111e36] to-slate-900 p-6 rounded-3xl border border-white/5 relative overflow-hidden">
      <div class="absolute -right-20 -top-20 w-60 h-60 bg-sky-500/10 rounded-full blur-3xl pointer-events-none"></div>
      <div class="absolute -left-20 -bottom-20 w-60 h-60 bg-gold/5 rounded-full blur-3xl pointer-events-none"></div>
      
      <div class="relative z-10">
        <p class="text-xs font-bold uppercase tracking-[0.2em] text-sky-400">System Settings</p>
        <h1 class="mt-1 text-3xl font-black text-white tracking-tight">إعدادات الطباعة والمطبوعات</h1>
        <p class="mt-2 text-sm text-text-muted">
          قم بتخصيص بيانات الهوية، العناوين، أرقام التواصل، وتعيين شروط ظهور الشعار حسب موديولات النظام.
        </p>
      </div>
      <button
        v-if="authStore.isAdmin"
        type="button"
        class="btn-airline inline-flex items-center justify-center gap-2.5 px-6 py-3.5 text-sm font-black shadow-xl shadow-gold/15 transition-all hover:scale-[1.02] active:scale-[0.98] disabled:opacity-50 relative z-10 shrink-0"
        :disabled="printStore.saving"
        @click="save"
      >
        <Save class="h-4.5 w-4.5" />
        {{ printStore.saving ? 'جاري الحفظ...' : 'حفظ التعديلات' }}
      </button>
    </div>

    <!-- Read-Only Banner for Non-Admins -->
    <div v-if="!authStore.isAdmin" class="rounded-2xl border border-amber-500/30 bg-amber-500/10 p-4 text-sm text-amber-200 flex items-center gap-3">
      <HelpCircle class="h-5 w-5 text-amber-400 shrink-0" />
      <span>وضع العرض فقط — لا تملك صلاحية تعديل الإعدادات؛ التعديل متاح لمدير النظام فقط.</span>
    </div>

    <!-- Main Grid: Settings & Live Preview -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
      
      <!-- Right Side: Form Controls (7 cols) -->
      <div class="lg:col-span-7 space-y-6">
        
        <!-- Section 1: Company Profile -->
        <section class="flight-panel space-y-5">
          <h2 class="flight-panel__title flex items-center gap-2.5 text-white">
            <Building2 class="h-5 w-5 text-gold" />
            بيانات هوية الشركة والشعار
          </h2>

          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <label class="mb-2 block text-xs font-bold text-text-muted">اسم الشركة بالكامل (عربي)</label>
              <input
                v-model="form.company_name_ar"
                type="text"
                class="flight-input w-full"
                placeholder="سفرك علينا للسياحة"
                :disabled="!authStore.isAdmin"
              />
            </div>
            <div>
              <label class="mb-2 block text-xs font-bold text-text-muted">اسم الشركة بالكامل (English)</label>
              <input
                v-model="form.company_name_en"
                type="text"
                class="flight-input w-full"
                dir="ltr"
                placeholder="Safarak Ealayna Travel"
                :disabled="!authStore.isAdmin"
              />
            </div>
            <div class="sm:col-span-2">
              <label class="mb-2 flex items-center gap-1.5 text-xs font-bold text-text-muted">
                <Phone class="h-4 w-4 text-sky-400" />
                أرقام الهواتف (اكتب كل رقم في سطر منفصل)
              </label>
              <textarea
                v-model="form.phones"
                rows="3"
                class="flight-input w-full resize-none font-mono"
                placeholder="01234567890&#10;01112223344"
                :disabled="!authStore.isAdmin"
              ></textarea>
            </div>
            <div class="sm:col-span-2">
              <label class="mb-2 flex items-center gap-1.5 text-xs font-bold text-text-muted">
                <MapPin class="h-4 w-4 text-emerald-400" />
                عنوان المكتب بالتفصيل
              </label>
              <textarea
                v-model="form.address"
                rows="2"
                class="flight-input w-full resize-none"
                placeholder="القاهرة، جمهورية مصر العربية - الشارع الرئيسي، المبنى الإداري"
                :disabled="!authStore.isAdmin"
              ></textarea>
            </div>
          </div>
        </section>

        <!-- Section 2: Print Footer Settings -->
        <section class="flight-panel space-y-5">
          <h2 class="flight-panel__title flex items-center gap-2.5 text-white">
            <Printer class="h-5 w-5 text-gold" />
            خيارات تذييل المستندات
          </h2>
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <label class="mb-2 block text-xs font-bold text-text-muted">تسمية توقيع الإدارة / المالية</label>
              <input
                v-model="form.finance_label"
                type="text"
                class="flight-input w-full"
                placeholder="المالية والمحاسب"
                :disabled="!authStore.isAdmin"
              />
            </div>
            <div class="flex items-center">
              <label class="flex items-center gap-3 rounded-2xl border border-white/5 bg-white/[0.02] p-4 cursor-pointer hover:bg-white/[0.04] transition w-full">
                <input
                  v-model="form.show_amount_due"
                  type="checkbox"
                  class="h-5 w-5 rounded-lg border-white/20 bg-white/5 text-gold focus:ring-gold/30"
                  :disabled="!authStore.isAdmin"
                />
                <span class="text-sm text-text-muted leading-snug">إظهار كتل «المستحق المالي المتبقي علينا/لنا» في كشوف الحجوزات والسندات</span>
              </label>
            </div>
          </div>
        </section>

        <!-- Section 3: Module Level Visibility Control -->
        <section class="flight-panel space-y-4">
          <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <h2 class="flight-panel__title flex items-center gap-2.5 text-white">
                <ToggleRight class="h-5 w-5 text-gold" />
                تحكم ظهور الشعار والبيانات لكل موديول
              </h2>
              <p class="mt-1 text-xs text-text-muted">قم بتعيين ما إذا كان سيتم إرفاق هيدر/شعار الشركة عند طباعة الوثائق لكل قسم.</p>
            </div>
            <div v-if="authStore.isAdmin" class="flex flex-wrap gap-2">
              <button type="button" class="rounded-xl border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-text-muted hover:text-white transition" @click="toggleAllForDoc('ticket', true)">تفعيل كل التذاكر</button>
              <button type="button" class="rounded-xl border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-text-muted hover:text-white transition" @click="toggleAllForDoc('invoice', true)">تفعيل كل الفواتير</button>
            </div>
          </div>

          <div class="overflow-x-auto rounded-2xl border border-white/5 bg-white/[0.01]">
            <table class="min-w-full text-sm">
              <thead class="bg-white/[0.03] text-xs text-text-muted">
                <tr>
                  <th class="px-5 py-3.5 text-right font-bold">الموديول / القسم</th>
                  <th class="px-5 py-3.5 text-center font-bold">تذكرة سفر (Ticket)</th>
                  <th class="px-5 py-3.5 text-center font-bold">فاتورة / سند / كشف (Invoice)</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-white/5">
                <tr
                  v-for="row in moduleRows"
                  :key="row.key"
                  class="hover:bg-white/[0.02] transition-colors"
                >
                  <td class="px-5 py-4 font-bold text-white">{{ row.label }}</td>
                  <td class="px-5 py-4 text-center">
                    <button
                      type="button"
                      class="inline-flex items-center gap-1.5 rounded-xl px-3 py-2 text-xs font-bold transition-all"
                      :class="row.ticket ? 'bg-emerald-500/10 border border-emerald-500/20 text-emerald-400' : 'bg-white/5 border border-transparent text-text-muted/60'"
                      :disabled="!authStore.isAdmin"
                      @click="toggleModule(row.key, 'ticket')"
                    >
                      <component :is="row.ticket ? ToggleRight : ToggleLeft" class="h-4 w-4" />
                      {{ row.ticket ? 'مفعّل للمطبوعات' : 'مخفى من الوصل' }}
                    </button>
                  </td>
                  <td class="px-5 py-4 text-center">
                    <button
                      type="button"
                      class="inline-flex items-center gap-1.5 rounded-xl px-3 py-2 text-xs font-bold transition-all"
                      :class="row.invoice ? 'bg-sky-500/10 border border-sky-500/20 text-sky-300' : 'bg-white/5 border border-transparent text-text-muted/60'"
                      :disabled="!authStore.isAdmin"
                      @click="toggleModule(row.key, 'invoice')"
                    >
                      <component :is="row.invoice ? ToggleRight : ToggleLeft" class="h-4 w-4" />
                      {{ row.invoice ? 'مفعّل للمطبوعات' : 'مخفى من الوصل' }}
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

      </div>

      <!-- Left Side: Interactive Live Print Preview (5 cols) -->
      <div class="lg:col-span-5 lg:sticky lg:top-6 space-y-4">
        <div class="flight-panel">
          <div class="flex items-center justify-between mb-4">
            <h3 class="flight-panel__title !mb-0 flex items-center gap-2 text-white">
              <Eye class="h-5 w-5 text-gold" />
              معاينة حية للمطبوعات
            </h3>
            <!-- Tabs inside preview -->
            <div class="flex rounded-xl bg-white/5 p-1 border border-white/5 text-[11px] font-bold">
              <button 
                type="button"
                @click="previewTab = 'ticket'"
                :class="['px-3 py-1.5 rounded-lg transition-all', previewTab === 'ticket' ? 'bg-gold text-slate-950 shadow-md' : 'text-text-muted hover:text-white']"
              >
                رأس التذكرة
              </button>
              <button 
                type="button"
                @click="previewTab = 'invoice'"
                :class="['px-3 py-1.5 rounded-lg transition-all', previewTab === 'invoice' ? 'bg-gold text-slate-950 shadow-md' : 'text-text-muted hover:text-white']"
              >
                تذييل الفاتورة
              </button>
            </div>
          </div>
          
          <p class="text-xs text-text-muted mb-4 leading-relaxed">
            هذه معاينة تقريبية لما ستظهره طابعات المكتب ومستندات الـ PDF المصدرة للعملاء بناءً على البيانات المدخلة.
          </p>

          <!-- Mockup Paper Sheet -->
          <div class="rounded-2xl border border-slate-300 bg-white p-6 shadow-2xl text-slate-900 font-sans relative overflow-hidden transition-all duration-300">
            <!-- Decorative Sheet Grid Header -->
            <div class="absolute top-0 inset-x-0 h-1.5 bg-gradient-to-r from-sky-500 via-gold to-emerald-500"></div>
            
            <!-- Tab Content 1: Ticket Header -->
            <div v-if="previewTab === 'ticket'" class="space-y-6 animate-in fade-in duration-300">
              <div class="flex justify-between items-start gap-4">
                <div class="space-y-1">
                  <span class="inline-block bg-slate-100 text-slate-600 rounded text-[9px] font-bold px-2 py-0.5 uppercase tracking-wider">Electronic Voucher</span>
                  <h4 class="text-xs font-bold text-slate-400">وصل حجز إلكتروني</h4>
                  <div class="w-24 h-4 bg-slate-200 rounded animate-pulse mt-2"></div>
                </div>
                
                <!-- dynamic branding header -->
                <div class="text-right max-w-[60%] space-y-1">
                  <div v-if="form.company_name_en" class="text-base font-black uppercase text-slate-900 tracking-wide font-sans">{{ form.company_name_en }}</div>
                  <div v-if="form.company_name_ar" class="text-xs font-bold text-slate-600">{{ form.company_name_ar }}</div>
                  
                  <div class="space-y-0.5 pt-1.5">
                    <div 
                      v-for="(p, i) in (form.phones || '').split(/\r?\n|,/).map(x=>x.trim()).filter(Boolean)" 
                      :key="i" 
                      class="font-mono text-[10px] text-slate-500" 
                      dir="ltr"
                    >
                      📞 {{ p }}
                    </div>
                  </div>
                  <div v-if="form.address" class="text-[10px] text-slate-400 leading-tight pt-1 border-t border-slate-100 mt-1">{{ form.address }}</div>
                </div>
              </div>

              <!-- Ticket Simulated Barcode -->
              <div class="border-t-2 border-dashed border-slate-200 pt-6 flex flex-col items-center justify-center gap-2">
                <div class="w-full h-8 flex gap-1 justify-center items-center opacity-85">
                  <div v-for="w in [2,4,1,3,1,4,2,1,3,4,1,2,3,1,4,2,2,1]" :key="w" :style="`width:${w}px`" class="bg-slate-900 h-full"></div>
                </div>
                <span class="font-mono text-[9px] text-slate-400 tracking-widest">#SA-8874-2026</span>
              </div>
            </div>

            <!-- Tab Content 2: Invoice Footer -->
            <div v-if="previewTab === 'invoice'" class="space-y-6 animate-in fade-in duration-300">
              
              <!-- Mock Content Area -->
              <div class="space-y-2.5 opacity-40">
                <div class="h-3 bg-slate-200 rounded w-3/4"></div>
                <div class="h-3 bg-slate-200 rounded w-1/2"></div>
                <div class="h-10 bg-slate-100 rounded-lg"></div>
              </div>

              <!-- Balance Due Block -->
              <div 
                v-if="form.show_amount_due" 
                class="bg-rose-50 border border-rose-100 rounded-xl p-3.5 flex justify-between items-center transition-all animate-in slide-in-from-bottom-2 duration-300"
              >
                <div>
                  <span class="text-[9px] font-black text-rose-600 block uppercase tracking-wider">المستحق لنا / الرصيد</span>
                  <span class="font-mono text-sm font-black text-rose-700">1,500.00 ج.م</span>
                </div>
                <span class="text-[10px] bg-rose-100 text-rose-800 font-bold px-2.5 py-1 rounded-lg">قيد الانتظار</span>
              </div>

              <!-- Signature Footer Block -->
              <div class="border-t border-slate-200 pt-4 flex justify-between items-end gap-6">
                <!-- Administrative signature -->
                <div class="text-right">
                  <div class="text-[10px] font-black text-slate-700">{{ form.finance_label || 'المالية والمحاسب' }}</div>
                  <div class="mt-8 w-28 border-t border-slate-300"></div>
                  <span class="text-[9px] text-slate-400 block mt-1">التوقيع والختم</span>
                </div>

                <div class="text-center opacity-45">
                  <span class="inline-block border-2 border-slate-300 rounded-full w-14 h-14 flex items-center justify-center text-[9px] font-black text-slate-400 border-dashed">
                    STAMP
                  </span>
                </div>
              </div>
            </div>

          </div>
        </div>
      </div>

    </div>
  </div>
</template>

<style scoped>
.btn-airline {
  background: linear-gradient(135deg, #d4a843 0%, #b88a27 100%);
  color: #0c1524;
}
.btn-airline:hover:not(:disabled) {
  background: linear-gradient(135deg, #e5b954 0%, #c99b38 100%);
}
.text-gold {
  color: #fbbf24;
}
</style>
