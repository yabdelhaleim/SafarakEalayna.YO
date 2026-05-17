  <template>
  <div class="relative" ref="rootEl">
    <!-- Label -->
    <label v-if="label" class="block text-sm font-medium text-gray-300 mb-2">
      {{ label }} <span v-if="required" class="text-red-400">*</span>
    </label>

    <!-- Input -->
    <div class="relative">
      <Search class="absolute right-4 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400 pointer-events-none" />
      <input
        v-model="query"
        type="text"
        autocomplete="off"
        :placeholder="placeholder"
        :class="['flight-input pr-12', { 'border-sky-400/50 ring-1 ring-sky-400/20': isFocused }]"
        @input="onInput"
        @focus="onFocus"
        @keydown.down.prevent="moveDown"
        @keydown.up.prevent="moveUp"
        @keydown.enter.prevent="selectHighlighted"
        @keydown.escape="close"
      />
      <!-- Loading Spinner -->
      <Loader2 v-if="searching" class="absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 text-sky-400 animate-spin" />
    </div>

    <!-- Selected Badge -->
    <div v-if="modelValue" class="mt-2 inline-flex items-center gap-2 px-4 py-2 bg-emerald-500/10 border border-emerald-500/20 rounded-xl">
      <div class="flex items-center gap-2">
        <span class="font-mono font-bold text-emerald-400 text-sm">{{ modelValue.iata_code }}</span>
        <span class="text-gray-300 text-sm">{{ modelValue.city_name_ar || modelValue.city_name_en }}</span>
        <span class="text-xs text-gray-500">{{ modelValue.country_name_en }}</span>
      </div>
      <button @click.stop="clear" class="p-1 hover:bg-red-500/20 rounded-lg transition-colors ml-1">
        <X class="w-3.5 h-3.5 text-red-400" />
      </button>
    </div>

    <!-- No results message -->
    <p v-else-if="noResults && query.length >= 2" class="mt-1.5 text-xs text-amber-400/80 flex items-center gap-1.5">
      <AlertCircle class="w-3.5 h-3.5 shrink-0" />
      لا يوجد مطار يطابق «{{ query }}». جرّب رمز IATA أو اسم المدينة بالإنجليزية.
    </p>

    <!-- Dropdown -->
    <Transition
      enter-active-class="transition duration-150 ease-out"
      enter-from-class="opacity-0 scale-95 translate-y-1"
      enter-to-class="opacity-100 scale-100 translate-y-0"
      leave-active-class="transition duration-100 ease-in"
      leave-from-class="opacity-100 scale-100 translate-y-0"
      leave-to-class="opacity-0 scale-95 translate-y-1"
    >
      <div
        v-if="isOpen && results.length > 0"
        class="absolute z-50 w-full mt-1.5 bg-[#0e1824] border border-white/10 rounded-xl shadow-2xl max-h-72 overflow-y-auto origin-top"
        style="backdrop-filter: blur(20px);"
      >
        <!-- Global Search Source Badge -->
        <div class="px-4 py-2 border-b border-white/5 flex items-center justify-between">
          <span class="text-[10px] font-bold uppercase tracking-widest text-sky-400/60">
            {{ sourceLabel }}
          </span>
          <span class="text-[10px] text-white/30">{{ results.length }} مطار</span>
        </div>

        <div
          v-for="(airport, idx) in results"
          :key="airport.iata_code + idx"
          @mousedown.prevent.stop="select(airport)"
          @mouseenter="highlighted = idx"
          :class="[
            'p-3.5 cursor-pointer border-b border-white/5 last:border-0 transition-all duration-200',
            highlighted === idx ? 'bg-sky-500/15 pl-5' : 'hover:bg-white/5'
          ]"
        >
          <div class="flex items-center justify-between gap-3">
            <div class="min-w-0 flex-1">
              <div class="flex items-center gap-2">
                <span :class="['font-bold text-sm transition-colors', highlighted === idx ? 'text-sky-300' : 'text-white']">
                  {{ airport.city_name_ar || airport.city_name_en || airport.name }}
                </span>
                <span v-if="airport.country_name_en" class="text-[10px] text-white/30 px-1.5 py-0.5 border border-white/10 rounded uppercase">
                  {{ airport.country_name_en }}
                </span>
              </div>
              <div class="text-xs text-gray-400 mt-1 truncate flex items-center gap-1">
                <Plane class="w-3 h-3 text-gray-600" />
                {{ airport.airport_name_ar || airport.airport_name_en || airport.name }}
              </div>
            </div>
            <div :class="[
              'shrink-0 px-3 py-1 rounded-lg font-mono font-bold text-sm border transition-all',
              highlighted === idx 
                ? 'bg-sky-500/20 text-sky-300 border-sky-500/40 scale-110 shadow-lg shadow-sky-500/10' 
                : 'bg-amber-500/10 text-amber-400 border-amber-500/20'
            ]">
              {{ airport.iata_code }}
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<script setup>
import { ref, watch, onMounted, onUnmounted } from 'vue';
import { Search, X, Loader2, AlertCircle, Plane } from 'lucide-vue-next';

// ============================================================
// Props & Emits
// ============================================================
const props = defineProps({
  modelValue: { type: Object, default: null },
  label: { type: String, default: '' },
  placeholder: { type: String, default: 'اكتب اسم المدينة أو رمز IATA...' },
  required: { type: Boolean, default: false },
  excludeIata: { type: String, default: null },
});

const emit = defineEmits(['update:modelValue']);

// ============================================================
// State
// ============================================================
const query = ref('');
const results = ref([]);
const searching = ref(false);
const isOpen = ref(false);
const isFocused = ref(false);
const highlighted = ref(-1);
const noResults = ref(false);
const sourceLabel = ref('بحث عالمي');
const rootEl = ref(null);

let debounceTimer = null;

// ============================================================
// Global Airport Dataset (fetched once from CDN)
// ============================================================
let allAirports = null;
let loadingDataset = false;
let datasetLoaded = false;

const DATASET_URL = 'https://raw.githubusercontent.com/mwgg/Airports/master/airports.json';

// Fallback airports if external dataset fails
const FALLBACK_AIRPORTS = [
  { iata_code: 'CAI', city_name_en: 'Cairo', city_name_ar: 'القاهرة', airport_name_ar: 'مطار القاهرة الدولي', country_name_en: 'Egypt', country_code: 'EG' },
  { iata_code: 'JED', city_name_en: 'Jeddah', city_name_ar: 'جدة', airport_name_ar: 'مطار الملك عبد العزيز الدولي', country_name_en: 'Saudi Arabia', country_code: 'SA' },
  { iata_code: 'RUH', city_name_en: 'Riyadh', city_name_ar: 'الرياض', airport_name_ar: 'مطار الملك خالد الدولي', country_name_en: 'Saudi Arabia', country_code: 'SA' },
  { iata_code: 'DXB', city_name_en: 'Dubai', city_name_ar: 'دبي', airport_name_ar: 'مطار دبي الدولي', country_name_en: 'UAE', country_code: 'AE' },
  { iata_code: 'DOH', city_name_en: 'Doha', city_name_ar: 'الدوحة', airport_name_ar: 'مطار حمد الدولي', country_name_en: 'Qatar', country_code: 'QA' },
  { iata_code: 'KWI', city_name_en: 'Kuwait', city_name_ar: 'الكويت', airport_name_ar: 'مطار الكويت الدولي', country_name_en: 'Kuwait', country_code: 'KW' },
  { iata_code: 'IST', city_name_en: 'Istanbul', city_name_ar: 'اسطنبول', airport_name_ar: 'مطار اسطنبول', country_name_en: 'Turkey', country_code: 'TR' },
  { iata_code: 'LHR', city_name_en: 'London', city_name_ar: 'لندن', airport_name_ar: 'Heathrow Airport', country_name_en: 'UK', country_code: 'GB' },
  { iata_code: 'JFK', city_name_en: 'New York', city_name_ar: 'نيويورك', airport_name_ar: 'JFK Airport', country_name_en: 'USA', country_code: 'US' },
  { iata_code: 'MED', city_name_en: 'Medina', city_name_ar: 'المدينة المنورة', airport_name_ar: 'مطار الأمير محمد بن عبد العزيز', country_name_en: 'Saudi Arabia', country_code: 'SA' },
  { iata_code: 'HBE', city_name_en: 'Alexandria', city_name_ar: 'الإسكندرية', airport_name_ar: 'مطار برج العرب', country_name_en: 'Egypt', country_code: 'EG' },
  { iata_code: 'AMM', city_name_en: 'Amman', city_name_ar: 'عمان', airport_name_ar: 'مطار الملكة علياء', country_name_en: 'Jordan', country_code: 'JO' },
  { iata_code: 'BEY', city_name_en: 'Beirut', city_name_ar: 'بيروت', airport_name_ar: 'مطار رفيق الحريري', country_name_en: 'Lebanon', country_code: 'LB' },
  { iata_code: 'SHJ', city_name_en: 'Sharjah', city_name_ar: 'الشارقة', airport_name_ar: 'مطار الشارقة الدولي', country_name_en: 'UAE', country_code: 'AE' },
];

const loadDataset = async () => {
  if (datasetLoaded || loadingDataset) return;
  loadingDataset = true;
  
  try {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 8000); // 8s timeout

    const resp = await fetch(DATASET_URL, { signal: controller.signal });
    clearTimeout(timeoutId);

    const json = await resp.json();
    // Convert object keyed by ICAO to array, keep only those with IATA
    allAirports = Object.values(json)
      .filter(a => a.iata && a.iata.length === 3)
      .map(a => ({
        iata_code: a.iata,
        icao_code: a.icao || '',
        city_name_en: a.city || '',
        city_name_ar: '',
        airport_name_en: a.name || '',
        airport_name_ar: '',
        country_name_en: a.country || '',
        country_code: a.country || '',
        lat: a.lat,
        lon: a.lon,
        id: null,
        _external: true,
      }));
    
    datasetLoaded = true;
  } catch (err) {
    console.warn('Airport dataset load failed, using fallbacks', err);
    allAirports = FALLBACK_AIRPORTS;
    datasetLoaded = true;
  } finally {
    loadingDataset = false;
  }
};

// Preload on mount
onMounted(() => {
  loadDataset();
  document.addEventListener('click', onClickOutside);
});

onUnmounted(() => {
  document.removeEventListener('click', onClickOutside);
});

const onClickOutside = (e) => {
  if (rootEl.value && !rootEl.value.contains(e.target)) {
    close();
  }
};

// ============================================================
// Search Logic
// ============================================================
const search = async () => {
  const q = query.value.trim().toLowerCase();
  if (q.length < 2) {
    results.value = [];
    isOpen.value = false;
    noResults.value = false;
    return;
  }

  searching.value = true;
  noResults.value = false;

  // Wait for dataset
  if (!datasetLoaded) await loadDataset();

  const list = allAirports || [];
  const out = [];
  const qUpper = q.toUpperCase();

  // Priority 1: exact IATA match
  for (const a of list) {
    if (a.iata_code === qUpper) {
      if (props.excludeIata && a.iata_code === props.excludeIata) continue;
      out.push({ ...a, _score: 100 });
    }
  }

  // Priority 2: starts with IATA
  if (out.length < 3) {
    for (const a of list) {
      if (out.some(x => x.iata_code === a.iata_code)) continue;
      if (props.excludeIata && a.iata_code === props.excludeIata) continue;
      if (a.iata_code.startsWith(qUpper)) {
        out.push({ ...a, _score: 80 });
        if (out.length >= 10) break;
      }
    }
  }

  // Priority 3: city name starts with
  for (const a of list) {
    if (out.length >= 30) break;
    if (out.some(x => x.iata_code === a.iata_code)) continue;
    if (props.excludeIata && a.iata_code === props.excludeIata) continue;
    const city = (a.city_name_en || '').toLowerCase();
    if (city.startsWith(q)) {
      out.push({ ...a, _score: 70 });
    }
  }

  // Priority 4: contains anywhere
  for (const a of list) {
    if (out.length >= 50) break;
    if (out.some(x => x.iata_code === a.iata_code)) continue;
    if (props.excludeIata && a.iata_code === props.excludeIata) continue;
    const hay = [
      a.iata_code,
      a.city_name_en,
      a.airport_name_en,
      a.country_name_en,
      a.country_code,
    ].join(' ').toLowerCase();
    if (hay.includes(q)) {
      out.push({ ...a, _score: 50 });
    }
  }

  // Sort by score descending
  out.sort((a, b) => (b._score || 0) - (a._score || 0));

  results.value = out.slice(0, 50);
  sourceLabel.value = `بحث عالمي (${list.length.toLocaleString()} مطار)`;
  isOpen.value = results.value.length > 0;
  noResults.value = results.value.length === 0;
  highlighted.value = -1;
  searching.value = false;
};

const onInput = () => {
  if (!query.value.trim()) {
    results.value = [];
    isOpen.value = false;
    noResults.value = false;
    return;
  }
  clearTimeout(debounceTimer);
  searching.value = true;
  debounceTimer = setTimeout(search, 250);
};

const onFocus = () => {
  isFocused.value = true;
  if (!datasetLoaded) loadDataset();
  if (results.value.length > 0) isOpen.value = true;
};

const close = () => {
  isOpen.value = false;
  isFocused.value = false;
  highlighted.value = -1;
};

const select = (airport) => {
  emit('update:modelValue', airport);
  query.value = '';
  results.value = [];
  isOpen.value = false;
  highlighted.value = -1;
  noResults.value = false;
};

const clear = () => {
  emit('update:modelValue', null);
  query.value = '';
  results.value = [];
  isOpen.value = false;
  noResults.value = false;
};

const moveDown = () => {
  if (!isOpen.value) return;
  highlighted.value = Math.min(highlighted.value + 1, results.value.length - 1);
};

const moveUp = () => {
  if (!isOpen.value) return;
  highlighted.value = Math.max(highlighted.value - 1, 0);
};

const selectHighlighted = () => {
  if (highlighted.value >= 0 && results.value[highlighted.value]) {
    select(results.value[highlighted.value]);
  }
};
</script>
