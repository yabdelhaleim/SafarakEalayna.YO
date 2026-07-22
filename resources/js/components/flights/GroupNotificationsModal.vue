<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70 backdrop-blur-sm"
      role="dialog"
      aria-modal="true"
      @click.self="onClose"
      @keydown.esc="onClose"
    >
      <div
        class="bg-card border border-white/10 rounded-2xl max-w-2xl w-full shadow-2xl animate-in fade-in zoom-in duration-200 overflow-hidden"
      >
        <!-- Header -->
        <header class="px-6 py-4 border-b border-white/10 flex items-center justify-between">
          <div class="flex items-center gap-3">
            <div class="rounded-xl bg-gold/15 p-2.5 text-gold">
              <Bell class="w-5 h-5" />
            </div>
            <div>
              <h3 class="text-lg font-black text-white">إعدادات الإشعارات</h3>
              <p class="text-xs text-text-muted mt-0.5">
                {{ group?.name || 'مجموعة طيران' }}
                <span v-if="group?.carrier" class="text-text-muted">
                  — {{ group.carrier.name }}
                </span>
              </p>
            </div>
          </div>
          <button
            type="button"
            class="p-1.5 rounded-lg hover:bg-white/5 text-text-muted"
            @click="onClose"
          >
            <X class="w-5 h-5" />
          </button>
        </header>

        <!-- Body -->
        <form v-if="form" class="px-6 py-5 space-y-5 max-h-[70vh] overflow-y-auto" @submit.prevent="onSubmit">
          <!-- Thresholds section -->
          <section>
            <div class="flex items-center gap-2 mb-3">
              <DollarSign class="w-4 h-4 text-gold" />
              <h4 class="text-sm font-bold text-white">عتبة الإشعار (عند انخفاض المتاح)</h4>
              <span class="text-xs text-text-muted">— اتركها 0 لتعطيل المستوى</span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
              <!-- Info -->
              <div
                class="rounded-xl border p-4 transition-colors"
                :class="form.notification_threshold_info > 0
                  ? 'border-info/40 bg-info/5'
                  : 'border-white/10 bg-white/[0.02]'"
              >
                <div class="flex items-center gap-2 mb-2">
                  <Info class="w-4 h-4 text-info" />
                  <span class="text-xs font-bold uppercase tracking-wider text-info">معلومة</span>
                </div>
                <label class="block text-xs text-text-muted mb-1">المبلغ</label>
                <div class="relative">
                  <input
                    v-model.number="form.notification_threshold_info"
                    type="number"
                    min="0"
                    step="0.01"
                    placeholder="10000"
                    class="w-full bg-input border border-white/10 rounded-lg px-3 py-2 text-sm font-mono text-white outline-none focus:border-info focus:ring-1 focus:ring-info/30"
                  />
                  <span class="absolute left-3 top-1/2 -translate-y-1/2 text-xs text-text-muted">
                    {{ currency }}
                  </span>
                </div>
                <p class="text-[11px] text-text-muted mt-2">إشعار مبكر — بداية الاقتراب</p>
              </div>

              <!-- Warning -->
              <div
                class="rounded-xl border p-4 transition-colors"
                :class="form.notification_threshold_warning > 0
                  ? 'border-warning/40 bg-warning/5'
                  : 'border-white/10 bg-white/[0.02]'"
              >
                <div class="flex items-center gap-2 mb-2">
                  <AlertTriangle class="w-4 h-4 text-warning" />
                  <span class="text-xs font-bold uppercase tracking-wider text-warning">تحذير</span>
                </div>
                <label class="block text-xs text-text-muted mb-1">المبلغ</label>
                <div class="relative">
                  <input
                    v-model.number="form.notification_threshold_warning"
                    type="number"
                    min="0"
                    step="0.01"
                    placeholder="5000"
                    class="w-full bg-input border border-white/10 rounded-lg px-3 py-2 text-sm font-mono text-white outline-none focus:border-warning focus:ring-1 focus:ring-warning/30"
                  />
                  <span class="absolute left-3 top-1/2 -translate-y-1/2 text-xs text-text-muted">
                    {{ currency }}
                  </span>
                </div>
                <p class="text-[11px] text-text-muted mt-2">تحتاج متابعة</p>
              </div>

              <!-- Danger -->
              <div
                class="rounded-xl border p-4 transition-colors"
                :class="form.notification_threshold_danger > 0
                  ? 'border-error/40 bg-error/5'
                  : 'border-white/10 bg-white/[0.02]'"
              >
                <div class="flex items-center gap-2 mb-2">
                  <ShieldAlert class="w-4 h-4 text-error" />
                  <span class="text-xs font-bold uppercase tracking-wider text-error">خطر</span>
                </div>
                <label class="block text-xs text-text-muted mb-1">المبلغ</label>
                <div class="relative">
                  <input
                    v-model.number="form.notification_threshold_danger"
                    type="number"
                    min="0"
                    step="0.01"
                    placeholder="1000"
                    class="w-full bg-input border border-white/10 rounded-lg px-3 py-2 text-sm font-mono text-white outline-none focus:border-error focus:ring-1 focus:ring-error/30"
                  />
                  <span class="absolute left-3 top-1/2 -translate-y-1/2 text-xs text-text-muted">
                    {{ currency }}
                  </span>
                </div>
                <p class="text-[11px] text-text-muted mt-2">تدخل فوري مطلوب</p>
              </div>
            </div>

            <p v-if="orderingWarning" class="mt-3 text-xs text-warning flex items-center gap-1.5">
              <AlertTriangle class="w-3.5 h-3.5" />
              {{ orderingWarning }}
            </p>
          </section>

          <!-- Channels section -->
          <section>
            <div class="flex items-center gap-2 mb-3">
              <Radio class="w-4 h-4 text-gold" />
              <h4 class="text-sm font-bold text-white">قنوات الإشعار</h4>
              <span class="text-xs text-text-muted">— اختار واحدة أو أكتر</span>
            </div>

            <div class="space-y-2.5">
              <label
                v-for="ch in channels"
                :key="ch.key"
                class="flex items-start gap-3 p-3 rounded-xl border border-white/10 hover:border-gold/40 hover:bg-white/[0.03] cursor-pointer transition-colors"
              >
                <input
                  v-model="form[ch.model]"
                  type="checkbox"
                  class="mt-1 w-4 h-4 rounded accent-gold cursor-pointer"
                />
                <div class="flex-1">
                  <div class="flex items-center gap-2">
                    <component :is="ch.icon" class="w-4 h-4" :class="ch.color" />
                    <span class="text-sm font-bold text-white">{{ ch.label }}</span>
                  </div>
                  <p class="text-xs text-text-muted mt-0.5">{{ ch.description }}</p>
                </div>
              </label>
            </div>
          </section>

          <!-- Last notification state -->
          <section v-if="group?.last_threshold_level" class="rounded-xl border border-white/10 bg-white/[0.02] p-4">
            <div class="flex items-center justify-between">
              <div class="flex items-center gap-2">
                <Activity class="w-4 h-4 text-text-muted" />
                <span class="text-xs font-bold text-text-muted uppercase tracking-wider">آخر إشعار</span>
              </div>
              <span
                class="text-xs font-bold px-2.5 py-1 rounded-full"
                :class="levelClass(group.last_threshold_level)"
              >
                {{ levelLabel(group.last_threshold_level) }}
              </span>
            </div>
            <p v-if="group.last_threshold_notified_at" class="text-xs text-text-muted mt-2">
              {{ formatRelative(group.last_threshold_notified_at) }}
            </p>
          </section>

          <!-- Footer -->
          <footer class="flex items-center justify-between gap-3 pt-2">
            <button
              type="button"
              class="px-4 py-2 rounded-xl text-sm font-bold text-text-muted hover:bg-white/5 transition-colors"
              @click="onClose"
            >
              إلغاء
            </button>
            <button
              type="submit"
              :disabled="saving"
              class="px-6 py-2 rounded-xl bg-gold text-black font-black text-sm hover:bg-gold/90 disabled:opacity-50 disabled:cursor-not-allowed transition-colors flex items-center gap-2"
            >
              <Loader2 v-if="saving" class="w-4 h-4 animate-spin" />
              <Save v-else class="w-4 h-4" />
              {{ saving ? 'جاري الحفظ...' : 'حفظ الإعدادات' }}
            </button>
          </footer>
        </form>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, watch, computed } from 'vue';
import {
  Bell, X, Save, Info, AlertTriangle, ShieldAlert,
  DollarSign, Radio, Activity, Loader2,
} from 'lucide-vue-next';
import { useFlightStore } from '@/stores/flightStore';

const props = defineProps({
  open: { type: Boolean, default: false },
  group: { type: Object, default: null },
});
const emit = defineEmits(['close', 'saved']);

const flightStore = useFlightStore();
const saving = ref(false);

const channels = [
  {
    key: 'notify_via_toast',
    model: 'notify_via_toast',
    label: 'Toast Popup فوري',
    description: 'يظهر مباشرة بعد كل حجز يقلل المتاح تحت العتبة',
    icon: computed(() => Loader2), // placeholder
    color: 'text-info',
  },
  {
    key: 'notify_via_widget',
    model: 'notify_via_widget',
    label: 'Dashboard Widget',
    description: 'يظهر في لوحة الطيران مع كل المجموعات النشطة',
    icon: computed(() => Activity),
    color: 'text-warning',
  },
  {
    key: 'notify_via_bell',
    model: 'notify_via_bell',
    label: 'In-App Notification 🔔',
    description: 'يصل في جرس الإشعارات لكل المديرين',
    icon: computed(() => Bell),
    color: 'text-error',
  },
];

const form = ref(null);

const currency = computed(() => {
  return (props.group?.carrier?.currency || 'EGP').toUpperCase();
});

const orderingWarning = computed(() => {
  if (!form.value) return null;
  const i = Number(form.value.notification_threshold_info || 0);
  const w = Number(form.value.notification_threshold_warning || 0);
  const d = Number(form.value.notification_threshold_danger || 0);
  if (i > 0 && w > 0 && i <= w) {
    return 'عتبة "معلومة" يجب أن تكون أكبر من عتبة "تحذير" (لأن المتاح يقل كلما زاد الحجز).';
  }
  if (w > 0 && d > 0 && w <= d) {
    return 'عتبة "تحذير" يجب أن تكون أكبر من عتبة "خطر".';
  }
  return null;
});

function levelLabel(level) {
  return { info: 'معلومة', warning: 'تحذير', danger: 'خطر' }[level] || level;
}

function levelClass(level) {
  return {
    info: 'bg-info/10 text-info border border-info/20',
    warning: 'bg-warning/10 text-warning border border-warning/20',
    danger: 'bg-error/10 text-error border border-error/20',
  }[level] || 'bg-white/10 text-text-muted';
}

function formatRelative(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  const diff = Math.round((Date.now() - d.getTime()) / 1000);
  if (diff < 60) return 'قبل لحظات';
  if (diff < 3600) return `قبل ${Math.floor(diff / 60)} دقيقة`;
  if (diff < 86400) return `قبل ${Math.floor(diff / 3600)} ساعة`;
  return d.toLocaleDateString('ar-EG');
}

function resetForm() {
  if (!props.group) return;
  form.value = {
    notification_threshold_info: props.group.notification_threshold_info ?? null,
    notification_threshold_warning: props.group.notification_threshold_warning ?? null,
    notification_threshold_danger: props.group.notification_threshold_danger ?? null,
    notify_via_toast: props.group.notify_via_toast ?? true,
    notify_via_widget: props.group.notify_via_widget ?? true,
    notify_via_bell: props.group.notify_via_bell ?? true,
  };
}

watch(() => props.open, (val) => {
  if (val) resetForm();
});

watch(() => props.group, () => {
  if (props.open) resetForm();
});

function onClose() {
  emit('close');
}

async function onSubmit() {
  if (!props.group) return;
  saving.value = true;
  try {
    const updated = await flightStore.updateGroupNotifications(props.group.id, {
      notification_threshold_info: form.value.notification_threshold_info || null,
      notification_threshold_warning: form.value.notification_threshold_warning || null,
      notification_threshold_danger: form.value.notification_threshold_danger || null,
      notify_via_toast: form.value.notify_via_toast,
      notify_via_widget: form.value.notify_via_widget,
      notify_via_bell: form.value.notify_via_bell,
    });
    emit('saved', updated);
    onClose();
  } catch (e) {
    // Toast already fired by store.
  } finally {
    saving.value = false;
  }
}
</script>