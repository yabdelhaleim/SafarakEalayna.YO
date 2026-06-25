<template>
  <div class="max-w-5xl mx-auto space-y-8 animate-in fade-in slide-in-from-bottom-8 duration-700">
    <!-- Header -->
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-3xl font-extrabold text-white">إنشاء حجز حج/عمرة جديد</h1>
        <p class="text-muted mt-1">{{ steps[currentStep - 1].label }}</p>
      </div>
      <router-link :to="{ name: 'hajj.index' }" class="text-muted hover:text-white flex items-center gap-2 transition-colors">
        <ArrowLeft class="w-4 h-4" /> إلغاء
      </router-link>
    </div>

    <!-- Step Indicator -->
    <div class="relative px-4 py-8 bg-card border border-white/10 rounded-3xl shadow-2xl">
      <div class="flex justify-between relative z-10">
        <div v-for="step in steps" :key="step.id" class="flex flex-col items-center gap-3 flex-1 relative">
          <div v-if="step.id < steps.length"
            :class="['absolute top-5 left-1/2 w-full h-[2px] -z-10 transition-colors duration-500',
              currentStep > step.id ? 'bg-success' : 'bg-white/10']"></div>
          <div :class="[
            'w-10 h-10 rounded-full flex items-center justify-center transition-all duration-500 border-2',
            currentStep === step.id ? 'bg-gold border-gold text-black shadow-[0_0_20px_rgba(212,168,67,0.4)] scale-110' :
            currentStep > step.id ? 'bg-success border-success text-white' : 'bg-input border-white/10 text-muted'
          ]">
            <Check v-if="currentStep > step.id" class="w-5 h-5" />
            <span v-else class="font-bold">{{ step.id }}</span>
          </div>
          <span :class="['text-[10px] font-bold uppercase tracking-widest transition-colors duration-500',
            currentStep === step.id ? 'text-gold' : 'text-muted']">{{ step.title }}</span>
        </div>
      </div>
    </div>

    <!-- Step Content -->
    <div class="min-h-[400px]">
      <transition name="step" mode="out-in">
        <div :key="currentStep" class="space-y-8">
          <!-- Step 1: العميل -->
          <section v-if="currentStep === 1" class="space-y-6">
            <div class="max-w-2xl mx-auto">
              <h2 class="text-xl font-bold mb-2">لمن هذا الحجز؟</h2>
              <p class="text-muted text-sm mb-8">ابحث عن عميل أو أنشئ عميل جديد.</p>

              <div class="space-y-4">
                <div class="relative">
                  <Search class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted" />
                  <input v-model="customerSearch" type="text" placeholder="بحث بالاسم أو الهاتف..."
                    class="w-full pl-10 pr-4 py-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none text-white"
                    @input="onCustomerSearchDebounced"
                    @focus="showDropdown = true" />
                  
                  <!-- Absolute Dropdown for Search Results -->
                  <div v-if="showDropdown && (loadingCustomers || searchResults.length)" 
                    class="absolute z-50 left-0 right-0 mt-2 p-2 bg-[#1A1A1A] border border-white/10 rounded-xl shadow-2xl max-h-64 overflow-y-auto space-y-1">
                    <div v-if="loadingCustomers" class="flex items-center justify-center py-4">
                      <div class="animate-spin w-6 h-6 border-2 border-gold border-t-transparent rounded-full"></div>
                    </div>
                    <template v-else>
                      <button v-for="c in searchResults" :key="c.id" type="button" @click="selectCustomer(c)"
                        class="w-full p-3 rounded-lg text-right transition-all hover:bg-white/5 flex items-center justify-between">
                        <div>
                          <div class="font-bold text-white">{{ c.full_name || c.name }}</div>
                          <div class="text-xs text-muted font-mono">{{ c.phone }}</div>
                        </div>
                        <div v-if="form.customer?.id === c.id" class="text-gold font-bold text-xs">محدد</div>
                      </button>
                    </template>
                  </div>
                </div>

                <!-- Selected Customer Card -->
                <div v-if="form.customer" class="p-4 bg-gold/10 border border-gold/30 rounded-xl flex items-center justify-between animate-in fade-in duration-300">
                  <div>
                    <div class="text-xs text-muted mb-1">العميل المحدد للحجز:</div>
                    <div class="font-bold text-gold text-lg">{{ form.customer.full_name || form.customer.name }}</div>
                    <div class="text-sm text-muted font-mono">{{ form.customer.phone }}</div>
                  </div>
                  <button type="button" @click="form.customer = null" class="text-xs text-error hover:underline">إلغاء التحديد</button>
                </div>

                <button type="button" @click="showNewCustomerForm = !showNewCustomerForm"
                  class="w-full p-4 bg-white/5 border border-dashed border-white/20 rounded-xl text-muted hover:border-gold hover:text-gold transition-all flex items-center justify-center gap-2">
                  <Plus class="w-4 h-4" /> {{ showNewCustomerForm ? 'إخفاء النموذج' : 'إضافة عميل جديد' }}
                </button>

                <div v-if="showNewCustomerForm" class="p-4 bg-card border border-white/10 rounded-xl space-y-4">
                  <h3 class="font-bold text-white">عميل جديد</h3>
                  <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <input v-model="newCustomer.full_name" placeholder="الاسم الكامل *" class="p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none text-white" />
                    <input v-model="newCustomer.phone" placeholder="رقم الهاتف *" class="p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none text-white" />
                    <input v-model="newCustomer.national_id" placeholder="الرقم القومي *" maxlength="14" class="p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none text-white" />
                    <input v-model="newCustomer.travel_country" placeholder="دولة السفر *" class="p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none text-white" />
                    <input v-model="newCustomer.passport_number" placeholder="رقم الجواز (اختياري)" class="p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none text-white" />
                    <input v-model="newCustomer.date_of_birth" type="date" placeholder="تاريخ الميلاد (اختياري)" class="p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none text-white" />
                  </div>
                  <button type="button" @click="createNewCustomer" class="w-full py-2 bg-gold text-black rounded-xl font-bold hover:bg-gold/90">
                    حفظ العميل
                  </button>
                </div>
              </div>
            </div>
          </section>

          <!-- Step 2: البرنامج -->
          <section v-if="currentStep === 2" class="space-y-6">
            <div class="max-w-3xl mx-auto">
              <h2 class="text-xl font-bold mb-2">اختر البرنامج</h2>
              <p class="text-muted text-sm mb-8">البرامج تُدار عبر لوحة Filament وتُجلب آلياً.</p>

              <select v-model="form.program_id" @change="onProgramSelect"
                class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none cursor-pointer text-white">
                <option :value="null">اختر برنامج...</option>
                <option v-for="p in store.programs" :key="p.id" :value="p.id">
                  {{ p.program_name }} — {{ p.program_type === 'hajj' ? 'حج' : 'عمرة' }} ({{ p.total_nights }} ليلة)
                </option>
              </select>

              <div v-if="!store.programs.length" class="mt-4 p-5 bg-warning/10 border border-warning/30 rounded-xl text-warning text-sm space-y-3">
                <div class="font-bold flex items-center gap-2">
                  <span>⚠</span> لا توجد برامج حج/عمرة مفعّلة في النظام
                </div>
                <p class="text-warning/80">يجب أولاً إنشاء برنامج من لوحة التحكم الإدارية قبل إتمام الحجز.</p>
                <a href="/admin/programs/create" target="_blank"
                  class="inline-flex items-center gap-2 px-4 py-2 bg-warning/20 hover:bg-warning/30 border border-warning/40 rounded-lg font-bold text-warning transition-colors">
                  ➕ إنشاء برنامج جديد من لوحة الإدارة
                </a>
              </div>

              <div v-if="selectedProgram" class="mt-6 p-6 bg-card border border-white/10 rounded-2xl space-y-4">
                <h3 class="font-bold text-gold">{{ selectedProgram.program_name }}</h3>
                <div class="grid grid-cols-2 gap-4 text-sm">
                  <Field label="النوع" :value="selectedProgram.program_type === 'hajj' ? '🕋 حج' : '🕋 عمرة'" />
                  <Field label="المدة" :value="`${selectedProgram.total_nights} ليلة`" />
                  <Field label="فندق مكة" :value="selectedProgram.mecca_hotel_name" />
                  <Field label="ليالي مكة" :value="selectedProgram.mecca_nights" />
                  <Field v-if="selectedProgram.medina_hotel_name" label="فندق المدينة" :value="selectedProgram.medina_hotel_name" />
                  <Field v-if="selectedProgram.medina_nights" label="ليالي المدينة" :value="selectedProgram.medina_nights" />
                  <Field label="الطيران" :value="selectedProgram.airline" />
                  <Field label="الشركة المنفذة" :value="selectedProgram.executing_company_label || selectedProgram.executing_company" />
                  <Field label="مشرف الرحلة" :value="selectedProgram.trip_supervisor_label || selectedProgram.trip_supervisor" />
                  <Field label="نوع التسكين" :value="selectedProgram.accommodation_label || selectedProgram.accommodation_type" />
                  <Field v-if="selectedProgram.departure_date" label="تاريخ السفر" :value="selectedProgram.departure_date" />
                  <Field v-if="selectedProgram.return_date" label="تاريخ العودة" :value="selectedProgram.return_date" />
                </div>
              </div>
            </div>
          </section>

          <!-- Step 3: المرافق -->
          <section v-if="currentStep === 3" class="space-y-6">
            <div class="max-w-2xl mx-auto">
              <h2 class="text-xl font-bold mb-2">مرافق الحجز</h2>
              <p class="text-muted text-sm mb-8">اختياري — أضف مرافقاً لهذا الحجز مع تسعير منفصل له.</p>

              <label class="flex items-center gap-3 p-4 bg-input border border-white/10 rounded-xl cursor-pointer hover:border-gold/50">
                <input v-model="needsCompanion" type="checkbox" class="w-5 h-5" />
                <div>
                  <div class="font-bold text-white">يحتاج مرافق</div>
                  <div class="text-sm text-muted">تفعيل إضافة مرافق وتحديد تكاليفه المستقلة</div>
                </div>
              </label>

              <div v-if="needsCompanion" class="space-y-4 mt-4">
                <div class="relative">
                  <Search class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted" />
                  <input v-model="companionSearch" type="text" placeholder="بحث عن المرافق بالاسم أو الهاتف..."
                    class="w-full pl-10 pr-4 py-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none text-white"
                    @input="onCompanionSearchDebounced"
                    @focus="showCompanionDropdown = true" />
                  
                  <!-- Absolute Dropdown for Companion Search Results -->
                  <div v-if="showCompanionDropdown && (loadingCompanion || companionSearchResults.length)" 
                    class="absolute z-50 left-0 right-0 mt-2 p-2 bg-[#1A1A1A] border border-white/10 rounded-xl shadow-2xl max-h-64 overflow-y-auto space-y-1">
                    <div v-if="loadingCompanion" class="flex items-center justify-center py-4">
                      <div class="animate-spin w-6 h-6 border-2 border-gold border-t-transparent rounded-full"></div>
                    </div>
                    <template v-else>
                      <button v-for="c in companionSearchResults" :key="c.id" type="button" @click="selectCompanion(c)"
                        class="w-full p-3 rounded-lg text-right transition-all hover:bg-white/5 flex items-center justify-between">
                        <div>
                          <div class="font-bold text-white">{{ c.full_name || c.name }}</div>
                          <div class="text-xs text-muted font-mono">{{ c.phone }}</div>
                        </div>
                        <div v-if="form.companion_customer_id === c.id" class="text-gold font-bold text-xs">محدد</div>
                      </button>
                    </template>
                  </div>
                </div>

                <!-- Card for Selected Companion and Price Inputs -->
                <div v-if="form.companion_customer_id" class="space-y-4 animate-in fade-in duration-300">
                  <div class="p-4 bg-gold/10 border border-gold/30 rounded-xl flex items-center justify-between">
                    <div>
                      <div class="text-xs text-muted mb-1">المرافق المحدد:</div>
                      <div class="font-bold text-gold text-lg">{{ companionName }}</div>
                    </div>
                    <button type="button" @click="clearCompanion" class="text-xs text-error hover:underline">إلغاء المرافق</button>
                  </div>

                  <div class="grid grid-cols-1 md:grid-cols-2 gap-4 p-5 bg-card border border-white/10 rounded-2xl">
                    <h3 class="col-span-full font-bold text-sm text-gold">تسعير المرافق الخاص</h3>
                    <div>
                      <label class="block text-xs text-muted mb-2 uppercase tracking-widest">سعر شراء المرافق (التكلفة)</label>
                      <input v-model.number="form.companion_purchase_price" type="number" min="0" step="0.01"
                        class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none font-mono text-white" />
                    </div>
                    <div>
                      <label class="block text-xs text-muted mb-2 uppercase tracking-widest">سعر بيع المرافق</label>
                      <input v-model.number="form.companion_selling_price" type="number" min="0" step="0.01"
                        class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none font-mono text-white" />
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </section>

          <!-- Step 4: التسعير -->
          <section v-if="currentStep === 4" class="space-y-6">
            <div class="max-w-2xl mx-auto space-y-6">
              <h2 class="text-xl font-bold text-center">التسعير وتفاصيل السكن والأسرة</h2>

              <!-- Supplier and purchase cost -->
              <div class="p-5 bg-card border border-white/10 rounded-2xl space-y-4">
                <h3 class="font-bold text-sm text-gold">بيانات المورِّد وتكلفة البرنامج الأساسي</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <label class="block text-xs text-muted mb-2">المورِّد (وكيل / شركة)</label>
                    <select v-model="form.supplier_id" @change="onSupplierSelect"
                      class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none cursor-pointer text-white">
                      <option :value="null">
                        الشركة المنفذة للبرنامج: {{ selectedProgram?.executing_company_label || selectedProgram?.executing_company || 'غير محددة' }}
                      </option>
                      <option v-for="s in store.suppliers" :key="s.id" :value="s.id">
                        {{ s.name }}
                      </option>
                    </select>
                    <div v-if="selectedProgram" class="text-[10px] text-muted mt-1.5 flex items-center gap-1">
                      <span>الشركة المنفذة للبرنامج:</span>
                      <span class="text-gold font-bold">{{ selectedProgram.executing_company_label || selectedProgram.executing_company || 'غير محددة' }}</span>
                    </div>
                  </div>
                  <div>
                    <label class="block text-xs text-muted mb-2">سعر شراء البرنامج (التكلفة)</label>
                    <input v-model.number="form.purchase_price" type="number" min="0" step="0.01"
                      class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none font-mono text-white" />
                  </div>
                </div>
              </div>

              <!-- Family pricing grid -->
              <div class="p-5 bg-card border border-white/10 rounded-2xl space-y-4">
                <h3 class="font-bold text-sm text-gold">شبكة تسعير الأسرة (حسب الفئة)</h3>
                <p class="text-xs text-muted">يمكنك إدخال الأعداد والأسعار الفرعية لتحديث سعر البيع الأساسي تلقائياً.</p>
                
                <div class="overflow-x-auto">
                  <table class="w-full text-right border-collapse text-xs">
                    <thead>
                      <tr class="border-b border-white/10 text-muted">
                        <th class="pb-2 font-bold">الفئة</th>
                        <th class="pb-2 font-bold w-20 text-center">العدد</th>
                        <th class="pb-2 font-bold w-28 text-center">سعر الفرد للبيع</th>
                        <th class="pb-2 font-bold w-28 text-left">الإجمالي الفرعي</th>
                      </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                      <tr v-for="p in passengers" :key="p.category">
                        <td class="py-2.5 font-medium text-white">{{ p.label }}</td>
                        <td class="py-2.5 text-center">
                          <input v-model.number="p.count" type="number" min="0"
                            class="w-14 p-1.5 bg-input border border-white/10 rounded-lg text-center focus:border-gold outline-none text-white font-mono"
                            @input="updatePassengerSubtotal(p)" />
                        </td>
                        <td class="py-2.5 text-center">
                          <input v-model.number="p.unit_price" type="number" min="0" step="0.01"
                            class="w-24 p-1.5 bg-input border border-white/10 rounded-lg text-center focus:border-gold outline-none text-white font-mono"
                            @input="updatePassengerSubtotal(p)" />
                        </td>
                        <td class="py-2.5 text-left font-mono font-bold text-gold">
                          {{ formatMoney(p.subtotal) }}
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>

                <div class="flex justify-between items-center pt-2 border-t border-white/10 font-bold">
                  <span class="text-xs text-muted font-bold">إجمالي شبكة الأسرة:</span>
                  <span class="text-sm text-gold font-mono">{{ formatMoney(passengersTotal) }}</span>
                </div>
              </div>

              <!-- Room options -->
              <div class="p-5 bg-card border border-white/10 rounded-2xl space-y-4">
                <h3 class="font-bold text-sm text-gold">خيارات السكن والتسكين</h3>
                
                <div class="grid grid-cols-2 gap-4">
                  <label class="flex items-center gap-3 p-3 bg-input border border-white/10 rounded-xl cursor-pointer hover:border-gold/50"
                    :class="{ 'border-gold bg-gold/5': form.accommodation_choice === 'standard' }">
                    <input type="radio" v-model="form.accommodation_choice" value="standard" class="w-4 h-4 text-gold" @change="onAccommodationChange" />
                    <div>
                      <div class="font-bold text-xs text-white">تسكين عادي (مشترك)</div>
                      <div class="text-[10px] text-muted">تسكين قياسي مدرج بالبرنامج</div>
                    </div>
                  </label>

                  <label class="flex items-center gap-3 p-3 bg-input border border-white/10 rounded-xl cursor-pointer hover:border-gold/50"
                    :class="{ 'border-gold bg-gold/5': form.accommodation_choice === 'private' }">
                    <input type="radio" v-model="form.accommodation_choice" value="private" class="w-4 h-4 text-gold" @change="onAccommodationChange" />
                    <div>
                      <div class="font-bold text-xs text-white">غرفة خاصة</div>
                      <div class="text-[10px] text-muted">إضافة كلفة سكن خاص (+3,000 EGP)</div>
                    </div>
                  </label>
                </div>

                <div v-if="form.accommodation_choice === 'private'" class="animate-in fade-in duration-300">
                  <label class="block text-xs text-muted mb-2 uppercase tracking-widest">رسوم السكن الخاص الإضافية</label>
                  <input v-model.number="form.accommodation_extra_charge" type="number" min="0" step="0.01"
                    class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none font-mono text-white" />
                </div>
              </div>

              <!-- Final selling price input -->
              <div class="p-5 bg-card border border-white/10 rounded-2xl space-y-4">
                <h3 class="font-bold text-sm text-gold">تحديد سعر البيع النهائي</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <label class="block text-xs text-muted mb-2">سعر البيع للبرنامج الأساسي</label>
                    <input v-model.number="form.selling_price" type="number" min="0" step="0.01"
                      class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none font-mono text-white" />
                  </div>
                  <div class="flex flex-col justify-end">
                    <!-- Quick markup -->
                    <label class="block text-xs text-muted mb-2">هامش سريع على التكلفة الأساسية</label>
                    <div class="flex gap-2">
                      <button v-for="percent in [20, 30, 50, 100]" :key="percent" type="button" @click="applyMarkup(percent)"
                        class="flex-1 py-2 rounded-xl bg-white/5 hover:bg-gold/20 border border-white/10 text-xs transition-all text-white font-bold">
                        +{{ percent }}%
                      </button>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Total profit/loss widget -->
              <div class="p-6 bg-card border border-white/10 rounded-2xl flex items-center justify-between">
                <div>
                  <div class="text-xs text-muted uppercase tracking-widest font-bold">الربح الإجمالي المتوقع للحجز</div>
                  <div class="text-2xl font-bold font-mono mt-1" :class="profitClass">
                    {{ formatMoney(profit) }}
                  </div>
                  <div class="text-[10px] text-muted mt-1 space-y-0.5">
                    <div>إجمالي البيع: {{ formatMoney(totalSellingPrice) }} | إجمالي الشراء: {{ formatMoney(totalPurchasePrice) }}</div>
                    <div v-if="marginPct !== null">نسبة الربحية الإجمالية: {{ marginPct }}%</div>
                  </div>
                </div>
                <div :class="['w-12 h-12 rounded-full flex items-center justify-center',
                  profit >= 0 ? 'bg-success/20 text-success' : 'bg-error/20 text-error']">
                  <TrendingUp v-if="profit >= 0" class="w-6 h-6" />
                  <TrendingDown v-else class="w-6 h-6" />
                </div>
              </div>

              <label class="flex items-center gap-3 p-4 bg-input border border-white/10 rounded-xl">
                <input v-model="form.per_person" type="checkbox" class="w-5 h-5" />
                <div>
                  <div class="font-bold text-white">السعر للفرد</div>
                  <div class="text-sm text-muted">السعر يُحسب لكل شخص على حدة</div>
                </div>
              </label>
            </div>
          </section>

          <!-- Step 5: الحساب والدفع -->
          <section v-if="currentStep === 5" class="space-y-6">
            <div class="max-w-2xl mx-auto space-y-6">
              <h2 class="text-xl font-bold mb-2">حساب التسوية والدفع الأولي</h2>
              <p class="text-muted text-sm">اختر الحساب الذي ستُسجَّل فيه القيود (إيراد البيع ومصروف التكلفة).</p>

              <div>
                <label class="block text-sm font-semibold text-white mb-2">نوع حساب التحصيل</label>
                <div class="flex flex-wrap gap-2 mb-4" dir="rtl">
                  <button
                    v-for="chip in settlementCategoryChips"
                    :key="chip.id"
                    type="button"
                    @click="settlementCategoryUi = chip.id"
                    :class="[
                      'flex items-center gap-2 px-3 py-2 rounded-xl border transition-all text-xs font-bold',
                      settlementCategoryUi === chip.id
                        ? 'bg-white/10 border-gold text-gold'
                        : 'bg-white/[0.02] border-white/10 text-text-muted hover:border-white/20'
                    ]"
                  >
                    <component :is="chip.icon" :class="['h-3.5 w-3.5', chip.iconClass]" />
                    {{ chip.label }}
                  </button>
                </div>

                <label class="block text-xs text-muted mb-2 uppercase tracking-widest">حساب التسوية</label>
                <select v-model="form.account_id"
                  class="w-full p-4 bg-input border border-white/10 rounded-xl focus:border-gold outline-none text-white">
                  <option :value="null">اختر الحساب...</option>
                  <option v-for="a in filteredAccounts" :key="a.id" :value="a.id">
                    {{ a.name }} ({{ accountTypeLabel(a) }}) — {{ formatMoney(a.balance, a.currency || 'EGP') }}
                  </option>
                </select>
                <p v-if="filteredAccounts.length === 0" class="text-xs text-warning mt-2">
                  لا توجد حسابات حج وعمرة في هذا التصنيف — أنشئ حساباً من Filament (موديول: الحج والعمرة).
                </p>
                <p class="text-xs text-muted mt-2">الحسابات تُدار من Filament &gt; الحسابات.</p>
              </div>

              <label class="flex items-center gap-3 p-4 bg-input border border-white/10 rounded-xl">
                <input v-model="addPayment" type="checkbox" class="w-5 h-5" />
                <div>
                  <div class="font-bold text-white">تسجيل دفعة أولية الآن؟</div>
                  <div class="text-sm text-muted">يمكنك تأجيلها وإضافتها لاحقاً من صفحة الحجز.</div>
                </div>
              </label>

              <div v-if="addPayment" class="space-y-4 p-6 bg-card border border-white/10 rounded-2xl">
                <div>
                  <label class="block text-xs text-muted mb-2 uppercase tracking-widest">المبلغ المدفوع الآن</label>
                  <input v-model.number="form.initial_payment.amount" type="number" min="0" step="0.01"
                    class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none font-mono text-white" />
                  <div class="flex flex-wrap gap-2 mt-3">
                    <button v-for="percent in [20, 25, 50, 75, 100]" :key="percent" type="button" @click="setPaidPercent(percent)"
                      class="px-3 py-1.5 rounded-lg bg-white/5 hover:bg-gold/20 border border-white/10 text-xs text-white">
                      {{ percent }}% من سعر البيع
                    </button>
                    <button type="button" @click="form.initial_payment.amount = 0"
                      class="px-3 py-1.5 rounded-lg bg-error/10 hover:bg-error/20 border border-error/30 text-xs text-error">
                      تصفير
                    </button>
                  </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <label class="block text-xs text-muted mb-2">طريقة الدفع</label>
                    <select v-model="form.initial_payment.payment_method"
                      class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none text-white">
                      <option value="cash">نقدي</option>
                      <option value="bank_transfer">تحويل بنكي</option>
                      <option value="wallet">محفظة إلكترونية</option>
                      <option value="postal">بريد</option>
                      <option value="other">أخرى</option>
                    </select>
                  </div>
                  <div>
                    <label class="block text-xs text-muted mb-2">تاريخ الدفع</label>
                    <input v-model="form.initial_payment.payment_date" type="date"
                      class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none text-white" />
                  </div>
                  <div>
                    <label class="block text-xs text-muted mb-2">رقم المرجع (اختياري)</label>
                    <input v-model="form.initial_payment.reference" type="text"
                      class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none text-white" />
                  </div>
                  <div>
                    <label class="block text-xs text-muted mb-2">المدفوع بواسطة</label>
                    <input v-model="form.initial_payment.paid_by" type="text"
                      class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none text-white" />
                  </div>
                </div>
              </div>

              <div>
                <label class="block text-xs text-muted mb-2 uppercase tracking-widest">اسم الموظف القائم بالحجز</label>
                <input v-model="form.agent_name" type="text"
                  class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none text-white"
                  placeholder="اسم الموظف" />
              </div>

              <div>
                <label class="block text-xs text-muted mb-2 uppercase tracking-widest">ملاحظات</label>
                <textarea v-model="form.notes" rows="2"
                  class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none text-white"></textarea>
              </div>
            </div>
          </section>

          <!-- Step 6: مراجعة -->
          <section v-if="currentStep === 6" class="space-y-6">
            <div class="max-w-2xl mx-auto space-y-6 p-6 bg-card border border-white/10 rounded-2xl">
              <h2 class="text-xl font-bold text-center text-gold">مراجعة بيانات وتفاصيل الحجز</h2>
              
              <div class="space-y-3">
                <h3 class="font-bold text-sm text-gold border-b border-white/10 pb-1">البيانات العامة</h3>
                <Row label="العميل الأساسي" :value="form.customer?.full_name || form.customer?.name" />
                <Row label="البرنامج المختار" :value="selectedProgram?.program_name" />
                <Row v-if="selectedProgram" label="الشركة المنفذة للبرنامج" :value="selectedProgram.executing_company_label || selectedProgram.executing_company || 'غير محددة'" />
                <Row v-if="form.supplier_id" label="المورِّد (الشركة الموردة)" :value="supplierName" />
                <Row v-if="form.companion_customer_id" label="المرافق" :value="companionName" />
                <Row label="خيار السكن" :value="form.accommodation_choice === 'private' ? 'غرفة خاصة' : 'تسكين عادي'" />
              </div>

              <!-- Pricing breakdown -->
              <div class="space-y-3 pt-4 border-t border-white/10">
                <h3 class="font-bold text-sm text-gold border-b border-white/10 pb-1">تفاصيل الحسابات والتسعير</h3>
                <Row label="سعر بيع البرنامج للعميل" :value="formatMoney(form.selling_price)" />
                <Row v-if="needsCompanion && form.companion_customer_id" label="سعر بيع البرنامج للمرافق" :value="formatMoney(form.companion_selling_price)" />
                <Row v-if="form.accommodation_extra_charge > 0" label="رسوم السكن الخاص الإضافية" :value="formatMoney(form.accommodation_extra_charge)" />
                <div class="flex justify-between items-center py-2 bg-white/5 px-3 rounded-lg">
                  <span class="font-bold text-white text-sm">إجمالي سعر البيع الكلي:</span>
                  <span class="font-bold font-mono text-gold">{{ formatMoney(totalSellingPrice) }}</span>
                </div>

                <div class="space-y-2 mt-4 pt-4 border-t border-white/10">
                  <Row label="سعر شراء البرنامج (التكلفة)" :value="formatMoney(form.purchase_price)" />
                  <Row v-if="needsCompanion && form.companion_customer_id" label="سعر شراء برنامج المرافق" :value="formatMoney(form.companion_purchase_price)" />
                  <div class="flex justify-between items-center py-2 bg-white/5 px-3 rounded-lg">
                    <span class="text-muted text-sm">إجمالي التكلفة الكلية:</span>
                    <span class="font-mono text-white">{{ formatMoney(totalPurchasePrice) }}</span>
                  </div>
                </div>

                <Row label="صافي الربح المتوقع" :value="formatMoney(profit)" :valueClass="profitClass" />
              </div>

              <!-- Passenger breakdowns -->
              <div v-if="hasPassengersBreakdown" class="space-y-3 pt-4 border-t border-white/10">
                <h3 class="font-bold text-sm text-gold border-b border-white/10 pb-1">الفئات الفرعية لشبكة الأسرة</h3>
                <div class="bg-[#121212] rounded-xl p-3 space-y-2 text-xs">
                  <div v-for="p in activePassengers" :key="p.category" class="flex justify-between text-white/90">
                    <span>{{ p.label }} (العدد: {{ p.count }})</span>
                    <span class="font-mono">{{ formatMoney(p.subtotal) }}</span>
                  </div>
                </div>
              </div>

              <!-- Payment details -->
              <div class="space-y-3 pt-4 border-t border-white/10">
                <h3 class="font-bold text-sm text-gold border-b border-white/10 pb-1">الدفع والتسوية</h3>
                <Row label="حساب التحصيل والتسوية" :value="selectedAccount?.name" />
                <Row v-if="addPayment && form.initial_payment.amount > 0" label="الدفعة الأولية المسجلة"
                  :value="formatMoney(form.initial_payment.amount)" valueClass="text-success" />
                <Row label="المتبقي على العميل" :value="formatMoney(remainingBalance)" valueClass="text-warning font-bold" />
              </div>
            </div>
          </section>
        </div>
      </transition>
    </div>

    <!-- Navigation Buttons -->
    <div class="flex justify-between items-center pt-8 border-t border-white/10">
      <button v-if="currentStep > 1" type="button" @click="prevStep"
        class="px-8 py-3 rounded-xl bg-white/5 hover:bg-white/10 font-bold text-white transition-colors">السابق</button>
      <div v-else></div>

      <button v-if="currentStep < 6" type="button" @click="nextStep" :disabled="!isStepValid"
        class="px-10 py-3 rounded-xl bg-gold text-black font-bold hover:bg-gold/90 disabled:opacity-30 disabled:grayscale transition-all">
        التالي
      </button>

      <button v-else type="button" @click="saveBooking" :disabled="isSaving"
        class="px-10 py-3 rounded-xl bg-success text-white font-bold hover:bg-success/90 shadow-lg shadow-success/20 flex items-center gap-3 transition-colors">
        <Loader2 v-if="isSaving" class="w-5 h-5 animate-spin" />
        {{ isSaving ? 'جارٍ الحفظ...' : 'حفظ الحجز' }}
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onActivated, h } from 'vue';
import { useRouter } from 'vue-router';
import axios from 'axios';
import { filterSettlementAccountsByModule } from '@/composables/useTreasuryAccountGroups';
import { useHajjUmraStore } from '@/stores/hajjUmraStore';
import { useAuthStore } from '@/stores/authStore';
import { 
  ArrowLeft, Check, Loader2, Search, Plus, TrendingUp, TrendingDown,
  Banknote, Wallet, Landmark
} from 'lucide-vue-next';

const store = useHajjUmraStore();
const router = useRouter();
const authStore = useAuthStore();

const currentStep = ref(1);
const isSaving = ref(false);
const customerSearch = ref('');
const companionSearch = ref('');
const showNewCustomerForm = ref(false);
const needsCompanion = ref(false);
const addPayment = ref(false);

const searchResults = ref([]);
const loadingCustomers = ref(false);
const showDropdown = ref(false);

const companionSearchResults = ref([]);
const loadingCompanion = ref(false);
const showCompanionDropdown = ref(false);
const selectedCompanionObject = ref(null);

const settlementCategoryUi = ref('cash');
const settlementCategoryChips = [
  { id: 'cash', label: 'نقدي / خزينة', icon: Banknote, iconClass: 'text-gold' },
  { id: 'wallet', label: 'محافظ', icon: Wallet, iconClass: 'text-sky-300' },
  { id: 'bank', label: 'بنك', icon: Landmark, iconClass: 'text-info' },
];

function defaultPassengers() {
  return [
    { category: 'adult', label: 'بالغ', count: 0, unit_price: 0, subtotal: 0 },
    { category: 'child_with_bed', label: 'طفل بسرير', count: 0, unit_price: 0, subtotal: 0 },
    { category: 'child_no_bed', label: 'طفل بدون سرير', count: 0, unit_price: 0, subtotal: 0 },
    { category: 'infant', label: 'رضيع', count: 0, unit_price: 0, subtotal: 0 },
  ];
}

function createDefaultForm() {
  return {
    customer: null,
    program_id: null,
    companion_customer_id: null,
    supplier_id: null,
    purchase_price: 0,
    companion_purchase_price: 0,
    selling_price: 0,
    companion_selling_price: 0,
    currency: 'EGP',
    per_person: true,
    accommodation_choice: 'standard',
    accommodation_extra_charge: 0,
    status: 'confirmed',
    account_id: null,
    agent_name: authStore.userName || '',
    notes: '',
    initial_payment: {
      amount: 0,
      payment_method: 'cash',
      payment_date: new Date().toISOString().split('T')[0],
      reference: '',
      paid_by: '',
    },
  };
}

const passengers = ref(defaultPassengers());

const form = ref(createDefaultForm());

function resetBookingForm() {
  currentStep.value = 1;
  isSaving.value = false;
  customerSearch.value = '';
  companionSearch.value = '';
  showNewCustomerForm.value = false;
  needsCompanion.value = false;
  addPayment.value = false;
  searchResults.value = [];
  loadingCustomers.value = false;
  showDropdown.value = false;
  companionSearchResults.value = [];
  loadingCompanion.value = false;
  showCompanionDropdown.value = false;
  selectedCompanionObject.value = null;
  settlementCategoryUi.value = 'cash';
  passengers.value = defaultPassengers();
  form.value = createDefaultForm();
  newCustomer.value = { full_name: '', phone: '', passport_number: '', date_of_birth: '', national_id: '', travel_country: 'السعودية' };
}

const steps = [
  { id: 1, title: 'العميل', label: 'اختيار العميل' },
  { id: 2, title: 'البرنامج', label: 'اختيار البرنامج' },
  { id: 3, title: 'المرافق', label: 'إضافة مرافق' },
  { id: 4, title: 'التسعير', label: 'الشراء والبيع' },
  { id: 5, title: 'الدفع', label: 'الحساب والدفع الأولي' },
  { id: 6, title: 'الملخص', label: 'مراجعة نهائية' },
];

const moduleSettlementAccounts = computed(() =>
  filterSettlementAccountsByModule(store.accounts || [], 'hajj_umra')
);

const filteredAccounts = computed(() => {
  const accounts = moduleSettlementAccounts.value;
  if (settlementCategoryUi.value === 'cash') {
    return accounts.filter(a => a.type === 'cashbox' || a.type === 'treasury');
  }
  if (settlementCategoryUi.value === 'wallet') {
    return accounts.filter(a => a.type === 'wallet');
  }
  if (settlementCategoryUi.value === 'bank') {
    return accounts.filter(a => a.type === 'bank');
  }
  return accounts;
});

const selectedProgram = computed(() => store.programs.find((p) => p.id === form.value.program_id));
const selectedAccount = computed(() => store.accounts.find((a) => a.id === form.value.account_id));

const companionName = computed(() => {
  return selectedCompanionObject.value?.full_name || selectedCompanionObject.value?.name || '';
});

const supplierName = computed(() => {
  const s = store.suppliers.find((x) => x.id === form.value.supplier_id);
  return s ? s.name : '';
});

const passengersTotal = computed(() => {
  return round(passengers.value.reduce((s, p) => s + (p.subtotal || 0), 0));
});

const totalSellingPrice = computed(() => {
  return round((form.value.selling_price || 0) + 
               (needsCompanion.value ? (form.value.companion_selling_price || 0) : 0) + 
               (form.value.accommodation_extra_charge || 0));
});

const totalPurchasePrice = computed(() => {
  return round((form.value.purchase_price || 0) + 
               (needsCompanion.value ? (form.value.companion_purchase_price || 0) : 0));
});

const profit = computed(() => {
  return round(totalSellingPrice.value - totalPurchasePrice.value);
});

const profitClass = computed(() => (profit.value >= 0 ? 'text-success' : 'text-error'));

const marginPct = computed(() => {
  const c = totalPurchasePrice.value;
  if (c <= 0) return null;
  return Math.round((profit.value / c) * 10000) / 100;
});

const hasPassengersBreakdown = computed(() => {
  return passengers.value.some(p => p.count > 0);
});

const activePassengers = computed(() => {
  return passengers.value.filter(p => p.count > 0);
});

const remainingBalance = computed(() => {
  const paid = addPayment.value ? (Number(form.value.initial_payment.amount) || 0) : 0;
  return round(totalSellingPrice.value - paid);
});

const isStepValid = computed(() => {
  switch (currentStep.value) {
    case 1: return !!form.value.customer;
    case 2: return !!form.value.program_id;
    case 3: return !needsCompanion.value || !!form.value.companion_customer_id;
    case 4: return form.value.purchase_price >= 0 && form.value.selling_price >= 0 && totalSellingPrice.value > 0;
    case 5: return !!form.value.account_id;
    default: return true;
  }
});

const newCustomer = ref({ full_name: '', phone: '', passport_number: '', date_of_birth: '', national_id: '', travel_country: 'السعودية' });

function round(n) {
  return Math.round((Number(n) || 0) * 100) / 100;
}

function formatMoney(n, curr = 'EGP') {
  const num = Number(n) || 0;
  return new Intl.NumberFormat('ar-EG', { style: 'currency', currency: curr }).format(num);
}

function accountTypeLabel(a) {
  const map = { cashbox: 'صندوق نقدية', bank: 'بنك', wallet: 'محفظة', treasury: 'خزينة', customer: 'عميل', supplier: 'مورّد' };
  return map[a.type] || a.type || '-';
}

function applyMarkup(pct) {
  const c = Number(form.value.purchase_price) || 0;
  if (c <= 0) {
    store.addToast('أدخل تكلفة أولاً', 'error');
    return;
  }
  form.value.selling_price = Math.round(c * (1 + pct / 100) * 100) / 100;
}

function setPaidPercent(pct) {
  const sp = totalSellingPrice.value;
  if (sp <= 0) {
    store.addToast('أدخل سعر البيع أولاً', 'error');
    return;
  }
  form.value.initial_payment.amount = Math.round((sp * pct) / 100 * 100) / 100;
}

let debounceTimeout = null;
const onCustomerSearchDebounced = () => {
  if (debounceTimeout) clearTimeout(debounceTimeout);
  
  const query = customerSearch.value.trim();
  if (query.length < 2) {
    searchResults.value = [];
    showDropdown.value = false;
    return;
  }
  
  showDropdown.value = true;
  loadingCustomers.value = true;
  
  debounceTimeout = setTimeout(async () => {
    try {
      const response = await axios.get('/api/v1/clients', { params: { search: query } });
      searchResults.value = response.data?.data ?? [];
    } catch (error) {
      console.error('Failed to search customers', error);
      searchResults.value = [];
    } finally {
      loadingCustomers.value = false;
    }
  }, 300);
};

let companionDebounceTimeout = null;
const onCompanionSearchDebounced = () => {
  if (companionDebounceTimeout) clearTimeout(companionDebounceTimeout);
  
  const query = companionSearch.value.trim();
  if (query.length < 2) {
    companionSearchResults.value = [];
    showCompanionDropdown.value = false;
    return;
  }
  
  showCompanionDropdown.value = true;
  loadingCompanion.value = true;
  
  companionDebounceTimeout = setTimeout(async () => {
    try {
      const response = await axios.get('/api/v1/clients', { params: { search: query } });
      companionSearchResults.value = response.data?.data ?? [];
    } catch (error) {
      console.error('Failed to search companion', error);
      companionSearchResults.value = [];
    } finally {
      loadingCompanion.value = false;
    }
  }, 300);
};

function selectCustomer(c) {
  form.value.customer = c;
  form.value.initial_payment.paid_by = c.full_name || c.name || '';
  customerSearch.value = '';
  searchResults.value = [];
  showDropdown.value = false;
}

function selectCompanion(c) {
  form.value.companion_customer_id = c.id;
  selectedCompanionObject.value = c;
  companionSearch.value = '';
  companionSearchResults.value = [];
  showCompanionDropdown.value = false;
}

function clearCompanion() {
  form.value.companion_customer_id = null;
  selectedCompanionObject.value = null;
  form.value.companion_purchase_price = 0;
  form.value.companion_selling_price = 0;
}

async function createNewCustomer() {
  if (!newCustomer.value.full_name?.trim() || !newCustomer.value.phone?.trim()) {
    store.addToast('الاسم والهاتف مطلوبان', 'error');
    return;
  }
  if (!newCustomer.value.national_id?.trim()) {
    store.addToast('الرقم القومي مطلوب', 'error');
    return;
  }
  if (!newCustomer.value.travel_country?.trim()) {
    store.addToast('دولة السفر مطلوبة', 'error');
    return;
  }
  try {
    const c = await store.createCustomer(newCustomer.value);
    selectCustomer(c);
    showNewCustomerForm.value = false;
    newCustomer.value = { full_name: '', phone: '', passport_number: '', date_of_birth: '', national_id: '', travel_country: 'السعودية' };
    store.addToast('تم إضافة العميل بنجاح');
  } catch (e) {
    const errMsg = e.response?.data?.message || 'فشل إضافة العميل';
    store.addToast(errMsg, 'error');
  }
}

function onProgramSelect() {
  const p = selectedProgram.value;
  if (!p) return;
  if ((!form.value.purchase_price || form.value.purchase_price <= 0) && p.default_purchase_price > 0) {
    form.value.purchase_price = p.default_purchase_price;
  }
  if ((!form.value.selling_price || form.value.selling_price <= 0) && p.default_selling_price > 0) {
    form.value.selling_price = p.default_selling_price;
  }
}

function onSupplierSelect() {
  const supplier = store.suppliers.find(s => s.id === form.value.supplier_id);
  if (supplier && supplier.supplier_cost_price > 0) {
    form.value.purchase_price = supplier.supplier_cost_price;
  }
}

function updatePassengerSubtotal(p) {
  p.subtotal = round((p.count || 0) * (p.unit_price || 0));
  updatePricesFromPassengers();
}

function updatePricesFromPassengers() {
  const total = passengersTotal.value;
  if (total > 0) {
    form.value.selling_price = total;
  }
}

function onAccommodationChange() {
  if (form.value.accommodation_choice === 'private') {
    form.value.accommodation_extra_charge = 3000;
  } else {
    form.value.accommodation_extra_charge = 0;
  }
}

function nextStep() {
  if (currentStep.value < 6 && isStepValid.value) {
    currentStep.value++;
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }
}

function prevStep() {
  if (currentStep.value > 1) {
    currentStep.value--;
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }
}

async function saveBooking() {
  isSaving.value = true;
  try {
    const payload = {
      customer_id: form.value.customer.id,
      companion_customer_id: needsCompanion.value ? (form.value.companion_customer_id || null) : null,
      program_id: form.value.program_id,
      supplier_id: form.value.supplier_id || null,
      purchase_price: Number(form.value.purchase_price) || 0,
      companion_purchase_price: needsCompanion.value ? (Number(form.value.companion_purchase_price) || 0) : 0,
      selling_price: Number(form.value.selling_price) || 0,
      companion_selling_price: needsCompanion.value ? (Number(form.value.companion_selling_price) || 0) : 0,
      currency: form.value.currency,
      per_person: !!form.value.per_person,
      accommodation_choice: form.value.accommodation_choice || 'standard',
      accommodation_extra_charge: Number(form.value.accommodation_extra_charge) || 0,
      status: form.value.status,
      account_id: form.value.account_id,
      agent_name: form.value.agent_name?.trim() || '',
      notes: form.value.notes?.trim() || null,
      passengers: passengers.value.filter(p => p.count > 0).map(p => ({
        category: p.category,
        count: p.count,
        unit_price: p.unit_price,
        subtotal: p.subtotal,
      })),
    };

    if (addPayment.value && Number(form.value.initial_payment.amount) > 0) {
      payload.initial_payment = {
        amount: Number(form.value.initial_payment.amount),
        payment_method: form.value.initial_payment.payment_method,
        account_id: form.value.account_id,
        payment_date: form.value.initial_payment.payment_date,
        reference: form.value.initial_payment.reference || null,
        paid_by: form.value.initial_payment.paid_by || form.value.customer?.full_name || '',
      };
    }

    const created = await store.createBooking(payload);
    store.addToast('تم إنشاء الحجز بنجاح');
    router.push({ name: 'hajj.show', params: { id: created.id } }).catch(() => router.push({ name: 'hajj.index' }));
  } catch (e) {
    console.error(e);
    store.addToast(e.response?.data?.message || 'فشل حفظ الحجز', 'error');
  } finally {
    isSaving.value = false;
  }
}

onMounted(async () => {
  resetBookingForm();
  await Promise.all([
    store.fetchSettings(), 
    store.fetchAccounts({ types: 'cashbox,wallet,bank,treasury,post' }),
    store.fetchSuppliers(),
  ]);
});

onActivated(() => {
  resetBookingForm();
});

const Field = (props) =>
  h('div', null, [
    h('span', { class: 'text-muted ml-2' }, props.label + ':'),
    h('span', { class: 'font-bold' }, String(props.value ?? '-')),
  ]);
Field.props = ['label', 'value'];

const Row = (props) =>
  h('div', { class: 'flex justify-between items-center pb-3 border-b border-white/10 last:border-0' }, [
    h('span', { class: 'text-muted' }, props.label),
    h('span', { class: `font-bold font-mono ${props.valueClass || ''}` }, String(props.value ?? '-')),
  ]);
Row.props = ['label', 'value', 'valueClass'];
</script>

<style scoped>
.bg-card { background-color: var(--card-bg); }
.bg-input { background-color: var(--input-bg); }
.text-muted { color: var(--text-muted); }
.text-gold { color: var(--gold); }
.bg-gold { background-color: var(--gold); }
.text-success { color: var(--success); }
.text-error { color: var(--error); }
.text-warning { color: var(--warning, #f59e0b); }
.bg-success { background-color: var(--success); }
.border-warning { border-color: var(--warning, #f59e0b); }
.bg-warning { background-color: var(--warning, #f59e0b); }

.step-enter-active, .step-leave-active { transition: all 0.4s ease; }
.step-enter-from { opacity: 0; transform: translateX(30px); }
.step-leave-to { opacity: 0; transform: translateX(-30px); }
</style>
