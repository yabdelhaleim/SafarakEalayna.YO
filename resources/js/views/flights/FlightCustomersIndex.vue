<template>
  <div class="animate-in fade-in slide-in-from-bottom-8 duration-700 text-right font-sans" dir="rtl">
    <div class="max-w-7xl mx-auto space-y-8 print:hidden">
      <!-- Header -->
      <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
      <div>
        <h1 class="text-3xl font-extrabold text-white font-display">عملاء الطيران</h1>
        <p class="text-muted mt-1">إدارة عملاء الكوانتر وعملاء الشركات لمبيعات الطيران ومتابعة ديونهم</p>
      </div>
      <div class="flex items-center gap-3">
        <button
          @click="openCreateModal"
          class="px-6 py-3 bg-gold text-black font-bold rounded-xl hover:bg-gold/90 transition-all shadow-lg shadow-gold/20 flex items-center gap-2"
        >
          <Plus class="w-5 h-5" />
          إضافة عميل جديد
        </button>
      </div>
    </div>

    <!-- Stats Panel -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
      <!-- Total Outstanding Debt -->
      <div class="bg-card border border-white/10 rounded-2xl p-6 relative overflow-hidden group">
        <div class="absolute -right-4 -top-4 w-24 h-24 bg-red-500/5 rounded-full group-hover:scale-110 transition-all"></div>
        <div class="flex items-center gap-4">
          <div class="w-12 h-12 rounded-xl bg-error/10 text-error flex items-center justify-center flex-shrink-0">
            <DollarSign class="w-6 h-6" />
          </div>
          <div>
            <p class="text-xs text-muted font-bold">إجمالي المديونيات المستحقة لنا</p>
            <h3 class="text-2xl font-extrabold text-white font-mono mt-1">
              {{ formatCurrency(stats.totalDebt) }}
            </h3>
          </div>
        </div>
      </div>

      <!-- Counter Customers Count -->
      <div class="bg-card border border-white/10 rounded-2xl p-6 relative overflow-hidden group">
        <div class="absolute -right-4 -top-4 w-24 h-24 bg-gold/5 rounded-full group-hover:scale-110 transition-all"></div>
        <div class="flex items-center gap-4">
          <div class="w-12 h-12 rounded-xl bg-gold/10 text-gold flex items-center justify-center flex-shrink-0">
            <Users class="w-6 h-6" />
          </div>
          <div>
            <p class="text-xs text-muted font-bold">عملاء الكوانتر</p>
            <h3 class="text-2xl font-extrabold text-white font-mono mt-1">
              {{ stats.counterCount }}
            </h3>
          </div>
        </div>
      </div>

      <!-- Companies Count -->
      <div class="bg-card border border-white/10 rounded-2xl p-6 relative overflow-hidden group">
        <div class="absolute -right-4 -top-4 w-24 h-24 bg-sky-500/5 rounded-full group-hover:scale-110 transition-all"></div>
        <div class="flex items-center gap-4">
          <div class="w-12 h-12 rounded-xl bg-sky-500/10 text-sky-400 flex items-center justify-center flex-shrink-0">
            <Building2 class="w-6 h-6" />
          </div>
          <div>
            <p class="text-xs text-muted font-bold">عملاء الشركات</p>
            <h3 class="text-2xl font-extrabold text-white font-mono mt-1">
              {{ stats.companiesCount }}
            </h3>
          </div>
        </div>
      </div>
    </div>

    <!-- Search & Tabs -->
    <div class="bg-card border border-white/10 rounded-3xl p-6 space-y-6 shadow-2xl">
      <!-- Tabs Navigation -->
      <div class="flex border-b border-white/10 p-1 bg-white/5 rounded-xl max-w-md">
        <button
          @click="changeTab('regular')"
          :class="[
            'flex-1 py-3 text-center text-sm font-bold rounded-lg transition-all',
            activeTab === 'regular'
              ? 'bg-gold text-black shadow-md'
              : 'text-muted hover:text-white'
          ]"
        >
          عميل كوانتر
        </button>
        <button
          @click="changeTab('counter')"
          :class="[
            'flex-1 py-3 text-center text-sm font-bold rounded-lg transition-all',
            activeTab === 'counter'
              ? 'bg-gold text-black shadow-md'
              : 'text-muted hover:text-white'
          ]"
        >
          عميل شركه
        </button>
      </div>

      <!-- Search Input -->
      <div class="relative">
        <div class="absolute inset-y-0 right-0 pr-4 flex items-center pointer-events-none">
          <Search class="h-5 w-5 text-muted" />
        </div>
        <input
          v-model="searchQuery"
          type="text"
          :placeholder="activeTab === 'regular' ? 'ابحث بالاسم، رقم الهاتف، أو الرقم القومي للعميل...' : 'ابحث باسم عميل الشركه أو رقم الهاتف...'"
          class="w-full pr-12 pl-4 py-4 bg-input border border-white/10 rounded-2xl focus:border-gold focus:ring-1 focus:ring-gold outline-none transition-all text-right text-sm"
          @input="onSearch"
        />
      </div>

      <!-- Customers Table -->
      <div class="overflow-x-auto rounded-2xl border border-white/10 bg-white/[0.02]">
        <table class="w-full text-right border-collapse">
          <thead>
            <tr class="border-b border-white/10 bg-white/5 text-xs font-bold text-muted uppercase tracking-wider">
              <th class="px-6 py-4">العميل</th>
              <th class="px-6 py-4">رقم الهاتف</th>
              <th v-if="activeTab === 'regular'" class="px-6 py-4">الرقم القومي</th>
              <th class="px-6 py-4">المدينة / السفر</th>
              <th v-if="activeTab === 'regular'" class="px-6 py-4">الجهة</th>
              <th class="px-6 py-4">الحساب الحالي / المديونية</th>
              <th class="px-6 py-4 text-left">الإجراءات</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loadingList" class="border-b border-white/5">
              <td :colspan="activeTab === 'regular' ? 7 : 5" class="px-6 py-12 text-center text-muted italic">
                <div class="inline-block animate-spin rounded-full h-8 w-8 border-t-2 border-b-2 border-gold mb-3"></div>
                <div>جاري تحميل البيانات...</div>
              </td>
            </tr>
            <tr v-else-if="customers.length === 0" class="border-b border-white/5">
              <td :colspan="activeTab === 'regular' ? 7 : 5" class="px-6 py-12 text-center text-muted">
                <Users class="h-12 w-12 text-muted/30 mx-auto mb-3" />
                <div class="text-sm font-semibold">لم يتم العثور على عملاء</div>
                <div class="text-xs text-muted/70 mt-1">تأكد من كتابة اسم صحيح أو قم بإضافة عميل جديد</div>
              </td>
            </tr>
            <tr
              v-else
              v-for="customer in customers"
              :key="customer.id"
              class="border-b border-white/5 hover:bg-white/[0.03] transition-colors group text-sm"
            >
              <!-- Customer Info -->
              <td class="px-6 py-4 whitespace-nowrap">
                <div class="flex items-center gap-3">
                  <div :class="['w-10 h-10 rounded-full flex items-center justify-center font-bold text-sm flex-shrink-0', activeTab === 'counter' ? 'bg-sky-500/10 text-sky-400' : 'bg-gold/10 text-gold']">
                    {{ getInitials(customer.name) }}
                  </div>
                  <div>
                    <div class="font-bold text-white">{{ customer.name }}</div>
                    <div v-if="customer.notes" class="text-xs text-muted truncate max-w-xs mt-0.5" :title="customer.notes">
                      {{ customer.notes }}
                    </div>
                  </div>
                </div>
              </td>

              <!-- Phone & WhatsApp -->
              <td class="px-6 py-4 whitespace-nowrap">
                <div class="flex items-center gap-2">
                  <span class="font-mono text-muted">{{ customer.phone || '—' }}</span>
                  <a
                    v-if="customer.phone || customer.whatsapp_number"
                    :href="'https://wa.me/' + formatWhatsApp(customer.whatsapp_number || customer.phone)"
                    target="_blank"
                    class="p-1 hover:bg-green-500/20 text-green-400 rounded transition-all"
                    title="مراسلة عبر واتساب"
                  >
                    <MessageSquare class="w-4 h-4" />
                  </a>
                </div>
              </td>

              <!-- National ID -->
              <td v-if="activeTab === 'regular'" class="px-6 py-4 whitespace-nowrap">
                <span class="font-mono text-muted">{{ customer.national_id || '—' }}</span>
              </td>

              <!-- City / Travel Country -->
              <td class="px-6 py-4 whitespace-nowrap">
                <div class="text-xs space-y-0.5">
                  <div v-if="customer.city" class="flex items-center gap-1 text-muted">
                    <MapPin class="w-3.5 h-3.5" />
                    <span>{{ customer.city }}</span>
                  </div>
                  <div v-if="customer.travel_country" class="flex items-center gap-1 text-sky-400">
                    <Globe2 class="w-3.5 h-3.5" />
                    <span>{{ customer.travel_country }}</span>
                  </div>
                  <div v-if="!customer.city && !customer.travel_country" class="text-muted/50">—</div>
                </div>
              </td>

              <!-- Affiliation -->
              <td v-if="activeTab === 'regular'" class="px-6 py-4 whitespace-nowrap text-muted text-xs">
                {{ customer.affiliation || '—' }}
              </td>

              <!-- Ledger Balance / Debt -->
              <td class="px-6 py-4 whitespace-nowrap">
                <div class="flex flex-col items-start">
                  <div class="font-mono font-bold text-sm">
                    <span v-if="customer.balance > 0" class="text-error">
                      {{ formatCurrency(customer.balance) }} (مدين / عليه)
                    </span>
                    <span v-else-if="customer.balance < 0" class="text-success">
                      {{ formatCurrency(Math.abs(customer.balance)) }} (دائن / له)
                    </span>
                    <span v-else class="text-muted">
                      0.00
                    </span>
                  </div>
                  <span class="text-[10px] text-muted/60 mt-0.5">رصيد الحساب المالي</span>
                </div>
              </td>

              <!-- Actions -->
              <td class="px-6 py-4 whitespace-nowrap text-left text-xs font-semibold">
                <div class="flex items-center justify-end gap-2">
                  <button
                    @click="viewCustomerStatement(customer)"
                    class="p-2 bg-white/5 hover:bg-sky-500/20 rounded-lg text-muted hover:text-sky-400 transition-all"
                    title="كشف الحساب وتفاصيل العمليات"
                  >
                    <Eye class="w-4 h-4" />
                  </button>
                  <button
                    @click="editCustomer(customer)"
                    class="p-2 bg-white/5 hover:bg-gold hover:text-black rounded-lg text-muted transition-all"
                    title="تعديل"
                  >
                    <Pen class="w-4 h-4" />
                  </button>
                  <button
                    @click="deleteCustomer(customer)"
                    class="p-2 bg-white/5 hover:bg-error/20 rounded-lg text-muted hover:text-error transition-all"
                    title="حذف"
                  >
                    <Trash2 class="w-4 h-4" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div
        v-if="!loadingList && pagination.lastPage > 1"
        class="flex flex-col sm:flex-row items-center justify-between gap-4 pt-4 border-t border-white/10 text-sm text-muted"
      >
        <div>
          عرض الصفحة {{ pagination.currentPage }} من {{ pagination.lastPage }} (إجمالي {{ pagination.total }} عميل)
        </div>
        <div class="flex items-center gap-2">
          <button
            :disabled="pagination.currentPage === 1"
            @click="changePage(pagination.currentPage - 1)"
            class="px-4 py-2 bg-white/5 hover:bg-white/10 rounded-lg disabled:opacity-50 disabled:pointer-events-none transition-all"
          >
            السابق
          </button>
          <span class="px-3 font-mono text-white">{{ pagination.currentPage }}</span>
          <button
            :disabled="pagination.currentPage === pagination.lastPage"
            @click="changePage(pagination.currentPage + 1)"
            class="px-4 py-2 bg-white/5 hover:bg-white/10 rounded-lg disabled:opacity-50 disabled:pointer-events-none transition-all"
          >
            التالي
          </button>
        </div>
      </div>
    </div>
    </div>

    <!-- Create / Edit Customer Modal -->
    <div v-if="showModal" class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm animate-in fade-in duration-300 print:hidden">
      <div class="bg-card w-full max-w-2xl border border-white/10 rounded-2xl p-6 shadow-2xl animate-in zoom-in-95 duration-300">
        <h2 class="text-xl font-bold mb-6 text-gold">
          {{ isEditMode ? 'تعديل بيانات العميل' : (activeTab === 'counter' ? 'إضافة عميل شركه جديد' : 'إضافة عميل كوانتر جديد') }}
        </h2>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <!-- Full Name -->
          <div>
            <label class="block text-sm text-muted mb-1">
              {{ activeTab === 'counter' ? 'اسم عميل الشركه بالكامل *' : 'اسم العميل بالكامل *' }}
            </label>
            <input
              v-model="form.full_name"
              type="text"
              class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none text-right text-sm"
              :placeholder="activeTab === 'counter' ? 'مثال: شركة النور للخدمات أو اسم العميل' : 'مثال: أحمد محمد علي'"
            />
          </div>

          <!-- Phone -->
          <div>
            <label class="block text-sm text-muted mb-1">رقم الهاتف*</label>
            <input
              v-model="form.phone"
              type="text"
              class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none text-left text-sm"
              dir="ltr"
              placeholder="01xxxxxxxxx"
            />
          </div>

          <!-- National ID (Only for Individuals) -->
          <div v-if="activeTab === 'regular'">
            <label class="block text-sm text-muted mb-1">الرقم القومي (14 رقم)</label>
            <input
              v-model="form.national_id"
              type="text"
              maxlength="14"
              class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none text-left text-sm"
              dir="ltr"
              placeholder="290xxxxxxxxxxx"
            />
          </div>

          <!-- WhatsApp Number -->
          <div>
            <label class="block text-sm text-muted mb-1">رقم الواتساب</label>
            <input
              v-model="form.whatsapp_number"
              type="text"
              class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none text-left text-sm"
              dir="ltr"
              placeholder="01xxxxxxxxx"
            />
          </div>

          <!-- City -->
          <div>
            <label class="block text-sm text-muted mb-1">المدينة</label>
            <input
              v-model="form.city"
              type="text"
              class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none text-right text-sm"
              placeholder="مثال: القاهرة"
            />
          </div>

          <!-- Travel Country -->
          <div>
            <label class="block text-sm text-muted mb-1">دولة السفر</label>
            <input
              v-model="form.travel_country"
              type="text"
              class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none text-right text-sm"
              placeholder="مثال: المملكة العربية السعودية"
            />
          </div>

          <!-- Affiliation (Only for Individuals) -->
          <div v-if="activeTab === 'regular'" class="md:col-span-2">
            <label class="block text-sm text-muted mb-1">الجهة التابع لها</label>
            <input
              v-model="form.affiliation"
              type="text"
              class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none text-right text-sm"
              placeholder="الشركة أو المؤسسة التابع لها"
            />
          </div>

          <!-- Notes -->
          <div class="md:col-span-2">
            <label class="block text-sm text-muted mb-1">ملاحظات</label>
            <textarea
              v-model="form.notes"
              rows="3"
              class="w-full p-3 bg-input border border-white/10 rounded-xl focus:border-gold outline-none text-right text-sm resize-none"
              placeholder="أية تفاصيل أو ملاحظات إضافية عن العميل..."
            ></textarea>
          </div>
        </div>

        <div class="flex gap-3 mt-8">
          <button
            @click="closeModal"
            class="flex-1 py-3 bg-white/5 rounded-xl hover:bg-white/10 transition-all text-sm font-semibold"
          >
            إلغاء
          </button>
          <button
            @click="saveCustomer"
            :disabled="saving"
            class="flex-1 py-3 bg-gold text-black font-bold rounded-xl hover:bg-gold/80 transition-all shadow-lg shadow-gold/20 disabled:opacity-50 text-sm flex items-center justify-center gap-2"
          >
            <Check class="w-4 h-4" v-if="!saving" />
            <span>{{ saving ? 'جاري الحفظ...' : 'حفظ العميل' }}</span>
          </button>
        </div>
      </div>
    </div>
  </div>

    <!-- Customer Statement Modal -->
    <div v-if="showStatementModal" class="fixed inset-0 z-[110] flex items-center justify-center p-4 bg-black/90 backdrop-blur-md animate-in fade-in duration-300 print:hidden" dir="rtl">
      <div class="bg-card w-full max-w-6xl max-h-[90vh] flex flex-col border border-white/10 rounded-2xl shadow-2xl overflow-hidden animate-in zoom-in-95 duration-300">
        
        <!-- Header -->
        <div class="px-6 py-4 bg-white/5 border-b border-white/10 flex items-center justify-between shrink-0">
          <div class="flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl bg-sky-500/10 text-sky-400 flex items-center justify-center">
              <FileText class="w-6 h-6" />
            </div>
            <div>
              <h3 class="text-xl font-black text-white">كشف حساب العميل والعمليات</h3>
              <p class="text-xs font-bold text-muted mt-1 font-mono">
                {{ selectedCustomerForStatement?.name || selectedCustomerForStatement?.full_name }} 
                <span class="mx-2 text-white/20">|</span> 
                <span dir="ltr">{{ selectedCustomerForStatement?.phone }}</span>
              </p>
            </div>
          </div>
          <button @click="closeStatementModal" class="p-2 text-muted hover:text-white hover:bg-white/10 rounded-xl transition-all">
            <X class="w-6 h-6" />
          </button>
        </div>

        <div class="flex-1 overflow-y-auto p-6 space-y-6">
          
          <!-- Stats Row -->
          <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-white/5 border border-white/5 p-4 rounded-2xl">
              <span class="text-[10px] font-black text-muted uppercase tracking-wider block mb-1">الرصيد الافتتاحي</span>
              <span class="text-lg font-bold font-mono text-white">{{ formatCurrency(statementStats.opening_balance) }}</span>
            </div>
            <div class="bg-error/5 border border-error/10 p-4 rounded-2xl">
              <span class="text-[10px] font-black text-error uppercase tracking-wider block mb-1">المبيعات / المسحوبات (مدين)</span>
              <span class="text-lg font-bold font-mono text-error">{{ formatCurrency(statementStats.period_debit) }}</span>
            </div>
            <div class="bg-success/5 border border-success/10 p-4 rounded-2xl">
              <span class="text-[10px] font-black text-success uppercase tracking-wider block mb-1">المدفوعات / السداد (دائن)</span>
              <span class="text-lg font-bold font-mono text-success">{{ formatCurrency(statementStats.period_credit) }}</span>
            </div>
            <div class="bg-white/5 border border-white/10 p-4 rounded-2xl">
              <span class="text-[10px] font-black text-gold uppercase tracking-wider block mb-1">الرصيد النهائي المستحق</span>
              <span class="text-lg font-bold font-mono flex items-center gap-1" :class="statementStats.closing_balance > 0 ? 'text-error' : (statementStats.closing_balance < 0 ? 'text-success' : 'text-white')">
                {{ formatCurrency(Math.abs(statementStats.closing_balance)) }}
                <span class="text-xs font-sans font-bold" v-if="statementStats.closing_balance > 0">(عليه)</span>
                <span class="text-xs font-sans font-bold" v-else-if="statementStats.closing_balance < 0">(له)</span>
              </span>
            </div>
          </div>

          <!-- Filters -->
          <div class="flex flex-wrap items-center gap-3 bg-white/[0.02] p-3 rounded-2xl border border-white/5">
            <div class="relative flex-1 min-w-[200px]">
              <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                <Search class="h-4 w-4 text-muted" />
              </div>
              <input v-model="statementFilters.search" type="text" placeholder="بحث في البيان أو المرجع..." class="w-full pr-10 pl-3 py-2.5 bg-input border border-white/10 rounded-xl focus:border-gold outline-none text-right text-sm" />
            </div>

            <div class="flex items-center gap-2 flex-1 min-w-[250px]">
              <input v-model="statementFilters.from_date" type="date" class="flex-1 px-3 py-2.5 bg-input border border-white/10 rounded-xl focus:border-gold outline-none text-sm font-mono text-muted text-right" title="من تاريخ" />
              <span class="text-muted text-sm px-1">-</span>
              <input v-model="statementFilters.to_date" type="date" class="flex-1 px-3 py-2.5 bg-input border border-white/10 rounded-xl focus:border-gold outline-none text-sm font-mono text-muted text-right" title="إلى تاريخ" />
            </div>
          </div>

          <!-- Loader -->
          <div v-if="loadingStatement" class="py-16 text-center text-muted flex flex-col items-center justify-center">
            <div class="animate-spin rounded-full h-10 w-10 border-t-2 border-b-2 border-sky-400 mb-4"></div>
            <p class="font-bold text-sm tracking-wider">جاري جلب سجل العمليات...</p>
          </div>
          
          <!-- Empty State -->
          <div v-else-if="filteredStatementItems.length === 0" class="py-16 text-center text-muted flex flex-col items-center justify-center bg-white/[0.01] rounded-2xl border border-dashed border-white/10">
            <Filter class="w-10 h-10 text-muted/30 mb-3" />
            <p class="font-bold text-sm">لا توجد عمليات تطابق معايير البحث.</p>
          </div>

          <!-- Table -->
          <div v-else class="overflow-x-auto rounded-xl border border-white/10 bg-white/[0.02]">
            <table class="w-full text-right border-collapse whitespace-nowrap">
              <thead>
                <tr class="border-b border-white/10 bg-white/5 text-[10px] font-black text-muted uppercase tracking-wider">
                  <th class="px-4 py-3">التاريخ</th>
                  <th class="px-4 py-3 text-center">القسم</th>
                  <th class="px-4 py-3 w-1/3">العملية والبيان</th>
                  <th class="px-4 py-3">مدين (سحب)</th>
                  <th class="px-4 py-3">دائن (إيداع)</th>
                  <th class="px-4 py-3">الرصيد</th>
                  <th class="px-4 py-3 text-left">التفاصيل</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="item in filteredStatementItems" :key="item.id" class="border-b border-white/5 hover:bg-white/5 transition-colors text-xs font-semibold group">
                  <!-- Date -->
                  <td class="px-4 py-3 font-mono text-muted">
                    <div class="flex flex-col">
                      <span class="text-white">{{ item.date_human?.split(' ')[0] }}</span>
                      <span class="text-[10px]">{{ item.date_human?.split(' ')[1] }}</span>
                    </div>
                  </td>
                  <!-- Module -->
                  <td class="px-4 py-3 text-center">
                    <div class="inline-flex items-center justify-center gap-1.5 px-2 py-1 rounded border border-white/10 bg-white/5 text-[10px]">
                      <component :is="getModuleIcon(item.module)" class="w-3.5 h-3.5 text-gold" />
                      <span>{{ getModuleLabel(item.module) }}</span>
                    </div>
                  </td>
                  <!-- Description -->
                  <td class="px-4 py-3 whitespace-normal min-w-[250px]">
                    <div class="flex flex-col gap-1">
                      <div class="text-white font-bold leading-relaxed">{{ item.description }}</div>
                      <div class="flex items-center gap-3 text-[10px] text-muted font-mono">
                        <span>#{{ item.reference_id || item.transaction_id }}</span>
                        <span v-if="item.process_type" class="bg-white/10 px-1.5 rounded">{{ item.process_type }}</span>
                      </div>
                    </div>
                  </td>
                  <!-- Debit -->
                  <td class="px-4 py-3 font-mono text-base">
                    <span v-if="item.debit > 0" class="text-error font-black">{{ formatCurrency(item.debit) }}</span>
                    <span v-else class="text-muted/30">-</span>
                  </td>
                  <!-- Credit -->
                  <td class="px-4 py-3 font-mono text-base">
                    <span v-if="item.credit > 0" class="text-success font-black">{{ formatCurrency(item.credit) }}</span>
                    <span v-else class="text-muted/30">-</span>
                  </td>
                  <!-- Running Balance -->
                  <td class="px-4 py-3 font-mono font-bold" :class="item.balance_after > 0 ? 'text-error/80' : (item.balance_after < 0 ? 'text-success/80' : 'text-muted')">
                    <span class="flex items-center gap-1">
                      {{ formatCurrency(Math.abs(item.balance_after)) }}
                      <span class="text-[10px] font-sans" v-if="item.balance_after !== 0">{{ item.balance_after > 0 ? '(ع)' : '(ل)' }}</span>
                    </span>
                  </td>
                  <!-- Actions -->
                  <td class="px-4 py-3 text-left">
                    <button @click="openReceipt(item)" class="p-2 inline-flex bg-sky-500/10 hover:bg-sky-500 text-sky-400 hover:text-white rounded-lg transition-all" title="عرض السند">
                      <FileText class="w-4 h-4" />
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- Receipt Details Sub-Modal -->
    <div v-if="selectedReceipt" class="fixed inset-0 z-[120] flex items-center justify-center p-4 bg-black/95 backdrop-blur-xl animate-in fade-in duration-200 print:static print:block print:w-full print:bg-white print:p-0" dir="rtl">
      <div class="bg-card w-full max-w-lg border border-white/10 rounded-3xl shadow-2xl overflow-hidden print:border-none print:shadow-none print:max-w-none print:rounded-none flex flex-col max-h-[95vh]">
        
        <!-- Modal Header -->
        <div class="px-6 py-4 bg-white/5 border-b border-white/10 flex items-center justify-between print:hidden shrink-0">
          <div class="flex items-center gap-3 text-gold">
            <Printer class="w-5 h-5" />
            <h3 class="font-black text-lg">سند مالي</h3>
          </div>
          <button @click="selectedReceipt = null" class="p-2 text-muted hover:text-white hover:bg-white/10 rounded-xl transition-all">
            <X class="w-5 h-5" />
          </button>
        </div>

        <!-- Printable Area -->
        <div class="p-8 space-y-6 text-right print:w-full print:text-black overflow-y-auto print:overflow-visible">
          <div class="text-center pb-4">
            <h2 class="text-2xl font-black text-gold print:text-black mb-1">سفري علينا للسياحة</h2>
            <p class="text-xs font-bold text-muted print:text-black/60">سند معاملة وتفاصيل حجز</p>
          </div>

          <!-- Amount -->
          <div class="text-center py-6 bg-white/[0.02] print:bg-transparent rounded-2xl border border-white/10 print:border-black/20 print:border-2 mb-6">
            <span class="text-[10px] font-black text-muted print:text-black/70 uppercase tracking-widest block mb-2">قيمة الحركة</span>
            <p class="text-4xl font-black font-mono" :class="selectedReceipt.credit > 0 ? 'text-success print:text-green-700' : 'text-error print:text-red-700'">
              {{ formatCurrency(selectedReceipt.credit > 0 ? selectedReceipt.credit : selectedReceipt.debit) }}
            </p>
            <span class="text-sm font-bold block mt-2" :class="selectedReceipt.credit > 0 ? 'text-success print:text-green-700' : 'text-error print:text-red-700'">
              {{ selectedReceipt.credit > 0 ? 'سداد ودفع من العميل' : 'مديونية سحب ومشتريات' }}
            </span>
          </div>

          <!-- Basic Details -->
          <div class="space-y-3 text-sm bg-white/5 print:bg-transparent p-5 rounded-2xl print:border-b print:border-t print:border-black/20">
            <div class="flex justify-between items-center py-2 border-b border-white/5 print:border-black/10">
              <span class="text-muted print:text-black/70 text-xs font-black">رقم المرجع</span>
              <span class="font-mono font-bold">{{ selectedReceipt.reference_id || selectedReceipt.transaction_id }}</span>
            </div>
            <div class="flex justify-between items-center py-2 border-b border-white/5 print:border-black/10">
              <span class="text-muted print:text-black/70 text-xs font-black">التاريخ والوقت</span>
              <span class="font-mono text-xs">{{ selectedReceipt.date_human }}</span>
            </div>
            <div class="flex justify-between items-center py-2 border-b border-white/5 print:border-black/10">
              <span class="text-muted print:text-black/70 text-xs font-black">العميل المستفيد</span>
              <span class="font-bold">{{ selectedCustomerForStatement?.name || selectedCustomerForStatement?.full_name }}</span>
            </div>
            <div class="flex justify-between items-center py-2 border-b border-white/5 print:border-black/10">
              <span class="text-muted print:text-black/70 text-xs font-black">القسم والنظام</span>
              <span class="font-bold">{{ getModuleLabel(selectedReceipt.module) }}</span>
            </div>
            <div class="flex justify-between items-center py-2">
              <span class="text-muted print:text-black/70 text-xs font-black">بواسطة الموظف</span>
              <span class="font-bold text-xs">{{ selectedReceipt.user_name || 'النظام' }}</span>
            </div>
          </div>

          <!-- Description -->
          <div class="mt-6">
            <span class="text-[10px] font-black text-gold print:text-black block uppercase tracking-widest mb-2">البيان الأساسي</span>
            <p class="text-sm font-bold bg-white/[0.02] print:bg-gray-50 border border-white/5 p-4 rounded-xl leading-relaxed">{{ selectedReceipt.description }}</p>
          </div>

          <!-- Booking Details -->
          <div v-if="selectedReceipt.booking_details && Object.keys(selectedReceipt.booking_details).length" class="mt-6 pt-6 border-t border-white/10 print:border-black/20">
            <span class="text-[10px] font-black text-gold print:text-black block uppercase tracking-widest mb-4">تفاصيل الحجز المرتبط</span>
            
            <div class="space-y-2 text-sm bg-white/5 print:bg-transparent p-5 rounded-2xl print:border print:border-black/20">
              <div v-if="selectedReceipt.booking_details.pnr" class="flex justify-between items-center py-1.5">
                <span class="text-muted print:text-black/70 text-xs font-black">رقم الحجز PNR</span>
                <span class="font-mono font-bold text-sky-400 print:text-blue-700">{{ selectedReceipt.booking_details.pnr }}</span>
              </div>
              <div v-if="selectedReceipt.booking_details.provider_name" class="flex justify-between items-center py-1.5 border-t border-white/5 print:border-black/10">
                <span class="text-muted print:text-black/70 text-xs font-black">المزود / الشركة</span>
                <span class="font-bold">{{ selectedReceipt.booking_details.provider_name }}</span>
              </div>
              <div v-if="selectedReceipt.booking_details.route" class="flex justify-between items-center py-1.5 border-t border-white/5 print:border-black/10">
                <span class="text-muted print:text-black/70 text-xs font-black">خط السير / الوجهة</span>
                <span class="font-bold text-left" dir="ltr">{{ selectedReceipt.booking_details.route }}</span>
              </div>
              <div v-if="selectedReceipt.booking_details.status" class="flex justify-between items-center py-1.5 border-t border-white/5 print:border-black/10">
                <span class="text-muted print:text-black/70 text-xs font-black">حالة الحجز</span>
                <span class="font-bold uppercase">{{ selectedReceipt.booking_details.status }}</span>
              </div>
              <div v-if="selectedReceipt.booking_details.passengers" class="flex flex-col py-1.5 border-t border-white/5 print:border-black/10">
                <span class="text-muted print:text-black/70 text-xs font-black mb-1.5">المسافرون</span>
                <span class="font-bold leading-relaxed whitespace-pre-wrap text-xs bg-black/20 print:bg-transparent p-3 rounded-lg">{{ selectedReceipt.booking_details.passengers }}</span>
              </div>
            </div>
          </div>

          <div class="grid grid-cols-2 gap-4 pt-12 text-center text-xs text-muted print:text-black/70 font-bold">
            <div>توقيع الموظف <br><br> .......................</div>
            <div>توقيع العميل / ختم الشركة <br><br> .......................</div>
          </div>
        </div>

        <div class="p-4 bg-white/5 border-t border-white/10 print:hidden text-center shrink-0">
          <button @click="printReceipt" class="btn-airline px-8 py-3 font-black inline-flex items-center justify-center gap-2 w-full shadow-lg text-sm rounded-xl">
            <Printer class="w-4 h-4" />
            طباعة السند
          </button>
        </div>
      </div>
    </div>
</template>

<script setup>
import { ref, reactive, onMounted, computed, watch } from 'vue';
import { useCustomerStore } from '@/stores/customerStore';
import {
  Search,
  Users,
  Plus,
  Pen,
  Trash2,
  Building2,
  Phone,
  MapPin,
  Globe2,
  Check,
  DollarSign,
  MessageSquare,
  Eye,
  X,
  FileText,
  Printer,
  Filter,
  Plane,
  Bus,
  Compass,
  LayoutGrid
} from 'lucide-vue-next';
import { useDebounceFn } from '@vueuse/core';
import axios from 'axios';

const store = useCustomerStore();

// UI State
const activeTab = ref('regular'); // 'regular' for counter/individual, 'counter' for companies
const searchQuery = ref('');
const showModal = ref(false);
const isEditMode = ref(false);
const editingCustomerId = ref(null);
const saving = ref(false);
const loadingList = ref(false);

// Statement Modal State
const showStatementModal = ref(false);
const loadingStatement = ref(false);
const selectedCustomerForStatement = ref(null);
const statementItems = ref([]);
const statementStats = ref({
  opening_balance: 0,
  period_credit: 0,
  period_debit: 0,
  closing_balance: 0
});
const statementFilters = reactive({
  search: '',
  from_date: '',
  to_date: '',
  module: ''
});
const selectedReceipt = ref(null);

// Local lists
const customers = ref([]);
const pagination = ref({
  total: 0,
  currentPage: 1,
  lastPage: 1,
  perPage: 15
});

// Stats
const stats = reactive({
  totalDebt: 0,
  counterCount: 0,
  companiesCount: 0
});

// Form state
const form = ref({
  full_name: '',
  phone: '',
  national_id: '',
  whatsapp_number: '',
  city: '',
  travel_country: '',
  affiliation: '',
  notes: ''
});

// Initials helper
const getInitials = (name) => {
  if (!name) return '?';
  return name.trim().split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2);
};

// WhatsApp link helper
const formatWhatsApp = (phone) => {
  if (!phone) return '';
  // strip all non-numeric characters
  let clean = phone.replace(/\D/g, '');
  // if starts with 0 and length is 11 (Egypt phone), prefix with 2
  if (clean.startsWith('0') && clean.length === 11) {
    clean = '2' + clean;
  }
  return clean;
};

// Currency helper
const formatCurrency = (val) => {
  return (parseFloat(val) || 0).toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  }) + ' جنيه';
};

// Fetch customers from API
const fetchCustomersList = async (page = 1) => {
  loadingList.value = true;
  try {
    await store.fetchCustomers({
      type: activeTab.value,
      search: searchQuery.value,
      page: page,
      per_page: 15
    });
    customers.value = store.customers;
    pagination.value = store.pagination;
  } catch (error) {
    console.error('Failed to load customers list', error);
  } finally {
    loadingList.value = false;
  }
};

// Load Stats
const fetchStats = async () => {
  try {
    // Eagerly fetch counts & debts by requesting a summary or fetching all from DB
    const res = await axios.get('/api/v1/customers', { params: { per_page: 1000 } });
    const items = res.data?.data?.items || res.data?.data || [];
    
    let debtSum = 0;
    let regulars = 0;
    let counters = 0;
    
    items.forEach(c => {
      // type individual = regular, type company = counter
      const isCompany = c.type === 'company' || c.type === 'counter';
      if (isCompany) {
        counters++;
      } else {
        regulars++;
      }
      
      const bal = parseFloat(c.balance || 0);
      if (bal > 0) {
        debtSum += bal;
      }
    });
    
    stats.totalDebt = debtSum;
    stats.counterCount = regulars;
    stats.companiesCount = counters;
  } catch (error) {
    console.error('Failed to load stats', error);
  }
};

// Tab switching
const changeTab = (tab) => {
  activeTab.value = tab;
  searchQuery.value = '';
  pagination.value.currentPage = 1;
  fetchCustomersList(1);
};

// Page switching
const changePage = (page) => {
  fetchCustomersList(page);
};

// Search handling with debounce
const onSearch = useDebounceFn(() => {
  fetchCustomersList(1);
}, 350);

// Modal opening / closing
const openCreateModal = () => {
  isEditMode.value = false;
  editingCustomerId.value = null;
  form.value = {
    full_name: '',
    phone: '',
    national_id: '',
    whatsapp_number: '',
    city: '',
    travel_country: '',
    affiliation: '',
    notes: ''
  };
  showModal.value = true;
};

const editCustomer = (customer) => {
  isEditMode.value = true;
  editingCustomerId.value = customer.id;
  form.value = {
    full_name: customer.full_name || customer.name,
    phone: customer.phone || '',
    national_id: customer.national_id || '',
    whatsapp_number: customer.whatsapp_number || '',
    city: customer.city || '',
    travel_country: customer.travel_country || '',
    affiliation: customer.affiliation || '',
    notes: customer.notes || ''
  };
  showModal.value = true;
};

const closeModal = () => {
  showModal.value = false;
  editingCustomerId.value = null;
};

// Create / Update action
const saveCustomer = async () => {
  if (!form.value.full_name || !form.value.phone) {
    store.addToast('يرجى كتابة الاسم ورقم الهاتف', 'error');
    return;
  }

  saving.value = true;
  try {
    const payload = {
      ...form.value,
      type: activeTab.value // 'regular' or 'counter' which maps to 'individual' or 'company' in backend
    };

    if (isEditMode.value) {
      await store.updateCustomer(editingCustomerId.value, payload);
    } else {
      await store.createCustomer(payload);
    }

    closeModal();
    fetchCustomersList(pagination.value.currentPage);
    fetchStats();
  } catch (error) {
    console.error('Failed to save customer', error);
  } finally {
    saving.value = false;
  }
};

// Delete Customer
const deleteCustomer = async (customer) => {
  if (!confirm(`هل أنت متأكد من حذف العميل: ${customer.name || customer.full_name}؟`)) return;
  try {
    await store.deleteCustomer(customer.id);
    fetchCustomersList(pagination.value.currentPage);
    fetchStats();
  } catch (error) {
    console.error('Failed to delete customer', error);
  }
};

// Statement logic
const viewCustomerStatement = async (customer) => {
  selectedCustomerForStatement.value = customer;
  showStatementModal.value = true;
  loadingStatement.value = true;
  statementFilters.search = '';
  statementFilters.from_date = '';
  statementFilters.to_date = '';
  statementFilters.module = '';
  statementItems.value = [];
  
  try {
    const response = await axios.get(`/api/v1/customers/${customer.id}/statement`);
    const data = response.data?.data;
    if (data) {
      statementItems.value = data.items || [];
      statementStats.value = data.stats || {
        opening_balance: 0,
        period_credit: 0,
        period_debit: 0,
        closing_balance: 0
      };
    }
  } catch (error) {
    console.error('Failed to load statement', error);
    store.addToast('حدث خطأ أثناء تحميل كشف الحساب', 'error');
  } finally {
    loadingStatement.value = false;
  }
};

const closeStatementModal = () => {
  showStatementModal.value = false;
  selectedCustomerForStatement.value = null;
};

const openReceipt = (item) => {
  selectedReceipt.value = item;
};

const printReceipt = () => {
  setTimeout(() => {
    window.print();
  }, 100);
};

const filteredStatementItems = computed(() => {
  return statementItems.value.filter(item => {
    // text search
    if (statementFilters.search) {
      const q = statementFilters.search.toLowerCase();
      const matchDesc = item.description?.toLowerCase().includes(q);
      const matchRef = item.reference_id?.toLowerCase().includes(q);
      const matchPnr = item.booking_details?.pnr?.toLowerCase().includes(q);
      if (!matchDesc && !matchRef && !matchPnr) return false;
    }
    // module filter
    if (statementFilters.module && item.module !== statementFilters.module) {
      return false;
    }
    // date filters
    if (statementFilters.from_date) {
      const itemDate = new Date(item.created_at.split(' ')[0]);
      const fromDate = new Date(statementFilters.from_date);
      if (itemDate < fromDate) return false;
    }
    if (statementFilters.to_date) {
      const itemDate = new Date(item.created_at.split(' ')[0]);
      const toDate = new Date(statementFilters.to_date);
      if (itemDate > toDate) return false;
    }
    return true;
  });
});

const getModuleIcon = (module) => {
  switch (module) {
    case 'flight': return Plane;
    case 'bus': return Bus;
    case 'hajj_umra': return Compass;
    case 'visa': return FileText;
    case 'online': return Globe2;
    default: return LayoutGrid;
  }
};

const getModuleLabel = (module) => {
  switch (module) {
    case 'flight': return 'طيران';
    case 'bus': return 'باصات';
    case 'hajj_umra': return 'حج وعمرة';
    case 'visa': return 'تأشيرات';
    case 'online': return 'أونلاين';
    default: return 'عام';
  }
};

// Initial mount
onMounted(() => {
  fetchCustomersList(1);
  fetchStats();
});
</script>

<style scoped>
.bg-card { background-color: var(--card-bg); }
.bg-input { background-color: var(--input-bg); }
.text-muted { color: var(--text-muted); }
.text-gold { color: var(--gold); }
.bg-gold { background-color: var(--gold); }
.text-error { color: var(--error); }
.bg-error { background-color: var(--error); }
.text-success { color: var(--success); }
.bg-success { background-color: var(--success); }

.btn-airline {
  background: linear-gradient(to right, #d4a843, #f59e0b);
  color: #000;
  border-radius: 1rem;
  transition: all 0.3s ease;
  cursor: pointer;
}
.btn-airline:hover {
  transform: scale(1.02);
}

@media print {
  body * {
    visibility: hidden;
  }
  .print\:static, .print\:static * {
    visibility: visible;
  }
  .print\:static {
    position: absolute;
    left: 0;
    top: 0;
    width: 100%;
    margin: 0;
    padding: 0;
  }
}
</style>
