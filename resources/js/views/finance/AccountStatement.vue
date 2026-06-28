<template>
  <div class="space-y-8 animate-in fade-in pb-10 duration-700" :class="{ 'print:hidden': selectedEntryDetails }">
    <!-- Professional Print Header (Visible only on print) -->
    <div class="hidden print:block print:mb-10">
      <div class="flex items-center justify-between border-b-2 border-black pb-6">
        <div class="flex items-center gap-4">
          <img v-if="printSettingsStore.settings.logo_url" :src="printSettingsStore.settings.logo_url" class="h-16 object-contain" style="max-height: 64px;" />
          <div>
            <h2 class="text-3xl font-black text-black">{{ printSettingsStore.settings.company_name_ar || 'سفري علينا' }}</h2>
            <p class="text-sm font-bold text-black mt-1">للتسويق السياحي والخدمات الإلكترونية</p>
          </div>
        </div>
        <div class="text-right">
          <h1 class="text-2xl font-black text-black">كشف حساب تفصيلي</h1>
          <p class="text-xs font-bold text-black mt-1">تاريخ الطباعة: {{ new Date().toLocaleString('ar-EG') }}</p>
        </div>
      </div>
      
      <div class="mt-8 grid grid-cols-2 gap-8 text-sm">
        <div class="space-y-1">
          <template v-if="statementTargetType === 'account'">
            <p><span class="font-black">اسم الحساب:</span> {{ account?.name }}</p>
            <p><span class="font-black">نوع الحساب:</span> {{ account?.type_label }}</p>
            <p><span class="font-black">العملة:</span> {{ account?.currency }}</p>
          </template>
          <template v-else>
            <p><span class="font-black">اسم العميل/الشركة:</span> {{ selectedCustomer?.full_name }}</p>
            <p><span class="font-black">رقم الهاتف:</span> {{ selectedCustomer?.phone || '—' }}</p>
            <p><span class="font-black">نوع العميل:</span> {{ selectedCustomer?.type === 'counter' ? 'شركة كاونتر' : 'عميل أفراد' }}</p>
          </template>
        </div>
        <div class="space-y-1 text-right">
          <p v-if="filters.from_date || filters.to_date">
            <span class="font-black">الفترة:</span> 
            {{ filters.from_date || 'البداية' }} إلى {{ filters.to_date || 'اليوم' }}
          </p>
          <div class="grid grid-cols-2 gap-x-4 gap-y-1 mt-2 border-t border-black pt-2">
            <template v-if="statementTargetType === 'account'">
              <p><span class="font-bold">رصيد أول المدة:</span> {{ formatCurrency(stats.opening_balance, account?.currency) }}</p>
              <p><span class="font-bold">رصيد آخر المدة:</span> {{ formatCurrency(stats.closing_balance, account?.currency) }}</p>
              <p><span class="font-bold">إجمالي الإيداع:</span> {{ formatCurrency(stats.period_credit, account?.currency) }}</p>
              <p><span class="font-bold">إجمالي السحب:</span> {{ formatCurrency(stats.period_debit, account?.currency) }}</p>
            </template>
            <template v-else>
              <p><span class="font-bold">إجمالي المبيعات (عليه):</span> {{ formatCurrency(stats.period_credit) }}</p>
              <p><span class="font-bold">إجمالي المسدد (له):</span> {{ formatCurrency(stats.period_debit) }}</p>
              <p class="col-span-2 text-base mt-1"><span class="font-black">صافي الرصيد المستحق:</span> <strong :class="stats.closing_balance > 0 ? 'text-red-600' : 'text-green-600'">{{ formatCurrency(Math.abs(stats.closing_balance)) }} {{ stats.closing_balance > 0 ? 'عليه (مدين)' : (stats.closing_balance < 0 ? 'له (دائن)' : '') }}</strong></p>
            </template>
          </div>
        </div>
      </div>
    </div>


    <div class="statement-page mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8 print:px-0">
      <!-- Header Section -->
      <div class="flex flex-col gap-6 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-5">
          <button
            type="button"
            class="group rounded-2xl border border-white/10 bg-white/5 p-3 text-text-muted transition-all hover:bg-white/10 hover:text-gold active:scale-95 print:hidden"
            @click="router.back()"
          >
            <ArrowLeft class="h-6 w-6 transition-transform group-hover:-translate-x-1" />
          </button>
          <div>
            <div class="flex items-center gap-3">
              <p class="text-sm font-black uppercase tracking-[0.15em] text-gold/90">سجل المعاملات المالية</p>
              <div class="h-px w-8 bg-gold/30"></div>
            </div>
            <h1 class="font-display mt-1 text-3xl font-black tracking-tight text-text-main sm:text-4xl">كشف الحساب التفصيلي</h1>
            <p v-if="statementTargetType === 'account' && account" class="mt-2 text-lg font-black text-gold">
              {{ account.name }} · {{ account.currency }}
            </p>
          </div>
        </div>
        
        <div class="flex items-center gap-3 print:hidden">
          <button
            @click="printStatement"
            class="flex items-center gap-2 px-4 py-2.5 rounded-xl border border-rose-500/20 bg-rose-500/5 text-rose-300 hover:text-white hover:bg-rose-500/20 transition-all font-bold text-sm"
            title="تصدير كشف الحساب بصيغة PDF"
          >
            <FileText class="w-4 h-4 text-rose-400" />
            تصدير PDF
          </button>

          <button
            @click="exportExcel"
            class="flex items-center gap-2 px-4 py-2.5 rounded-xl border border-emerald-500/20 bg-emerald-500/5 text-emerald-300 hover:text-white hover:bg-emerald-500/20 transition-all font-bold text-sm"
            title="تصدير كشف الحساب بصيغة Excel"
          >
            <Download class="w-4 h-4 text-emerald-400" />
            تصدير Excel
          </button>

          <button
            @click="printStatement"
            class="flex items-center gap-2 px-4 py-2.5 rounded-xl border border-white/10 bg-white/5 text-text-muted hover:text-white hover:bg-white/10 transition-all font-bold text-sm"
            title="طباعة كشف الحساب"
          >
            <Printer class="w-4 h-4" />
            طباعة
          </button>
          
          <button
            v-if="statementTargetType === 'account' && account"
            @click="openQuickTransactionModal"
            class="px-5 py-3.5 rounded-xl border border-gold/30 bg-gold/10 text-gold hover:bg-gold/20 transition-all font-black text-sm flex items-center gap-2"
          >
            <Banknote class="w-5 h-5" />
            إيداع / سحب سريع
          </button>

          <button
            v-if="statementTargetType === 'account' && account"
            @click="showTransferModal = true"
            class="btn-airline inline-flex items-center gap-2 px-6 py-3.5 text-sm font-black shadow-2xl shadow-gold/10 hover:shadow-gold/20 transition-all duration-500"
          >
            <ArrowRightLeft class="w-5 h-5" />
            تحويل أرصدة
          </button>
        </div>
      </div>

      <!-- Switcher Tabs -->
      <div class="mt-8 flex items-center justify-center gap-4 border-b border-white/10 pb-6 print:hidden">
        <button
          type="button"
          @click="setTargetType('account')"
          class="px-6 py-3.5 rounded-2xl font-black text-base transition-all duration-500 relative overflow-hidden flex items-center gap-2.5"
          :class="statementTargetType === 'account' ? 'bg-gradient-to-r from-gold to-amber-500 text-black shadow-xl shadow-gold/20 scale-105' : 'bg-white/5 text-text-muted hover:bg-white/10 hover:text-white'"
        >
          <Wallet class="w-4 h-4" />
          <span>حسابات الخزائن والبنوك</span>
        </button>
        <button
          type="button"
          @click="setTargetType('customer')"
          class="px-6 py-3.5 rounded-2xl font-black text-base transition-all duration-500 relative overflow-hidden flex items-center gap-2.5"
          :class="statementTargetType === 'customer' ? 'bg-gradient-to-r from-gold to-amber-500 text-black shadow-xl shadow-gold/20 scale-105' : 'bg-white/5 text-text-muted hover:bg-white/10 hover:text-white'"
        >
          <User class="w-4 h-4" />
          <span>كشوفات العملاء والشركات</span>
        </button>
      </div>

      <!-- Account Stats Card (Bank Style) -->
      <div v-if="statementTargetType === 'account' && account" class="mt-10 relative group print:hidden">
        <div class="absolute -inset-0.5 bg-gradient-to-r from-gold/20 to-emerald-500/20 rounded-[2rem] blur opacity-30 group-hover:opacity-50 transition duration-1000"></div>
        <div class="relative flight-panel !p-0 overflow-hidden border border-white/10 bg-black/60 backdrop-blur-3xl shadow-2xl">
          <div class="grid grid-cols-1 md:grid-cols-4 divide-y md:divide-y-0 md:divide-x md:divide-x-reverse divide-white/5">
            <!-- Opening Balance -->
            <div class="p-8 space-y-4 bg-white/[0.01]">
              <div class="flex items-center gap-3 text-sky-400">
                <div class="p-2 rounded-lg bg-sky-400/10">
                  <History class="w-5 h-5" />
                </div>
                <span class="text-sm font-black">رصيد أول المدة</span>
              </div>
              <p class="font-mono text-3xl font-black text-text-main">{{ formatCurrency(stats.opening_balance, account.currency) }}</p>
            </div>

            <!-- Period Credit -->
            <div class="p-8 space-y-4 bg-white/[0.01]">
              <div class="flex items-center gap-3 text-success">
                <div class="p-2 rounded-lg bg-success/10">
                  <TrendingUp class="w-5 h-5" />
                </div>
                <span class="text-sm font-black">إجمالي الإيداعات</span>
              </div>
              <p class="font-mono text-3xl font-black text-success">{{ formatCurrency(stats.period_credit, account.currency) }}</p>
            </div>

            <!-- Period Debit -->
            <div class="p-8 space-y-4 bg-white/[0.01]">
              <div class="flex items-center gap-3 text-error">
                <div class="p-2 rounded-lg bg-error/10">
                  <TrendingDown class="w-5 h-5" />
                </div>
                <span class="text-sm font-black">إجمالي المسحوبات</span>
              </div>
              <p class="font-mono text-3xl font-black text-error">{{ formatCurrency(stats.period_debit, account.currency) }}</p>
            </div>

            <!-- Period Closing/Current Balance -->
            <div class="p-8 space-y-4 bg-gold/[0.03]">
              <div class="flex items-center gap-3 text-gold">
                <div class="p-2 rounded-lg bg-gold/10">
                  <Wallet class="w-5 h-5" />
                </div>
                <span class="text-sm font-black">رصيد آخر المدة</span>
              </div>
              <p class="font-mono text-4xl font-black" :class="stats.closing_balance >= 0 ? 'text-success' : 'text-error'">
                {{ formatCurrency(stats.closing_balance, account?.currency) }}
              </p>
            </div>
          </div>
        </div>
      </div>

      <!-- Customer Stats Card -->
      <div v-else-if="statementTargetType === 'customer' && selectedCustomer" class="mt-10 relative group print:hidden">
        <div class="absolute -inset-0.5 bg-gradient-to-r from-amber-500/20 to-gold/20 rounded-[2rem] blur opacity-30 group-hover:opacity-50 transition duration-1000"></div>
        <div class="relative flight-panel !p-0 overflow-hidden border border-white/10 bg-black/60 backdrop-blur-3xl shadow-2xl">
          <div class="p-6 bg-white/[0.03] border-b border-white/5 flex flex-col sm:flex-row items-center justify-between gap-4">
            <div class="flex items-center gap-4">
              <div class="w-12 h-12 rounded-2xl bg-gold/10 flex items-center justify-center text-gold border border-gold/20">
                <User class="w-6 h-6" />
              </div>
              <div>
                <div class="flex items-center gap-2">
                  <h3 class="text-xl font-black text-white">{{ selectedCustomer.full_name }}</h3>
                  <span class="text-[10px] px-2 py-0.5 rounded bg-white/10 text-gold font-bold">
                    {{ selectedCustomer.type === 'counter' ? 'شركة كاونتر' : 'عميل أفراد' }}
                  </span>
                </div>
                <p class="text-xs text-text-muted mt-1 font-bold">{{ selectedCustomer.phone || 'بدون هاتف' }}</p>
              </div>
            </div>
            <button
              @click="clearCustomerSelection"
              class="px-4 py-2 rounded-xl border border-white/10 bg-white/5 hover:bg-white/10 text-xs font-bold text-text-muted hover:text-white transition-all duration-300 flex items-center gap-2"
            >
              <Search class="w-3.5 h-3.5" />
              تغيير العميل / الشركة
            </button>
          </div>
          <div class="grid grid-cols-1 md:grid-cols-3 divide-y md:divide-y-0 md:divide-x md:divide-x-reverse divide-white/5">
            <!-- Period Debit (Sales) -->
            <div class="p-6 space-y-2 bg-white/[0.01]">
              <span class="text-[11px] font-black text-text-muted block">إجمالي المبيعات (عليه)</span>
              <p class="font-mono text-2xl font-black text-error">{{ formatCurrency(stats.period_credit) }}</p>
            </div>
            <!-- Period Credit (Paid) -->
            <div class="p-6 space-y-2 bg-white/[0.01]">
              <span class="text-[11px] font-black text-text-muted block">إجمالي المسدد (له)</span>
              <p class="font-mono text-2xl font-black text-success">{{ formatCurrency(stats.period_debit) }}</p>
            </div>
            <!-- Closing Balance (Net) -->
            <div class="p-6 space-y-2 bg-gold/[0.03]">
              <span class="text-sm font-black text-gold block">صافي الرصيد المستحق</span>
              <p class="font-mono text-3xl font-black" :class="stats.closing_balance > 0 ? 'text-error' : (stats.closing_balance < 0 ? 'text-success' : 'text-white')">
                {{ formatCurrency(Math.abs(stats.closing_balance)) }}
                <span v-if="stats.closing_balance > 0" class="text-sm text-error font-bold inline-block mr-1">عليه (مدين)</span>
                <span v-else-if="stats.closing_balance < 0" class="text-sm text-success font-bold inline-block mr-1">له (دائن)</span>
              </p>
            </div>
          </div>
        </div>
      </div>


      <!-- Selection View Section -->
      <div v-if="statementTargetType === 'account' ? !account : !selectedCustomer" class="mt-10 flight-panel text-center py-16 border-dashed border-white/10 print:hidden">
        <!-- Account Selection Panel -->
        <div v-if="statementTargetType === 'account'" class="max-w-md mx-auto space-y-8 animate-in fade-in duration-500">
          <div class="w-20 h-20 bg-gold/10 rounded-3xl flex items-center justify-center text-gold mx-auto border border-gold/20 shadow-2xl shadow-gold/5">
            <CreditCard class="w-10 h-10" />
          </div>
          <div>
            <h2 class="text-2xl font-black text-text-main">كشف الحسابات المالية</h2>
            <p class="text-text-muted mt-2 font-bold">يرجى تحديد القسم ثم الحساب المالي لعرض سجل العمليات.</p>
          </div>
          
          <div class="space-y-4">
            <!-- Division Selection -->
            <div class="relative group text-right">
              <label for="accountModuleTypeFilter" class="text-[10px] font-black text-text-muted uppercase tracking-widest px-1 mb-1 block">القسم الرئيسي (سياحة / مكتب)</label>
              <select id="accountModuleTypeFilter" name="accountModuleTypeFilter" v-model="accountModuleTypeFilter" @change="onModuleTypeFilterChange" class="flight-select w-full !pl-12 font-bold bg-black border-gold/30">
                <option value="">كل الأقسام الرئيسية</option>
                <option value="tourism">قسم السياحة</option>
                <option value="office">قسم المكتب</option>
              </select>
              <div class="absolute left-4 bottom-3.5 text-gold">
                <Globe class="w-5 h-5" />
              </div>
            </div>

            <!-- Module Selection -->
            <div class="relative group text-right">
              <label for="accountModuleFilter" class="text-[10px] font-black text-text-muted uppercase tracking-widest px-1 mb-1 block">الموديول / القسم الفرعي</label>
              <select id="accountModuleFilter" name="accountModuleFilter" v-model="accountModuleFilter" @change="onModuleFilterChange" class="flight-select w-full !pl-12 font-bold bg-black border-gold/30">
                <option value="">كل الموديولات</option>
                <option v-for="m in availableAccountModules" :key="m.value" :value="m.value">{{ m.label }}</option>
              </select>
              <div class="absolute left-4 bottom-3.5 text-gold">
                <LayoutGrid class="w-5 h-5" />
              </div>
            </div>

            <!-- Account Selection -->
            <div class="relative group text-right">
              <label for="selectedAccountId" class="text-[10px] font-black text-text-muted uppercase tracking-widest px-1 mb-1 block">اختر الحساب المالي (المحفظة)</label>
              <select id="selectedAccountId" name="selectedAccountId" 
                v-model="selectedAccountId"
                @change="handleAccountChange"
                class="flight-select w-full !pl-12 font-black bg-black border-gold/30 focus:border-gold"
              >
                <option value="" disabled>اختر الحساب لعرض كشفه...</option>
                <option v-for="acc in availableAccounts" :key="acc.id" :value="acc.id">
                  {{ acc.name }} ({{ acc.currency }})
                </option>
              </select>
              <div class="absolute left-4 bottom-3.5 text-gold">
                <Search class="w-6 h-6" />
              </div>
            </div>

            <p v-if="(accountModuleTypeFilter || accountModuleFilter) && !availableAccounts.length" class="text-xs text-error font-bold italic animate-in fade-in">
              لا توجد حسابات مفعّلة مرتبطة بهذا الاختيار حالياً.
            </p>
          </div>
        </div>

        <!-- Customer Selection Panel -->
        <div v-else class="max-w-md mx-auto space-y-8 animate-in fade-in duration-500">
          <div class="w-20 h-20 bg-amber-500/10 rounded-3xl flex items-center justify-center text-amber-500 mx-auto border border-amber-500/20 shadow-2xl shadow-amber-500/5">
            <User class="w-10 h-10" />
          </div>
          <div>
            <h2 class="text-2xl font-black text-text-main">كشوفات العملاء والشركات</h2>
            <p class="text-text-muted mt-2 font-bold">حدد الفئة ثم اختر العميل/الشركة لاستعراض السجل المحاسبي الكامل.</p>
          </div>
          
          <div class="space-y-4">
            <!-- Customer Type Dropdown -->
            <div class="relative group text-right">
              <label for="selectedCustomerType" class="text-[10px] font-black text-text-muted uppercase tracking-widest px-1 mb-1 block">فئة العميل</label>
              <select id="selectedCustomerType" name="selectedCustomerType" v-model="selectedCustomerType" class="flight-select w-full !pl-12 font-bold bg-black border-gold/30">
                <option value="">كل الفئات (أفراد وشركات)</option>
                <option value="regular">عملاء أفراد (Regular)</option>
                <option value="counter">شركات كاونتر (Counter)</option>
              </select>
              <div class="absolute left-4 bottom-3.5 text-gold">
                <User class="w-5 h-5" />
              </div>
            </div>

            <!-- Customer Search/Select Dropdown -->
            <div class="relative group text-right">
              <label for="targetCustomerId" class="text-[10px] font-black text-text-muted uppercase tracking-widest px-1 mb-1 block">اختر العميل / الشركة</label>
              <select id="targetCustomerId" name="targetCustomerId" 
                v-model="targetCustomerId"
                @change="handleCustomerSelectChange"
                class="flight-select w-full !pl-12 font-black bg-black border-gold/30 focus:border-gold"
              >
                <option value="" disabled>اختر العميل لعرض كشف الحساب...</option>
                <option v-for="cust in availableCustomers" :key="cust.id" :value="cust.id">
                  {{ cust.full_name }} {{ cust.phone ? `(${cust.phone})` : '' }}
                </option>
              </select>
              <div class="absolute left-4 bottom-3.5 text-gold">
                <Search class="w-6 h-6" />
              </div>
            </div>

            <p v-if="!availableCustomers.length" class="text-xs text-text-muted/50 font-bold italic">
              جاري تحميل قائمة العملاء...
            </p>
          </div>
        </div>
      </div>

      <!-- Filters & Search -->
      <div class="flight-panel mt-2 print:hidden">
        <h3 class="mb-4 text-lg font-black text-text-main">تصفية كشف الحساب</h3>
        <div class="grid grid-cols-1 md:grid-cols-12 gap-4 items-end">
        <div class="md:col-span-4 relative group">
          <Search class="absolute right-4 top-1/2 -translate-y-1/2 w-4 h-4 text-text-muted group-focus-within:text-gold transition-colors" />
          <input id="filtersSearch" name="filtersSearch"
            v-model="filters.search"
            type="text"
            placeholder="البحث في الوصف، الاسم، أو رقم الحجز..."
            class="stmt-filter-input flight-input w-full pr-12 font-bold"
            @input="debounceFetch"
          />
        </div>
        
        <div class="md:col-span-2 space-y-1.5">
          <label for="filtersModule" class="stmt-filter-label">الموديول / القسم</label>
          <select id="filtersModule" name="filtersModule" v-model="filters.module" class="stmt-filter-select flight-select w-full" @change="fetchStatement">
            <option value="">كل التفاصيل</option>
            <option v-for="m in financeStore.meta.transactionModules" :key="m.value" :value="m.value">{{ m.label }}</option>
          </select>
        </div>

        <div class="md:col-span-2 space-y-1.5">
          <label for="filtersType" class="stmt-filter-label">نوع الحركة</label>
          <select id="filtersType" name="filtersType" v-model="filters.type" class="stmt-filter-select flight-select w-full" @change="fetchStatement">
            <option value="">الكل (إيداع وسحب)</option>
            <option value="credit">إيداعات فقط (+)</option>
            <option value="debit">مسحوبات فقط (-)</option>
          </select>
        </div>

        <div class="md:col-span-3 grid grid-cols-2 gap-2">
          <div class="space-y-1.5">
            <label for="filtersFromDate" class="stmt-filter-label">من تاريخ</label>
            <input id="filtersFromDate" name="filtersFromDate" v-model="filters.from_date" type="date" class="stmt-filter-input flight-input w-full" @change="fetchStatement" />
          </div>
          <div class="space-y-1.5">
            <label for="filtersToDate" class="stmt-filter-label">إلى تاريخ</label>
            <input id="filtersToDate" name="filtersToDate" v-model="filters.to_date" type="date" class="stmt-filter-input flight-input w-full" @change="fetchStatement" />
          </div>
        </div>

        <div class="md:col-span-1">
          <button 
            @click="resetFilters"
            class="w-full p-2.5 rounded-xl border border-white/5 bg-white/5 text-text-muted hover:text-text-main transition-all flex items-center justify-center"
            title="إعادة ضبط"
          >
            <RotateCcw class="w-5 h-5" />
          </button>
        </div>
      </div>

      <!-- Main Statement Table -->
      <div class="flight-panel !p-0 overflow-hidden border border-white/5 shadow-2xl relative">
        <!-- Loading Overlay -->
        <div v-if="loading" class="absolute inset-0 z-10 bg-black/40 backdrop-blur-[2px] flex items-center justify-center animate-in fade-in duration-300">
          <div class="flex flex-col items-center gap-4">
            <div class="w-12 h-12 border-4 border-gold/20 border-t-gold rounded-full animate-spin"></div>
            <p class="text-base font-black text-gold">جاري تحديث السجل...</p>
          </div>
        </div>

        <div class="overflow-x-auto">
          <table class="stmt-table w-full text-right border-collapse">
            <thead>
              <tr class="bg-white/[0.03] border-b border-white/5">
                <th class="px-6 py-5 whitespace-nowrap">التاريخ</th>
                <th class="px-6 py-5 whitespace-nowrap">الحركة / الموظف</th>
                <th class="px-6 py-5 whitespace-nowrap">المرجع / PNR</th>
                <th class="px-6 py-5 min-w-[300px]">البيان والوصف</th>
                <th class="px-6 py-5 text-left whitespace-nowrap">خصم / مدين</th>
                <th class="px-6 py-5 text-left whitespace-nowrap">إضافة / دائن</th>
                <th class="px-6 py-5 text-left whitespace-nowrap">الرصيد بعد</th>
                <th class="px-6 py-5 text-center print:hidden whitespace-nowrap">إجراءات</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-white/5">
              <tr v-if="!statement.length && !loading">
                <td colspan="8" class="px-6 py-20 text-center">
                  <div class="flex flex-col items-center gap-4 opacity-30">
                    <History class="w-16 h-16" />
                    <p class="text-lg font-black">لا توجد حركات مالية مسجلة لهذا الحساب في الفترة المحددة</p>
                  </div>
                </td>
              </tr>
              <template v-for="(entry, idx) in statement" :key="entry.id || entry.transaction_id || idx">
                <tr 
                  class="hover:bg-white/[0.02] transition-colors group cursor-pointer"
                  :class="{ 'animate-in slide-in-from-right-4 fade-in duration-500': true }"
                  :style="{ animationDelay: `${idx * 30}ms` }"
                  @click="toggleRow(entry.id || entry.transaction_id)"
                >
                  <!-- Col 1: Date -->
                  <td class="px-6 py-5 text-base font-black text-text-main whitespace-nowrap">{{ entry.date_human || formatDate(entry.created_at || entry.date) }}</td>
                  
                  <!-- Col 2: Process / Employee -->
                  <td class="px-6 py-4 whitespace-nowrap">
                    <div class="flex flex-col gap-1">
                      <span class="text-base font-black text-text-main flex items-center gap-2">
                        <span class="p-1 rounded bg-white/5 text-gold">
                          <Plane v-if="entry.module === 'flight'" class="w-3.5 h-3.5" />
                          <Compass v-else-if="entry.module === 'hajj_umra'" class="w-3.5 h-3.5" />
                          <FileText v-else-if="entry.module === 'visa'" class="w-3.5 h-3.5" />
                          <Bus v-else-if="entry.module === 'bus'" class="w-3.5 h-3.5" />
                          <Globe v-else-if="entry.module === 'online'" class="w-3.5 h-3.5" />
                          <Wallet v-else class="w-3.5 h-3.5" />
                        </span>
                        <span>{{ getModuleLabel(entry.module || account?.module || 'general') }}</span>
                      </span>
                      <span class="text-sm font-bold text-text-muted">{{ entry.user_name || 'تلقائي' }}</span>
                    </div>
                  </td>
                  
                  <!-- Col 3: Reference / PNR -->
                  <td class="px-6 py-5 font-mono text-base text-sky-300 font-black whitespace-nowrap">
                    {{ entry.booking_details?.pnr || entry.reference_id || '—' }}
                  </td>
                  
                  <!-- Col 4: Description & Badges -->
                  <td class="px-6 py-4 min-w-[280px] max-w-xl">
                    <div class="space-y-1">
                      <p class="text-base font-black text-text-main leading-relaxed whitespace-normal break-words">{{ entry.description || entry.notes }}</p>
                      <div class="flex items-center flex-wrap gap-2 mt-1">
                        <span v-if="entry.status" 
                          class="text-sm px-2.5 py-1 rounded-lg font-black uppercase"
                          :class="entry.status === 'completed' ? 'bg-success/10 text-success' : 'bg-gold/10 text-gold'"
                        >
                          {{ entry.status }}
                        </span>
                        <span v-if="entry.process_type" class="text-sm px-2.5 py-1 rounded-lg font-black uppercase bg-white/5 text-text-muted">
                          {{ entry.process_type }}
                        </span>
                        <span v-if="entry.payment_method" class="text-sm px-2.5 py-1 rounded-lg font-black uppercase bg-white/5 text-text-muted">
                          {{ getPaymentMethodLabel(entry.payment_method) }}
                        </span>
                        <span v-if="entry.entity_name" class="text-sm px-2.5 py-1 rounded-lg font-black bg-gold/10 text-gold">
                          {{ entry.entity_name }}
                        </span>
                      </div>
                    </div>
                  </td>
                  
                  <!-- Col 5: Debit (-) -->
                  <td class="px-6 py-4 font-mono text-left whitespace-nowrap">
                    <span v-if="entry.debit > 0" :class="statementTargetType === 'customer' ? 'text-lg font-black text-success' : 'text-lg font-black text-error'">
                      {{ formatCurrency(entry.debit, account?.currency) }}
                    </span>
                    <span v-else class="text-text-muted/20">—</span>
                  </td>
                  
                  <!-- Col 6: Credit (+) -->
                  <td class="px-6 py-4 font-mono text-left whitespace-nowrap">
                    <span v-if="entry.credit > 0" :class="statementTargetType === 'customer' ? 'text-lg font-black text-error' : 'text-lg font-black text-success'">
                      {{ formatCurrency(entry.credit, account?.currency) }}
                    </span>
                    <span v-else class="text-text-muted/20">—</span>
                  </td>
                  
                  <!-- Col 7: Balance -->
                  <td class="px-6 py-5 font-mono text-lg font-black text-left whitespace-nowrap" :class="entry.balance_after > 0 ? 'text-error' : (entry.balance_after < 0 ? 'text-success' : 'text-text-main')">
                    {{ formatCurrency(Math.abs(entry.balance_after || 0), account?.currency) }}
                    <span v-if="statementTargetType === 'customer'" class="text-sm block font-bold">
                      <span v-if="entry.balance_after > 0" class="text-error font-bold">عليه (مدين)</span>
                      <span v-else-if="entry.balance_after < 0" class="text-success font-bold">له (دائن)</span>
                    </span>
                  </td>
                  
                  <!-- Col 8: Actions -->
                  <td class="px-6 py-4 text-center print:hidden whitespace-nowrap" @click.stop>
                    <div class="flex items-center justify-center gap-1.5">
                      <button @click="showEntryDetails(entry)" class="p-1.5 rounded-lg bg-white/5 text-text-muted hover:text-gold transition-colors" title="عرض التفاصيل والطباعة">
                        <Eye class="w-4 h-4" />
                      </button>
                      <button 
                        @click="toggleRow(entry.id || entry.transaction_id)" 
                        class="p-1.5 rounded-lg bg-white/5 text-text-muted hover:text-white transition-colors"
                        title="تفاصيل إضافية"
                      >
                        <ChevronDown v-if="!expandedRows.has(entry.id || entry.transaction_id)" class="w-4 h-4" />
                        <ChevronUp v-else class="w-4 h-4" />
                      </button>
                    </div>
                  </td>
                </tr>

                <!-- Expanded inline details panel -->
                <tr v-if="expandedRows.has(entry.id || entry.transaction_id)" class="bg-white/[0.01]" :key="'expanded_' + (entry.id || entry.transaction_id)">
                  <td colspan="8" class="px-6 py-4 border-b border-white/5">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 text-right leading-relaxed p-5 bg-black/40 rounded-2xl border border-white/5 shadow-inner">
                      <!-- Column 1: Core Transaction Meta -->
                      <div class="space-y-2 text-xs">
                        <p class="text-[10px] font-black text-gold uppercase tracking-wider block mb-1">بيانات المعاملة الأساسية</p>
                        <p><span class="font-black text-text-muted">رقم القيد / المعاملة:</span> <span class="font-mono text-white">#{{ entry.id || entry.transaction_id }}</span></p>
                        <p><span class="font-black text-text-muted">الموظف المسؤول:</span> <span class="text-white">{{ entry.user_name || 'تلقائي' }}</span></p>
                        <p><span class="font-black text-text-muted">القسم / الموديول:</span> <span class="text-gold font-bold">{{ getModuleLabel(entry.module || account?.module || 'general') }}</span></p>
                        <p><span class="font-black text-text-muted">الاسم / العميل / الجهة:</span> <span class="text-white font-bold">{{ entry.entity_name || entry.customer_name || '—' }}</span></p>
                        <p v-if="entry.provider_name"><span class="font-black text-text-muted">المزود:</span> <span class="text-white font-bold">{{ entry.provider_name }}</span></p>
                      </div>

                      <!-- Column 2: Notes / Full Description -->
                      <div class="space-y-2 text-xs">
                        <div class="flex items-center justify-between mb-1">
                          <p class="text-[10px] font-black text-gold uppercase tracking-wider block">البيان والتفاصيل</p>
                          <button @click="showEntryDetails(entry)" class="print:hidden text-[10px] px-2 py-1 rounded bg-white/10 hover:bg-gold hover:text-black transition-colors font-bold flex items-center gap-1">
                            <Printer class="w-3 h-3" />
                            طباعة الإيصال
                          </button>
                        </div>
                        <p class="text-white font-bold bg-white/5 p-3 rounded-xl text-sm border border-white/5 leading-relaxed">{{ entry.description || entry.notes }}</p>
                        <p v-if="entry.notes && entry.notes !== entry.description" class="text-xs font-medium text-text-muted italic">
                          <span class="font-black">ملاحظات إضافية:</span> {{ entry.notes }}
                        </p>
                      </div>

                      <!-- Column 3: Booking/Airline details if any -->
                      <div class="space-y-2 text-xs">
                        <template v-if="(entry.booking_details && Object.keys(entry.booking_details).length) || entry.booking">
                          <p class="text-[10px] font-black text-gold uppercase tracking-wider block mb-1">بيانات وتفاصيل الحجز</p>
                          <div class="space-y-1.5 bg-white/5 p-3.5 rounded-xl border border-white/5">
                            <p v-if="entry.booking_details?.pnr || entry.booking?.pnr"><span class="font-black text-text-muted">PNR:</span> <span class="font-mono text-sky-400 font-bold">{{ entry.booking_details?.pnr || entry.booking?.pnr }}</span></p>
                            <p v-if="entry.booking_details?.ticket_number || entry.booking?.ticket_number"><span class="font-black text-text-muted">رقم التذكرة:</span> <span class="font-mono text-white">{{ entry.booking_details?.ticket_number || entry.booking?.ticket_number }}</span></p>
                            <p v-if="entry.booking_details?.route || entry.booking?.route"><span class="font-black text-text-muted">خط السير:</span> <span class="text-white font-bold">{{ entry.booking_details?.route || entry.booking?.route }}</span></p>
                            <p v-if="entry.booking_details?.travel_date"><span class="font-black text-text-muted">تاريخ الرحلة:</span> <span class="text-white font-bold">{{ entry.booking_details.travel_date }}</span></p>
                            <p v-if="entry.booking_details?.passengers || entry.booking?.passengers"><span class="font-black text-text-muted block mb-0.5">المسافرين:</span> <span class="text-white font-medium block">{{ entry.booking_details?.passengers || entry.booking?.passengers }}</span></p>
                            <p v-if="entry.booking_details?.flight_number || entry.booking?.flight_number"><span class="font-black text-text-muted">رقم الرحلة:</span> <span class="font-mono text-white">{{ entry.booking_details?.flight_number || entry.booking?.flight_number }}</span></p>
                            <p v-if="entry.booking_details?.provider_name || entry.booking_details?.airline"><span class="font-black text-text-muted">المزود / الطيران:</span> <span class="text-gold font-bold">{{ entry.booking_details?.provider_name || entry.booking_details?.airline }}</span></p>
                          </div>
                        </template>

                        <template v-else>
                          <p class="text-[10px] font-black text-gold uppercase tracking-wider block mb-1">بيانات الحساب بعد الحركة</p>
                          <div class="space-y-1.5 bg-white/5 p-3.5 rounded-xl border border-white/5">
                            <p><span class="font-black text-text-muted">الرصيد بعد الحركة مباشرة:</span> <span class="font-mono text-white font-bold">{{ formatCurrency(entry.balance_after || 0, account?.currency) }}</span></p>
                            <p v-if="entry.payment_method"><span class="font-black text-text-muted">طريقة الدفع:</span> <span class="text-white font-bold">{{ getPaymentMethodLabel(entry.payment_method) }}</span></p>
                            <p v-if="entry.process_type"><span class="font-black text-text-muted">نوع القيد:</span> <span class="text-white font-bold">{{ entry.process_type }}</span></p>
                          </div>
                        </template>

                      </div>
                    </div>
                  </td>
                </tr>
              </template>

            </tbody>
            <tfoot class="bg-white/[0.05] border-t-2 border-white/10 font-mono">
              <tr class="text-text-main font-black">
                <td colspan="4" class="px-6 py-5 text-left text-xs uppercase tracking-widest text-text-muted whitespace-nowrap">
                  {{ statementTargetType === 'customer' ? 'إجماليات مبيعات ومسددات العميل' : 'إجمالي العمليات في الفترة' }}
                </td>
                <td class="px-6 py-5 text-left font-black" :class="statementTargetType === 'customer' ? 'text-success' : 'text-error'">
                  {{ statementTargetType === 'customer' ? '+' : '' }}{{ formatCurrency(stats.period_debit, account?.currency) }}
                </td>
                <td class="px-6 py-5 text-left font-black" :class="statementTargetType === 'customer' ? 'text-error' : 'text-success'">
                  {{ statementTargetType === 'customer' ? '' : '+' }}{{ formatCurrency(stats.period_credit, account?.currency) }}
                </td>
                <td class="px-6 py-5 text-left text-xl font-black" :class="stats.closing_balance > 0 ? 'text-error' : (stats.closing_balance < 0 ? 'text-success' : 'text-white')">
                  {{ formatCurrency(Math.abs(stats.closing_balance), account?.currency) }}
                  <span v-if="statementTargetType === 'customer'" class="text-[9px] block">
                    <span v-if="stats.closing_balance > 0" class="text-error font-bold">عليه (مدين)</span>
                    <span v-else-if="stats.closing_balance < 0" class="text-success font-bold">له (دائن)</span>
                  </span>
                </td>
                <td class="print:hidden"></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>

        <!-- Pagination -->
        <div v-if="pagination.last_page > 1" class="px-6 py-6 bg-white/[0.02] border-t border-white/5 flex items-center justify-between print:hidden">
          <p class="text-base font-bold text-text-muted">
            إظهار {{ pagination.from }} - {{ pagination.to }} من إجمالي {{ pagination.total }} حركة
          </p>
          <div class="flex gap-2">
            <button 
              @click="changePage(pagination.current_page - 1)"
              :disabled="pagination.current_page === 1"
              class="p-2.5 rounded-xl border border-white/5 bg-white/5 text-text-muted hover:text-gold disabled:opacity-30 transition-all"
            >
              <ChevronRight class="w-5 h-5" />
            </button>
            <div class="flex items-center gap-1 px-4 text-base font-black">
              <span class="text-gold">{{ pagination.current_page }}</span>
              <span class="text-text-muted">/</span>
              <span>{{ pagination.last_page }}</span>
            </div>
            <button 
              @click="changePage(pagination.current_page + 1)"
              :disabled="pagination.current_page === pagination.last_page"
              class="p-2.5 rounded-xl border border-white/5 bg-white/5 text-text-muted hover:text-gold disabled:opacity-30 transition-all"
            >
              <ChevronLeft class="w-5 h-5" />
            </button>
          </div>
        </div>

        <!-- Print All Option (Only visible if paginated) -->
        <div v-if="pagination.last_page > 1" class="mt-4 text-center print:hidden">
          <button 
            @click="printFullStatement"
            class="text-[10px] font-black text-gold uppercase tracking-[0.2em] hover:underline flex items-center justify-center gap-2 mx-auto"
          >
            <Printer class="w-3 h-3" />
            تحميل وطباعة التقرير الكامل ({{ pagination.total }} حركة)
          </button>
        </div>
      </div>

      <!-- Transfer Modal -->
      <teleport to="body">
        <div 
          v-if="showTransferModal" 
          class="fixed inset-0 z-[300] flex items-center justify-center bg-black/90 p-4 backdrop-blur-xl animate-in fade-in duration-300"
          @click.self="closeTransferModal"
        >
          <div class="flight-panel w-full max-w-xl !p-0 overflow-hidden shadow-2xl border border-white/10 animate-in zoom-in-95 duration-300">
            <div class="px-8 py-6 bg-white/[0.03] border-b border-white/5 flex items-center justify-between">
              <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-2xl bg-gold/10 flex items-center justify-center text-gold border border-gold/20 shadow-2xl shadow-gold/10">
                  <ArrowRightLeft class="w-6 h-6" />
                </div>
                <div>
                  <h3 class="text-2xl font-black text-text-main">تسوية ماليّة</h3>
                  <p class="text-xs text-text-muted font-bold uppercase tracking-widest mt-1">تحويل بين الحسابات</p>
                </div>
              </div>
              <button @click="closeTransferModal" class="p-2 text-text-muted hover:text-white transition-colors">
                <X class="w-6 h-6" />
              </button>
            </div>

            <form @submit.prevent="transferFunds" class="p-8 space-y-6">
              <div class="space-y-2">
                <label for="transferToAccountId" class="stmt-filter-label">الحساب المستهدف</label>
                <div class="relative group">
                  <select id="transferToAccountId" name="transferToAccountId"
                    v-model="transfer.to_account_id" 
                    required
                    class="flight-select w-full !pl-12 font-bold bg-black text-white"
                  >
                    <option value="" disabled>اختر الحساب المحول إليه</option>
                    <option
                      v-for="acc in availableAccounts"
                      :key="acc.id"
                      :value="acc.id"
                      :disabled="Number(acc.id) === Number(route.params.id)"
                    >
                      {{ acc.name }} — {{ acc.currency }} ({{ formatCurrency(acc.balance, acc.currency) }})
                    </option>
                  </select>
                  <div class="absolute left-4 top-1/2 -translate-y-1/2 text-text-muted group-focus-within:text-gold transition-colors">
                    <ArrowRight class="w-5 h-5" />
                  </div>
                </div>
              </div>

              <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="space-y-2">
                  <label for="transferAmount" class="stmt-filter-label">
                    المبلغ ({{ account?.currency || 'EGP' }})
                  </label>
                  <div class="relative group">
                    <input id="transferAmount" name="transferAmount"
                      v-model.number="transfer.amount"
                      type="number"
                      step="0.01"
                      min="0.01"
                      required
                      class="flight-input w-full font-mono text-xl font-black"
                      placeholder="0.00"
                    />
                    <div class="absolute left-4 top-1/2 -translate-y-1/2 text-gold">
                      <Banknote class="w-5 h-5" />
                    </div>
                  </div>
                </div>
                <div class="space-y-2">
                  <label class="stmt-filter-label">عملة الحساب المستلم</label>
                  <div class="flight-input w-full bg-white/5 font-black flex items-center justify-between">
                    <span>{{ transferToAccount?.currency || '—' }}</span>
                    <Globe class="w-4 h-4 text-text-muted" />
                  </div>
                </div>
              </div>

              <div
                v-if="account && transferToAccount && !currenciesMatch(account.currency, transferToAccount.currency)"
                class="space-y-2"
              >
                <label for="transferExchangeRate" class="stmt-filter-label">
                  سعر الصرف
                  <span class="text-rose-400">*</span>
                </label>
                <div class="flex items-center gap-2">
                  <span class="text-sm text-text-muted whitespace-nowrap">1 {{ account.currency }} =</span>
                  <input
                    id="transferExchangeRate"
                    name="transferExchangeRate"
                    v-model.number="transfer.exchange_rate"
                    type="number"
                    step="0.000001"
                    min="0.000001"
                    required
                    class="flight-input flex-1 font-mono font-bold"
                  />
                  <span class="text-sm text-text-muted whitespace-nowrap">{{ transferToAccount.currency }}</span>
                </div>
                <p class="text-sm text-text-muted">
                  المبلغ المضاف للحساب المستلم:
                  <span class="text-gold font-bold">{{ formatCurrency(statementTransferConvertedAmount, transferToAccount.currency) }}</span>
                </p>
              </div>

              <div
                v-if="account && transferToAccount"
                class="rounded-2xl border border-white/10 bg-white/[0.03] p-4 space-y-2 text-sm"
              >
                <div class="flex justify-between gap-4">
                  <span class="text-text-muted">المبلغ المخصوم</span>
                  <span class="font-bold text-gold">{{ formatCurrency(transfer.amount, account.currency) }}</span>
                </div>
                <div
                  v-if="!currenciesMatch(account.currency, transferToAccount.currency)"
                  class="flex justify-between gap-4"
                >
                  <span class="text-text-muted">المبلغ المضاف</span>
                  <span class="font-bold text-emerald-400">{{ formatCurrency(statementTransferConvertedAmount, transferToAccount.currency) }}</span>
                </div>
              </div>

              <p
                v-if="transferError"
                class="text-sm text-rose-400 bg-rose-500/10 border border-rose-500/20 rounded-xl px-4 py-3"
              >
                {{ transferError }}
              </p>

              <div class="space-y-2">
                <label for="transferNotes" class="stmt-filter-label">ملاحظات العملية</label>
                <textarea id="transferNotes" name="transferNotes" 
                  v-model="transfer.notes" 
                  rows="3" 
                  class="flight-input w-full resize-none placeholder:text-text-muted/20"
                  placeholder="اكتب سبب التحويل أو أي تفاصيل إضافية هنا..."
                ></textarea>
              </div>

              <div class="flex gap-4 pt-4">
                <button
                  type="submit"
                  :disabled="submitting || !canExecuteStatementTransfer"
                  class="btn-airline flex-1 py-4 text-base font-black shadow-2xl disabled:opacity-50 flex items-center justify-center gap-3"
                >
                  <span v-if="submitting" class="w-5 h-5 border-2 border-white/30 border-t-white animate-spin rounded-full"></span>
                  {{ submitting ? 'جاري تنفيذ العملية...' : 'اعتماد التحويل المالي' }}
                </button>
                <button
                  type="button"
                  @click="closeTransferModal"
                  class="btn-airline-ghost px-8 py-4 text-base font-bold rounded-2xl"
                >
                  إلغاء
                </button>
              </div>
            </form>
          </div>
        </div>
      </teleport>

      <!-- Quick Transaction Modal -->
      <teleport to="body">
        <div 
          v-if="showQuickTransactionModal" 
          class="fixed inset-0 z-[300] flex items-center justify-center bg-black/90 p-4 backdrop-blur-xl animate-in fade-in duration-300"
          @click.self="showQuickTransactionModal = false"
        >
          <div class="flight-panel w-full max-w-xl !p-0 overflow-hidden shadow-2xl border border-white/10 animate-in zoom-in-95 duration-300">
            <div class="px-8 py-6 bg-white/[0.03] border-b border-white/5 flex items-center justify-between">
              <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-2xl bg-gold/10 flex items-center justify-center text-gold border border-gold/20 shadow-2xl shadow-gold/10">
                  <Banknote class="w-6 h-6" />
                </div>
                <div>
                  <h3 class="text-2xl font-black text-text-main">حركة مالية سريعة</h3>
                  <p class="text-xs text-text-muted font-bold uppercase tracking-widest mt-1">إيداع أو سحب للمحفظة الحالية</p>
                </div>
              </div>
              <button @click="showQuickTransactionModal = false" class="p-2 text-text-muted hover:text-white transition-colors">
                <X class="w-6 h-6" />
              </button>
            </div>

            <form @submit.prevent="submitQuickTransaction" class="p-8 space-y-6">
              <!-- Type Choice (Income / Expense) -->
              <div class="space-y-2 text-right">
                <label class="stmt-filter-label">نوع الحركة</label>
                <div class="grid grid-cols-2 gap-4">
                  <button
                    type="button"
                    @click="quickTransaction.type = 'income'"
                    class="py-3 rounded-xl border-2 transition-all font-bold flex items-center justify-center gap-2"
                    :class="quickTransaction.type === 'income' ? 'border-success bg-success/10 text-success' : 'border-white/10 text-text-muted hover:border-gold/30'"
                  >
                    <TrendingUp class="w-5 h-5" />
                    <span>إيداع / دائن (+)</span>
                  </button>
                  <button
                    type="button"
                    @click="quickTransaction.type = 'expense'"
                    class="py-3 rounded-xl border-2 transition-all font-bold flex items-center justify-center gap-2"
                    :class="quickTransaction.type === 'expense' ? 'border-error bg-error/10 text-error' : 'border-white/10 text-text-muted hover:border-gold/30'"
                  >
                    <TrendingDown class="w-5 h-5" />
                    <span>سحب / مدين (-)</span>
                  </button>
                </div>
              </div>

              <!-- Amount & Date -->
              <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-right">
                <div class="space-y-2">
                  <label for="quickTransactionAmount" class="stmt-filter-label">المبلغ *</label>
                  <div class="relative group">
                    <input id="quickTransactionAmount" name="quickTransactionAmount"
                      v-model.number="quickTransaction.amount" 
                      type="number" 
                      step="0.01" 
                      required 
                      class="flight-input w-full font-mono text-xl font-black"
                      placeholder="0.00"
                    />
                    <div class="absolute left-4 top-1/2 -translate-y-1/2 text-gold">
                      {{ account?.currency || 'EGP' }}
                    </div>
                  </div>
                </div>

                <div class="space-y-2">
                  <label for="quickTransactionDate" class="stmt-filter-label">تاريخ المعاملة</label>
                  <input id="quickTransactionDate" name="quickTransactionDate"
                    v-model="quickTransaction.date" 
                    type="date"
                    required
                    class="flight-input w-full font-bold text-sm bg-black text-white"
                  />
                </div>
              </div>

              <!-- Description & Reference -->
              <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-right">
                <div class="space-y-2">
                  <label for="quickTransactionDescription" class="stmt-filter-label">الوصف والبيان *</label>
                  <input id="quickTransactionDescription" name="quickTransactionDescription"
                    v-model="quickTransaction.description" 
                    type="text"
                    required
                    class="flight-input w-full font-bold text-sm"
                    placeholder="بيان الحركة..."
                  />
                </div>
                <div class="space-y-2">
                  <label for="quickTransactionReference" class="stmt-filter-label">رقم المرجع</label>
                  <input id="quickTransactionReference" name="quickTransactionReference"
                    v-model="quickTransaction.reference" 
                    type="text"
                    class="flight-input w-full font-mono text-sm"
                    placeholder="رقم الإيصال أو الحجز..."
                  />
                </div>
              </div>

              <!-- Notes -->
              <div class="space-y-2 text-right">
                <label for="quickTransactionNotes" class="stmt-filter-label">ملاحظات إضافية</label>
                <textarea id="quickTransactionNotes" name="quickTransactionNotes"
                  v-model="quickTransaction.notes" 
                  rows="2" 
                  class="flight-input w-full resize-none placeholder:text-text-muted/20"
                  placeholder="ملاحظات اختيارية..."
                ></textarea>
              </div>

              <div class="flex gap-4 pt-4">
                <button 
                  type="submit" 
                  :disabled="submitting"
                  class="btn-airline flex-1 py-4 text-base font-black shadow-2xl disabled:opacity-50 flex items-center justify-center gap-3"
                >
                  <span v-if="submitting" class="w-5 h-5 border-2 border-white/30 border-t-white animate-spin rounded-full"></span>
                  {{ submitting ? 'جاري التنفيذ...' : 'تسجيل المعاملة' }}
                </button>
                <button 
                  type="button" 
                  @click="showQuickTransactionModal = false"
                  class="btn-airline-ghost px-8 py-4 text-base font-bold rounded-2xl"
                >
                  إلغاء
                </button>
              </div>
            </form>
          </div>
        </div>
      </teleport>

      <!-- Entry Details / Receipt Modal -->
      <teleport to="body">
        <div 
          v-if="selectedEntryDetails" 
          class="fixed inset-0 z-[500] flex items-center justify-center bg-black/90 p-4 sm:p-6 backdrop-blur-xl animate-in fade-in duration-300 print:hidden"
          @click.self="selectedEntryDetails = null"
        >
          <div class="flight-panel w-full max-w-3xl !p-0 overflow-hidden shadow-2xl border border-white/10 animate-in zoom-in-95 duration-300 print:border-none print:shadow-none print:max-w-full print:mx-auto print:w-full print:block print:rounded-none flex flex-col max-h-[90vh]">
            <!-- Modal Header -->
            <div class="px-8 py-6 bg-white/[0.03] border-b border-white/5 flex items-center justify-between print:hidden">
              <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-gold/10 flex items-center justify-center text-gold">
                  <FileText class="w-5 h-5" />
                </div>
                <div>
                  <h3 class="text-xl font-black text-text-main">تفاصيل السند / المعاملة</h3>
                  <p class="text-xs text-text-muted font-mono mt-0.5">#{{ selectedEntryDetails.id || selectedEntryDetails.transaction_id }}</p>
                </div>
              </div>
              <div class="flex items-center gap-2">
                <button @click="printSingleEntry" class="p-2 bg-white/5 rounded-lg text-text-muted hover:text-sky-400 transition-colors" title="طباعة السند">
                  <Printer class="w-5 h-5" />
                </button>
                <button @click="selectedEntryDetails = null" class="p-2 text-text-muted hover:text-white transition-colors">
                  <X class="w-5 h-5" />
                </button>
              </div>
            </div>

            <!-- Printable Receipt Content -->
            <!-- Printable Receipt Content -->
            <div id="printable-receipt" class="p-6 sm:p-8 space-y-5 text-text-main text-right print:w-full overflow-y-auto print:overflow-visible print:p-2 print:space-y-3 print:text-black">
              <!-- Official Document Header -->
              <div class="border-b border-white/10 pb-4 print:pb-2 text-center">
                <h2 class="text-2xl print:text-xl font-black text-gold print:text-black">{{ printSettingsStore.settings.company_name_ar || 'سفري علينا للسياحة' }}</h2>
                <p class="text-sm print:text-xs font-bold text-text-muted mt-1 print:text-gray-600">سند معاملة مالية / كشف تفاصيل قيد شامل</p>
              </div>

              <!-- General Meta Layer -->
              <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6 text-sm print:text-xs bg-white/[0.02] print:bg-transparent p-4 sm:p-6 print:p-0 rounded-xl border border-white/5 print:border-none text-right leading-relaxed print:gap-2">
                <div class="space-y-2 print:space-y-1">
                  <p><span class="font-black text-text-muted print:text-gray-700">رقم المرجع/القيد:</span> <span class="font-mono text-sky-400 print:text-black font-bold">{{ selectedEntryDetails.reference_id || selectedEntryDetails.booking_details?.pnr || selectedEntryDetails.id || selectedEntryDetails.transaction_id }}</span></p>
                  <p><span class="font-black text-text-muted print:text-gray-700">التاريخ والوقت:</span> <span>{{ selectedEntryDetails.date_human }}</span></p>
                  <p><span class="font-black text-text-muted print:text-gray-700">نوع النظام / القسم:</span> <span class="text-gold print:text-black font-bold">{{ getModuleLabel(selectedEntryDetails.module || account?.module || 'general') }} <span class="text-[10px] text-text-muted/60 print:text-gray-500 font-mono">({{ selectedEntryDetails.module || account?.module || 'عام' }})</span></span></p>
                  <p><span class="font-black text-text-muted print:text-gray-700">نوع القيد / الإجراء:</span> <span class="font-bold">{{ selectedEntryDetails.process_type || selectedEntryDetails.payment_method || 'حركة مالية وتسوية' }}</span></p>
                </div>
                <div class="space-y-2 print:space-y-1">
                  <p><span class="font-black text-text-muted print:text-gray-700">الحساب المالي المستهدف:</span> <span class="text-sky-400 print:text-black font-bold">{{ account?.name || selectedEntryDetails.account_name || selectedEntryDetails.treasury_name || 'الحساب الحالي المفتوح' }}</span></p>
                  <p><span class="font-black text-text-muted print:text-gray-700">الجهة / العميل المستفيد:</span> <span class="text-gold print:text-black font-bold">{{ statementTargetType === 'customer' ? (selectedCustomer?.name || selectedEntryDetails.entity_name) : (selectedEntryDetails.entity_name || selectedEntryDetails.customer_name || selectedEntryDetails.user_name || '—') }}</span></p>
                  <p><span class="font-black text-text-muted print:text-gray-700">الموظف المسؤول:</span> <span>{{ selectedEntryDetails.user_name || 'النظام (تلقائي)' }}</span></p>
                </div>
              </div>

              <!-- Main Amount Box -->
              <div class="p-4 print:p-2 rounded-2xl bg-white/5 print:bg-transparent border border-white/10 print:border-black/20 text-center">
                <span class="text-xs print:text-[10px] font-black text-text-muted print:text-gray-700 block mb-2 print:mb-1 uppercase tracking-widest">قيمة الحركة المالية المسجلة</span>
                <p class="text-3xl print:text-xl font-black font-mono" :class="selectedEntryDetails.credit > 0 ? 'text-success print:text-black' : 'text-error print:text-black'">
                  {{ formatCurrency(selectedEntryDetails.credit > 0 ? selectedEntryDetails.credit : selectedEntryDetails.debit, account?.currency) }}
                </p>
                <span class="text-sm print:text-xs font-bold block mt-2 print:mt-1" :class="selectedEntryDetails.credit > 0 ? 'text-success print:text-gray-700' : 'text-error print:text-gray-700'">
                  ({{ selectedEntryDetails.credit > 0 ? 'إيداع / دائن (إضافة للرصيد)' : 'سحب / مدين (خصم من الرصيد)' }})
                </span>
              </div>

              <!-- Statement Settlement Status Box (عليه فلوس لا حسابه خالص كل حاجة) -->
              <div class="p-4 print:p-2 rounded-xl bg-white/[0.02] print:bg-transparent border border-white/5 print:border-black/20 text-right space-y-2 print:space-y-1">
                <span class="text-xs print:text-[10px] font-black text-gold print:text-black block uppercase tracking-widest mb-2 print:mb-1">حالة الحساب والرصيد التراكمي التفصيلي</span>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 print:gap-2 text-sm print:text-xs">
                  <div>
                    <span class="text-text-muted print:text-gray-700 block mb-0.5">الرصيد التراكمي بعد هذه الحركة:</span>
                    <span class="font-mono font-bold block text-sm" :class="selectedEntryDetails.balance_after > 0 ? 'text-error' : (selectedEntryDetails.balance_after < 0 ? 'text-success' : 'text-text-main')">
                      {{ formatCurrency(Math.abs(selectedEntryDetails.balance_after || 0), account?.currency) }}
                      <span class="text-xs font-sans">({{ selectedEntryDetails.balance_after > 0 ? 'عليه / مدين' : (selectedEntryDetails.balance_after < 0 ? 'له / دائن' : 'رصيد مصفر') }})</span>
                    </span>
                  </div>
                  <div>
                    <span class="text-text-muted block mb-0.5">الموقف المالي الختامي للحساب/العميل:</span>
                    <span class="font-bold block text-sm mt-1" :class="stats.closing_balance > 0 ? 'text-error' : (stats.closing_balance < 0 ? 'text-success' : 'text-success')">
                      <template v-if="stats.closing_balance > 0">
                        ⚠️ عليه مستحقات (مدين) بقيمة {{ formatCurrency(Math.abs(stats.closing_balance), account?.currency) }}
                      </template>
                      <template v-else-if="stats.closing_balance < 0">
                        ✅ له مستحقات (دائن) بقيمة {{ formatCurrency(Math.abs(stats.closing_balance), account?.currency) }}
                      </template>
                      <template v-else>
                        ✨ حسابه خالص تماماً (الرصيد الختامي صفر)
                      </template>
                    </span>
                  </div>
                </div>
              </div>

              <!-- Description & Details -->
              <div class="space-y-2 border-t border-white/10 print:border-black/10 pt-4 print:pt-2 text-right">
                <label class="text-xs print:text-[10px] font-black text-gold print:text-black block uppercase tracking-widest mb-1">البيان والتفاصيل</label>
                <p class="text-base print:text-sm font-bold text-text-main print:text-black bg-white/5 print:bg-transparent print:border print:border-black/10 p-4 print:p-2 rounded-xl leading-relaxed">
                  {{ selectedEntryDetails.description }}
                </p>
              </div>

              <!-- Extra Notes if any -->
              <div v-if="selectedEntryDetails.notes" class="space-y-2 border-t border-white/10 pt-4 text-right">
                <label class="text-xs font-black text-gold block uppercase tracking-widest mb-1">ملاحظات إضافية</label>
                <p class="text-sm font-medium text-text-muted italic">
                  {{ selectedEntryDetails.notes }}
                </p>
              </div>

              <!-- Rich Booking Details Layer (تفاصيل الرحلة كله بطريقة منظمة) -->
              <div v-if="(selectedEntryDetails.booking_details && Object.keys(selectedEntryDetails.booking_details).length) || selectedEntryDetails.booking" class="space-y-3 print:space-y-1 border-t border-white/10 print:border-black/10 pt-4 print:pt-2 text-right">
                <label class="text-xs print:text-[10px] font-black text-gold print:text-black block uppercase tracking-widest mb-1">تفاصيل الرحلة / الحجز المنظمة بالكامل</label>
                
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4 print:gap-2 text-sm print:text-xs bg-white/[0.02] print:bg-transparent p-5 print:p-2 rounded-xl border border-white/5 print:border-black/10">
                  
                  <!-- Dedicated layout for specific fields -->
                  <div v-if="selectedEntryDetails.booking_details?.pnr || selectedEntryDetails.booking?.pnr" class="flex flex-col gap-0.5">
                    <span class="text-[10px] text-text-muted print:text-gray-700">رقم الحجز / PNR</span>
                    <span class="font-mono font-black text-sky-400 print:text-black">{{ selectedEntryDetails.booking_details?.pnr || selectedEntryDetails.booking?.pnr }}</span>
                  </div>
                  <div v-if="selectedEntryDetails.booking_details?.ticket_number || selectedEntryDetails.booking?.ticket_number" class="flex flex-col gap-0.5">
                    <span class="text-[10px] text-text-muted print:text-gray-700">رقم التذكرة</span>
                    <span class="font-mono font-bold text-text-main print:text-black">{{ selectedEntryDetails.booking_details?.ticket_number || selectedEntryDetails.booking?.ticket_number }}</span>
                  </div>
                  <div v-if="selectedEntryDetails.booking_details?.route || selectedEntryDetails.booking?.route" class="flex flex-col gap-0.5 sm:col-span-2">
                    <span class="text-[10px] text-text-muted print:text-gray-700">خط السير / المطارات</span>
                    <span class="font-bold text-text-main print:text-black">{{ selectedEntryDetails.booking_details?.route || selectedEntryDetails.booking?.route }}</span>
                  </div>
                  <div v-if="selectedEntryDetails.booking_details?.passengers || selectedEntryDetails.booking?.passengers" class="flex flex-col gap-0.5 sm:col-span-2 md:col-span-3">
                    <span class="text-[10px] text-text-muted print:text-gray-700">أسماء المسافرين</span>
                    <span class="font-bold text-text-main print:text-black">{{ selectedEntryDetails.booking_details?.passengers || selectedEntryDetails.booking?.passengers }}</span>
                  </div>
                  <div v-if="selectedEntryDetails.booking_details?.flight_number || selectedEntryDetails.booking?.flight_number" class="flex flex-col gap-0.5">
                    <span class="text-[10px] text-text-muted print:text-gray-700">رقم الرحلة</span>
                    <span class="font-mono text-text-main print:text-black">{{ selectedEntryDetails.booking_details?.flight_number || selectedEntryDetails.booking?.flight_number }}</span>
                  </div>
                  <div v-if="selectedEntryDetails.booking_details?.provider_name || selectedEntryDetails.booking?.provider_name || selectedEntryDetails.booking_details?.airline" class="flex flex-col gap-0.5">
                    <span class="text-[10px] text-text-muted print:text-gray-700">المزود / شركة الطيران</span>
                    <span class="font-bold text-gold print:text-black">{{ selectedEntryDetails.booking_details?.provider_name || selectedEntryDetails.booking?.provider_name || selectedEntryDetails.booking_details?.airline }}</span>
                  </div>
                  <div v-if="selectedEntryDetails.booking_details?.status || selectedEntryDetails.booking?.status" class="flex flex-col gap-0.5">
                    <span class="text-[10px] text-text-muted print:text-gray-700">حالة التذكرة / الحجز</span>
                    <span class="font-bold text-text-main print:text-black uppercase">{{ selectedEntryDetails.booking_details?.status || selectedEntryDetails.booking?.status }}</span>
                  </div>
                  
                  <!-- Fallback mapping loop for ANY extra unlisted key inside booking_details -->
                  <template v-if="selectedEntryDetails.booking_details && typeof selectedEntryDetails.booking_details === 'object'">
                    <div 
                      v-for="(val, key) in selectedEntryDetails.booking_details" 
                      :key="key"
                      v-show="!['pnr', 'ticket_number', 'route', 'passengers', 'flight_number', 'provider_name', 'airline', 'status'].includes(key)"
                      class="flex flex-col gap-0.5"
                    >
                      <span class="text-[10px] text-text-muted print:text-gray-700 capitalize">{{ String(key).replace(/_/g, ' ') }}</span>
                      <span class="font-bold text-text-main print:text-black truncate" :title="String(val)">{{ val || '—' }}</span>
                    </div>
                  </template>

                  <!-- Fallback mapping loop for ANY extra unlisted key inside booking -->
                  <template v-if="selectedEntryDetails.booking && typeof selectedEntryDetails.booking === 'object'">
                    <div 
                      v-for="(val, key) in selectedEntryDetails.booking" 
                      :key="'b_'+key"
                      v-show="!['pnr', 'ticket_number', 'route', 'passengers', 'flight_number', 'provider_name', 'airline', 'status'].includes(key)"
                      class="flex flex-col gap-0.5"
                    >
                      <span class="text-[10px] text-text-muted capitalize">{{ String(key).replace(/_/g, ' ') }}</span>
                      <span class="font-bold text-text-main truncate" :title="String(val)">{{ typeof val === 'object' ? JSON.stringify(val) : val }}</span>
                    </div>
                  </template>

                </div>
              </div>

              <!-- Footer Authorization -->
              <div class="grid grid-cols-2 gap-4 pt-8 border-t border-white/10 text-center text-xs text-text-muted font-bold">
                <div>توقيع الموظف المسؤول</div>
                <div>ختم الشركة / الاعتماد</div>
              </div>
            </div>

            <!-- Modal Action Footer -->
            <div class="px-8 py-4 bg-white/[0.02] border-t border-white/5 text-center print:hidden">
              <button 
                @click="printSingleEntry"
                class="btn-airline w-full py-3.5 text-sm font-black flex items-center justify-center gap-2"
              >
                <Printer class="w-4 h-4" />
                طباعة هذا السند المنفصل
              </button>
            </div>
          </div>
        </div>
      </teleport>
    </div>

      <!-- Dedicated Print Layout -->
      <div v-if="selectedEntryDetails" class="hidden print:block print:w-full print:bg-white print:text-black print:font-sans" dir="rtl">
        <div class="max-w-4xl mx-auto py-8 px-6">
          <!-- Logo & Header -->
          <div class="text-center mb-10 border-b-2 border-gray-800 pb-6">
            <h1 class="text-3xl font-black text-black mb-3">{{ printSettingsStore.settings.company_name_ar || 'سفري علينا للسياحة' }}</h1>
            <h2 class="text-xl text-gray-800 font-bold">سند معاملة مالية / إيصال استلام</h2>
            <div class="mt-6 flex justify-between text-sm text-gray-700 font-bold px-4">
              <span>التاريخ: {{ selectedEntryDetails.date_human }}</span>
              <span>رقم السند: {{ selectedEntryDetails.reference_id || selectedEntryDetails.booking_details?.pnr || selectedEntryDetails.id || selectedEntryDetails.transaction_id }}</span>
            </div>
          </div>

          <!-- Transaction Basic Info Table -->
          <div class="mb-10">
            <table class="w-full text-right border-collapse border-2 border-gray-800">
              <tbody>
                <tr>
                  <th class="border-2 border-gray-800 bg-gray-100 px-4 py-3 text-sm text-gray-900 w-1/4 font-black">القسم / النظام</th>
                  <td class="border-2 border-gray-800 px-4 py-3 text-sm text-black font-bold w-1/4">{{ getModuleLabel(selectedEntryDetails.module || account?.module || 'general') }}</td>
                  <th class="border-2 border-gray-800 bg-gray-100 px-4 py-3 text-sm text-gray-900 w-1/4 font-black">نوع الإجراء</th>
                  <td class="border-2 border-gray-800 px-4 py-3 text-sm text-black font-bold w-1/4">{{ selectedEntryDetails.process_type || selectedEntryDetails.payment_method || 'حركة مالية وتسوية' }}</td>
                </tr>
                <tr>
                  <th class="border-2 border-gray-800 bg-gray-100 px-4 py-3 text-sm text-gray-900 font-black">الحساب المالي</th>
                  <td class="border-2 border-gray-800 px-4 py-3 text-sm text-black font-bold">{{ account?.name || selectedEntryDetails.account_name || selectedEntryDetails.treasury_name || 'الحساب الحالي المفتوح' }}</td>
                  <th class="border-2 border-gray-800 bg-gray-100 px-4 py-3 text-sm text-gray-900 font-black">العميل / المستفيد</th>
                  <td class="border-2 border-gray-800 px-4 py-3 text-sm text-black font-bold">{{ statementTargetType === 'customer' ? (selectedCustomer?.name || selectedEntryDetails.entity_name) : (selectedEntryDetails.entity_name || selectedEntryDetails.customer_name || selectedEntryDetails.user_name || '—') }}</td>
                </tr>
                <tr>
                  <th class="border-2 border-gray-800 bg-gray-100 px-4 py-3 text-sm text-gray-900 font-black">الموظف المسؤول</th>
                  <td colspan="3" class="border-2 border-gray-800 px-4 py-3 text-sm text-black font-bold">{{ selectedEntryDetails.user_name || 'النظام (تلقائي)' }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Value Box -->
          <div class="mb-10 border-4 border-gray-800 rounded-2xl p-8 text-center bg-gray-50 flex flex-col items-center justify-center">
            <h3 class="text-xl text-gray-900 font-black mb-4 underline underline-offset-8">قيمة المعاملة</h3>
            <div class="text-5xl font-black font-mono tracking-wider text-black">
              {{ formatCurrency(selectedEntryDetails.credit > 0 ? selectedEntryDetails.credit : selectedEntryDetails.debit, account?.currency) }}
            </div>
            <div class="mt-4 text-lg font-bold text-gray-800">
              ({{ selectedEntryDetails.credit > 0 ? 'إيداع / دائن' : 'سحب / مدين' }})
            </div>
          </div>
          
          <div class="mb-10 p-4 border-2 border-gray-800 bg-gray-50 flex items-center justify-between text-md font-bold text-black">
             <span>الرصيد التراكمي بعد هذه الحركة: {{ formatCurrency(Math.abs(selectedEntryDetails.balance_after || 0), account?.currency) }} ({{ selectedEntryDetails.balance_after > 0 ? 'عليه / مدين' : (selectedEntryDetails.balance_after < 0 ? 'له / دائن' : 'رصيد مصفر') }})</span>
             <span v-if="stats.closing_balance > 0">الموقف الختامي: عليه مستحقات {{ formatCurrency(Math.abs(stats.closing_balance), account?.currency) }}</span>
             <span v-else-if="stats.closing_balance < 0">الموقف الختامي: له مستحقات {{ formatCurrency(Math.abs(stats.closing_balance), account?.currency) }}</span>
             <span v-else>الموقف الختامي: رصيد خالص</span>
          </div>

          <!-- Details & Description -->
          <div class="mb-10">
            <h3 class="text-lg font-black text-gray-900 border-b-2 border-gray-800 pb-2 mb-4">البيان والتفاصيل</h3>
            <p class="text-black text-lg font-bold leading-relaxed">{{ selectedEntryDetails.description }}</p>
            <p v-if="selectedEntryDetails.notes" class="text-gray-700 font-semibold mt-3 italic text-md">{{ selectedEntryDetails.notes }}</p>
          </div>

          <!-- Booking Details Box (If exists) -->
          <div v-if="(selectedEntryDetails.booking_details && Object.keys(selectedEntryDetails.booking_details).length) || selectedEntryDetails.booking" class="mb-10">
             <h3 class="text-lg font-black text-gray-900 border-b-2 border-gray-800 pb-2 mb-4">بيانات الرحلة / الحجز الإضافية</h3>
             <div class="grid grid-cols-2 gap-y-4 gap-x-8 text-md font-bold text-black">
                <div v-if="selectedEntryDetails.booking_details?.pnr || selectedEntryDetails.booking?.pnr">
                  <span class="text-gray-700">PNR:</span> {{ selectedEntryDetails.booking_details?.pnr || selectedEntryDetails.booking?.pnr }}
                </div>
                <div v-if="selectedEntryDetails.booking_details?.ticket_number || selectedEntryDetails.booking?.ticket_number">
                  <span class="text-gray-700">التذكرة:</span> {{ selectedEntryDetails.booking_details?.ticket_number || selectedEntryDetails.booking?.ticket_number }}
                </div>
                <div v-if="selectedEntryDetails.booking_details?.route || selectedEntryDetails.booking?.route" class="col-span-2">
                  <span class="text-gray-700">خط السير:</span> {{ selectedEntryDetails.booking_details?.route || selectedEntryDetails.booking?.route }}
                </div>
                <div v-if="selectedEntryDetails.booking_details?.passengers || selectedEntryDetails.booking?.passengers" class="col-span-2">
                  <span class="text-gray-700">المسافرين:</span> {{ selectedEntryDetails.booking_details?.passengers || selectedEntryDetails.booking?.passengers }}
                </div>
                <div v-if="selectedEntryDetails.booking_details?.airline || selectedEntryDetails.booking?.provider_name">
                  <span class="text-gray-700">الطيران/المزود:</span> {{ selectedEntryDetails.booking_details?.airline || selectedEntryDetails.booking?.provider_name }}
                </div>
             </div>
          </div>

          <!-- Footer/Signatures -->
          <div class="mt-20 flex justify-between text-center pt-8">
            <div class="w-1/3 px-4">
              <div class="border-t-2 border-gray-800 pt-3 font-black text-lg text-black">توقيع المستلم / العميل</div>
            </div>
            <div class="w-1/3 px-4">
              <div class="border-t-2 border-gray-800 pt-3 font-black text-lg text-black">توقيع الموظف المسؤول</div>
            </div>
            <div class="w-1/3 px-4">
              <div class="border-t-2 border-gray-800 pt-3 font-black text-lg text-black">ختم الشركة المعتمد</div>
            </div>
          </div>
        </div>
      </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, nextTick, watch } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { storeToRefs } from 'pinia';
import { useAccountStore } from '@/stores/accountStore';
import { useFinanceStore } from '@/stores/financeStore';
import { usePrintSettingsStore } from '@/stores/printSettingsStore';

const printSettingsStore = usePrintSettingsStore();
import axios from 'axios';
import {
  buildTransferApiPayload,
  canExecuteCrossCurrencyTransfer,
  computeConvertedAmount,
  currenciesMatch,
  findTreasuryAccount,
} from '@/composables/useCrossCurrencyTransfer';
import {
  ArrowLeft, 
  Printer, 
  ArrowRightLeft, 
  CreditCard, 
  TrendingUp, 
  TrendingDown, 
  Wallet, 
  Search, 
  RotateCcw, 
  History, 
  User, 
  ChevronRight, 
  ChevronLeft,
  X,
  ArrowRight,
  Banknote,
  Globe,
  LayoutGrid,
  Plane,
  Compass,
  FileText,
  Bus,
  Eye,
  ChevronDown,
  ChevronUp,
  Download
} from 'lucide-vue-next';

const router = useRouter();
const route = useRoute();
const accountStore = useAccountStore();
const financeStore = useFinanceStore();

// Entry Details Modal State
const selectedEntryDetails = ref(null);

// Expanded Row State for Inline Details Panel
const expandedRows = ref(new Set());

function toggleRow(id) {
  if (expandedRows.value.has(id)) {
    expandedRows.value.delete(id);
  } else {
    expandedRows.value.add(id);
  }
}

function showEntryDetails(entry) {
  selectedEntryDetails.value = entry;
}

function getPaymentMethodLabel(method) {
  if (!method) return '';
  const labels = {
    'http_api': 'بوابة النظام (تلقائي)',
    'cash': 'نقدي',
    'bank_transfer': 'تحويل بنكي',
    'cash_wallet': 'محفظة نقدية',
    'office_safe': 'خزينة المكتب',
    'office_drawer': 'درج المكتب',
    'postal_transfer': 'حوالة بريدية',
    'vodafone_cash': 'فودافون كاش',
    'instapay': 'انستاباي',
    'credit_card': 'بطاقة ائتمان',
    'check': 'شيك بنكي',
    'mixed': 'دفع مختلط',
    'other': 'أخرى'
  };
  const key = method.toLowerCase().trim();
  return labels[key] || method;
}

function printSingleEntry() {
  setTimeout(() => {
    window.print();
  }, 100);
}

// Quick Transaction Modal State
const showQuickTransactionModal = ref(false);
const quickTransaction = ref({
  type: 'income',
  amount: null,
  date: new Date().toISOString().split('T')[0],
  module: 'general',
  description: '',
  account_id: '',
  reference: '',
  notes: '',
});

function openQuickTransactionModal() {
  quickTransaction.value = {
    type: 'income',
    amount: null,
    date: new Date().toISOString().split('T')[0],
    module: account.value?.module || 'general',
    description: '',
    account_id: route.params.id || '',
    reference: '',
    notes: '',
  };
  showQuickTransactionModal.value = true;
}

// Target Switcher Refs
const statementTargetType = ref('account'); // 'account' or 'customer'

// Account state
const account = ref(null);
const selectedAccountId = ref(route.params.id || '');

// Customer state
const selectedCustomerType = ref(''); // '' or 'regular' or 'counter'
const targetCustomerId = ref('');
const selectedCustomer = ref(null);
const allLoadedCustomers = ref([]);

// Statement data
const statement = ref([]);
const stats = ref({ 
  period_credit: 0, 
  period_debit: 0,
  opening_balance: 0,
  closing_balance: 0,
  account_balance: 0
});
const loading = ref(true);
const submitting = ref(false);
const showTransferModal = ref(false);
const transferError = ref('');
const accountModuleTypeFilter = ref('');
const accountModuleFilter = ref('');

function onModuleTypeFilterChange() {
  accountModuleFilter.value = '';
  selectedAccountId.value = '';
}

function onModuleFilterChange() {
  selectedAccountId.value = '';
}

const filters = ref({
  search: '',
  from_date: '',
  to_date: '',
  type: '',
  module: '',
  page: 1,
  per_page: 20,
});

const transfer = ref({
  to_account_id: '',
  amount: null,
  exchange_rate: 1,
  notes: '',
});

const { pagination, accounts } = storeToRefs(accountStore);

// Customer switching and loaders
function setTargetType(type) {
  statementTargetType.value = type;
  statement.value = [];
  stats.value = { period_credit: 0, period_debit: 0, opening_balance: 0, closing_balance: 0 };
  if (type === 'customer' && !allLoadedCustomers.value.length) {
    loadCustomers();
  }
}

async function loadCustomers() {
  try {
    const res = await axios.get('/api/v1/customers', { params: { per_page: 500 } });
    const items = res.data?.data?.items || res.data?.items || (Array.isArray(res.data?.data) ? res.data?.data : []);
    allLoadedCustomers.value = items;
  } catch (err) {
    console.error('Failed to load customers:', err);
  }
}

const availableCustomers = computed(() => {
  if (!selectedCustomerType.value) return allLoadedCustomers.value;
  return allLoadedCustomers.value.filter(c => c.type === selectedCustomerType.value);
});

async function handleCustomerSelectChange() {
  if (!targetCustomerId.value) return;
  loading.value = true;
  filters.value.page = 1;
  try {
    const res = await axios.get(`/api/v1/customers/${targetCustomerId.value}/statement`, { params: filters.value });
    const data = res.data?.data || {};
    selectedCustomer.value = data.customer || allLoadedCustomers.value.find(c => Number(c.id) === Number(targetCustomerId.value));
    statement.value = data.items || [];
    if (data.stats) stats.value = data.stats;
    if (data.pagination) pagination.value = data.pagination;
  } catch (err) {
    console.error('Failed to fetch customer statement:', err);
    statement.value = [];
  } finally {
    loading.value = false;
  }
}

function clearCustomerSelection() {
  selectedCustomer.value = null;
  targetCustomerId.value = '';
  statement.value = [];
  stats.value = { period_credit: 0, period_debit: 0, opening_balance: 0, closing_balance: 0 };
}

function handleAccountChange() {
  if (selectedAccountId.value) {
    router.push({ 
      name: 'finance.accounts.statement.detail', 
      params: { id: selectedAccountId.value } 
    });
  }
}

const layoutMode = computed(() => {
  if (!account.value) return 'commercial';
  
  const type = account.value.type;
  const module = account.value.module;

  if (['treasury', 'bank', 'post', 'cashbox', 'wallet'].includes(type)) {
    return 'treasury';
  }
  
  if (module === 'online') {
    return 'online';
  }
  
  return 'commercial';
});

const availableAccountModules = computed(() => {
  const modules = financeStore.meta.transactionModules || [];
  if (!accountModuleTypeFilter.value) return modules;

  const tourismModules = ['flight', 'hajj_umra', 'visa'];
  const officeModules = ['bus', 'wallet', 'online', 'fawry', 'general', 'service'];

  if (accountModuleTypeFilter.value === 'tourism') {
    return modules.filter((m) => tourismModules.includes(m.value));
  }
  if (accountModuleTypeFilter.value === 'office') {
    return modules.filter((m) => officeModules.includes(m.value));
  }
  return modules;
});

const availableAccounts = computed(() => {
  if (!Array.isArray(accounts.value)) return [];
  
  const TOURISM_MODULES = ['tourism', 'flights', 'hajj_umra', 'visas', 'flight', 'visa', 'hajj'];
  const OFFICE_MODULES = ['office', 'bus', 'fawry', 'online', 'wallet_transfer', 'general', 'wallet', 'service'];

  return accounts.value.filter(acc => {
    const matchActive = acc.is_active;
    
    let matchModuleType = true;
    if (accountModuleTypeFilter.value === 'tourism') {
      matchModuleType = TOURISM_MODULES.includes(acc.module_type) || TOURISM_MODULES.includes(acc.module);
    } else if (accountModuleTypeFilter.value === 'office') {
      matchModuleType = OFFICE_MODULES.includes(acc.module_type) || OFFICE_MODULES.includes(acc.module);
    }
    
    let matchModule = true;
    if (accountModuleFilter.value) {
      if (accountModuleFilter.value === 'general') {
        matchModule = !acc.module || acc.module === 'general' || acc.module_type === 'general' || acc.module_type === 'office';
      } else {
        const val = accountModuleFilter.value;
        const normalizedVal = val.endsWith('s') ? val.slice(0, -1) : val; // normalize singular
        const accModule = acc.module || '';
        const accModuleType = acc.module_type || '';
        const normAccModule = accModule.endsWith('s') ? accModule.slice(0, -1) : accModule;
        const normAccModuleType = accModuleType.endsWith('s') ? accModuleType.slice(0, -1) : accModuleType;
        
        matchModule = normAccModule === normalizedVal || normAccModuleType === normalizedVal;
        
        // Specially handle wallet modules
        if (normalizedVal === 'wallet') {
          matchModule = matchModule || 
                       accModule.includes('wallet') || 
                       accModuleType.includes('wallet');
        }
      }
    }
    
    return matchActive && matchModuleType && matchModule;
  });
});

const transferToAccount = computed(() =>
  findTreasuryAccount(availableAccounts.value, transfer.value.to_account_id)
);

const statementTransferConvertedAmount = computed(() => {
  if (!account.value || !transferToAccount.value) return 0;
  return computeConvertedAmount(
    transfer.value.amount,
    transfer.value.exchange_rate,
    account.value.currency,
    transferToAccount.value.currency
  );
});

const canExecuteStatementTransfer = computed(() =>
  canExecuteCrossCurrencyTransfer({
    fromAccountId: route.params.id,
    toAccountId: transfer.value.to_account_id,
    fromAccount: account.value,
    toAccount: transferToAccount.value,
    amount: transfer.value.amount,
    exchangeRate: transfer.value.exchange_rate,
  })
);

function closeTransferModal() {
  showTransferModal.value = false;
  transferError.value = '';
  transfer.value = {
    to_account_id: '',
    amount: null,
    exchange_rate: 1,
    notes: '',
  };
}

async function fetchAccountData() {
  try {
    const res = await axios.get(`/api/v1/finance/accounts/${route.params.id}`);
    account.value = res.data?.data || null;
  } catch (err) {
    console.error('Failed to fetch account:', err);
  }
}

async function fetchStatement() {
  if (statementTargetType.value === 'customer') {
    if (!selectedCustomer.value?.id) return;
    loading.value = true;
    try {
      const res = await axios.get(`/api/v1/customers/${selectedCustomer.value.id}/statement`, { params: filters.value });
      const data = res.data?.data || {};
      statement.value = data.items || [];
      if (data.stats) stats.value = data.stats;
      if (data.pagination) pagination.value = data.pagination;
    } catch (err) {
      console.error('Failed to fetch customer statement:', err);
      statement.value = [];
    } finally {
      loading.value = false;
    }
    return;
  }

  if (!route.params.id) return;
  loading.value = true;
  try {
    const res = await axios.get(`/api/v1/finance/accounts/${route.params.id}/statement`, { 
      params: filters.value 
    });
    const data = res.data?.data || {};
    statement.value = data.items || [];
    
    if (data.pagination) {
      pagination.value = data.pagination;
    }

    if (data.stats) {
      stats.value = data.stats;
    }
  } catch (err) {
    console.error('Failed to fetch statement:', err);
    statement.value = [];
  } finally {
    loading.value = false;
  }
}

async function printFullStatement() {
  if (loading.value) return;
  const originalStatement = [...statement.value];
  loading.value = true;
  try {
    const url = statementTargetType.value === 'customer' 
      ? `/api/v1/customers/${selectedCustomer.value.id}/statement`
      : `/api/v1/finance/accounts/${route.params.id}/statement`;
      
    const res = await axios.get(url, { 
      params: { ...filters.value, per_page: 5000, page: 1 } 
    });
    const data = res.data?.data || {};
    statement.value = data.items || [];
    
    await nextTick();
    window.print();
    
    statement.value = originalStatement;
  } catch (err) {
    console.error('Failed to fetch full statement for printing:', err);
    if (window.addToast) window.addToast('فشل تحميل التقرير الكامل للطباعة', 'error');
  } finally {
    loading.value = false;
  }
}

let debounceTimer;
function debounceFetch() {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(() => {
    filters.value.page = 1;
    fetchStatement();
  }, 500);
}

function resetFilters() {
  filters.value = {
    search: '',
    from_date: '',
    to_date: '',
    type: '',
    module: '',
    page: 1,
    per_page: 20,
  };
  fetchStatement();
}

function changePage(page) {
  if (page < 1 || page > pagination.value.last_page) return;
  filters.value.page = page;
  fetchStatement();
}

async function transferFunds() {
  if (submitting.value) return;
  transferError.value = '';

  if (!canExecuteStatementTransfer.value) {
    transferError.value = 'تحقق من الحساب المستهدف والمبلغ وسعر الصرف والرصيد المتاح';
    return;
  }

  submitting.value = true;
  try {
    const payload = buildTransferApiPayload({
      from_account_id: route.params.id,
      to_account_id: transfer.value.to_account_id,
      amount: transfer.value.amount,
      notes: transfer.value.notes,
      exchange_rate: transfer.value.exchange_rate,
      fromAccount: account.value,
      toAccount: transferToAccount.value,
    });

    await axios.post('/api/v1/finance/transfers', payload);

    if (window.addToast) window.addToast('تم تنفيذ التحويل بنجاح', 'success');
    closeTransferModal();
    await fetchAccountData();
    await fetchStatement();
  } catch (err) {
    const msg = err.response?.data?.message || 'فشل تنفيذ التحويل';
    transferError.value = msg;
    if (window.addToast) window.addToast(msg, 'error');
  } finally {
    submitting.value = false;
  }
}

async function submitQuickTransaction() {
  if (submitting.value) return;
  submitting.value = true;
  try {
    await financeStore.createTransaction(quickTransaction.value);
    const successMsg = 'تم تسجيل المعاملة المالية بنجاح';
    if (window.addToast) {
      window.addToast(successMsg, 'success');
    } else if (financeStore.addToast) {
      financeStore.addToast(successMsg, 'success');
    }
    showQuickTransactionModal.value = false;
    await fetchAccountData();
    await fetchStatement();
  } catch (err) {
    const msg = err.response?.data?.message || 'فشل إضافة المعاملة';
    if (window.addToast) {
      window.addToast(msg, 'error');
    } else if (financeStore.addToast) {
      financeStore.addToast(msg, 'error');
    }
  } finally {
    submitting.value = false;
  }
}

function getModuleLabel(val) {
  const customMap = {
    flight: 'طيران',
    hajj_umra: 'حج وعمرة',
    visa: 'تأشيرات',
    bus: 'باصات',
    online: 'أونلاين'
  };
  return customMap[val] || financeStore.meta.transactionModules?.find(m => m.value === val)?.label || val;
}

function printStatement() {
  window.print();
}

const exportExcel = () => {
  if (!statement.value.length) return;
  const headers = ['التاريخ', 'القسم/الموظف', 'رقم المرجع/PNR', 'البيان/الوصف', 'مدين (-)', 'دائن (+)', 'الرصيد بعد الحركة'];
  
  const formatDateLocal = (dateString) => {
    if (!dateString) return '';
    try {
      const d = new Date(dateString);
      if (isNaN(d.getTime())) return dateString;
      const day = String(d.getDate()).padStart(2, '0');
      const month = String(d.getMonth() + 1).padStart(2, '0');
      const year = d.getFullYear();
      return `${day}-${month}-${year}`;
    } catch {
      return dateString;
    }
  };

  const rows = statement.value.map(entry => {
    const date = entry.date_human || formatDateLocal(entry.created_at || entry.date) || '';
    const moduleLabel = getModuleLabel(entry.module || account.value?.module || 'general');
    const user = entry.user_name || 'تلقائي';
    const moduleUser = `${moduleLabel} - ${user}`;
    const pnr = entry.booking_details?.pnr || entry.reference_id || '—';
    const description = (entry.description || entry.notes || '').replace(/,/g, ' - ').replace(/[\r\n]+/g, ' ');
    const debit = entry.debit > 0 ? entry.debit : 0;
    const credit = entry.credit > 0 ? entry.credit : 0;
    const balance = entry.balance_after || 0;
    
    return [date, moduleUser, pnr, description, debit, credit, balance];
  });
  
  const csvContent = '\uFEFF' + headers.join(',') + '\n' + rows.map(r => r.join(',')).join('\n');
  const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  const name = statementTargetType.value === 'customer' ? (selectedCustomer.value?.name || 'عميل') : (account.value?.name || 'حساب');
  a.href = url;
  a.download = `كشف_حساب_${name.replace(/\s+/g, '_')}_${new Date().toISOString().split('T')[0]}.csv`;
  a.click();
};

function formatCurrency(amount, currency = 'EGP') {
  return new Intl.NumberFormat('ar-EG', {
    style: 'currency',
    currency: currency,
    minimumFractionDigits: 0,
    maximumFractionDigits: 2
  }).format(Number(amount) || 0);
}

watch(
  () => route.params.id,
  async (newId) => {
    loading.value = true;
    try {
      if (newId) {
        statementTargetType.value = 'account';
        selectedAccountId.value = newId;
        await Promise.all([
          fetchAccountData(),
          fetchStatement()
        ]);
      } else {
        account.value = null;
        selectedAccountId.value = '';
        statement.value = [];
      }
    } catch (err) {
      console.error('Error in route params watcher:', err);
    } finally {
      loading.value = false;
    }
  }
);

onMounted(async () => {
  printSettingsStore.fetch().catch(() => {});
  loading.value = true;
  try {
    const promises = [
      accountStore.fetchAccounts({ per_page: 100 })
    ];
    if (!financeStore.meta.transactionModules.length) {
      promises.push(financeStore.fetchSettingsMeta());
    }
    await Promise.all(promises);
    
    if (route.params.id) {
      statementTargetType.value = 'account';
      selectedAccountId.value = route.params.id;
      await Promise.all([
        fetchAccountData(),
        fetchStatement()
      ]);
    }
  } catch (err) {
    console.error('Error in onMounted:', err);
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.statement-page :deep(.stmt-filter-label) {
  display: block;
  margin-bottom: 0.35rem;
  padding-inline: 0.25rem;
  font-size: 0.875rem;
  font-weight: 800;
  color: rgba(156, 163, 175, 1);
}

.statement-page :deep(.stmt-filter-select),
.statement-page :deep(.stmt-filter-input) {
  width: 100%;
  padding-top: 0.75rem;
  padding-bottom: 0.75rem;
  font-size: 1rem;
  font-weight: 700;
}

.statement-page :deep(.stmt-table th) {
  padding: 1rem 1.5rem;
  font-size: 0.875rem;
  font-weight: 800;
  color: rgba(156, 163, 175, 1);
  white-space: nowrap;
}

.statement-page :deep(.stmt-table td) {
  vertical-align: middle;
}

.flight-panel {
  background-color: rgba(0, 0, 0, 0.4);
  backdrop-filter: blur(24px);
  border-radius: 1.5rem;
  border: 1px solid rgba(255, 255, 255, 0.1);
  padding: 2rem;
  box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
}

.flight-input {
  background-color: rgba(255, 255, 255, 0.05);
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: 1rem;
  padding: 0.75rem 1.25rem;
  color: #f9fafb;
  transition: all 0.3s ease;
  outline: none;
}
.flight-input:focus {
  border-color: rgba(212, 168, 67, 0.5);
}

.flight-select {
  background-color: #000;
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: 1rem;
  padding: 0.75rem 1.25rem;
  color: #f9fafb;
  transition: all 0.3s ease;
  outline: none;
  appearance: none;
}
.flight-select:focus {
  border-color: rgba(212, 168, 67, 0.5);
}

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

.btn-airline-ghost {
  background-color: rgba(255, 255, 255, 0.05);
  border: 1px solid rgba(255, 255, 255, 0.1);
  color: #f9fafb;
}

@media print {
  @page {
    size: A4 portrait;
    margin: 1.5cm;
  }

  /* Universal rule to force high-fidelity color graphics and backgrounds during print */
  * {
    print-color-adjust: exact !important;
    -webkit-print-color-adjust: exact !important;
    color-adjust: exact !important;
  }

  .flight-input, 
  .btn-airline, 
  .teleport,
  header {
    display: none !important;
  }
  
  .space-y-8 {
    margin: 0 !important;
    padding: 0 !important;
  }

  /* Restrict white overrides to only generic tables and main list views */
  table {
    width: 100% !important;
    border-collapse: collapse !important;
    color: black !important;
    font-size: 10pt !important;
    direction: rtl !important;
  }

  tr {
    page-break-inside: avoid !important;
    break-inside: avoid !important;
  }

  th, td {
    border: 1px solid #000 !important;
    padding: 10px 8px !important;
    color: black !important;
    text-align: right !important;
  }

  th {
    background-color: #f1f5f9 !important;
    font-weight: bold !important;
    color: black !important;
  }

  /* Explicitly target only elements outside the printable receipt for text conversion */
  .main-zone .text-text-main, 
  .main-zone .text-text-muted, 
  .main-zone .text-gold {
    color: black !important;
  }

  .font-mono {
    font-family: monospace !important;
  }
}
</style>

<style>
@media print {
  body, html, #app, .app-shell, .main-zone, .page-body {
    height: auto !important;
    min-height: auto !important;
    max-height: none !important;
    overflow: visible !important;
    position: static !important;
    display: block !important;
    width: auto !important;
    margin: 0 !important;
    padding: 0 !important;
    background: #ffffff !important;
    background-color: #ffffff !important;
    color: #000000 !important;
  }
  .sidebar, .top-bar, .toast-rack, .backdrop {
    display: none !important;
  }

  /* Printer-friendly voucher layout with high contrast */
  body:has(#printable-receipt), html:has(#printable-receipt) {
    background: #ffffff !important;
    background-color: #ffffff !important;
    color: #000000 !important;
  }

  #printable-receipt {
    display: block !important;
    width: 100% !important;
    max-width: 672px !important;
    margin: 0 auto !important;
    padding: 20px !important;
    background: #ffffff !important;
    background-color: #ffffff !important;
    color: #000000 !important;
    box-sizing: border-box !important;
    font-size: 11pt !important;
    line-height: 1.8 !important;
    direction: rtl !important;
    border: 2px double #b45309 !important; /* Double gold-colored border for voucher aesthetics */
    border-radius: 12px !important;
  }

  #printable-receipt * {
    color: #000000 !important;
    text-shadow: none !important;
  }
  
  #printable-receipt .text-gold,
  #printable-receipt h2.text-gold {
    color: #b45309 !important; /* Rich amber/gold that prints clearly */
    font-weight: 800 !important;
  }

  #printable-receipt .text-sky-400 {
    color: #0369a1 !important; /* Dark sky blue */
  }

  #printable-receipt .text-success {
    color: #166534 !important; /* Forest green */
  }

  #printable-receipt .text-error {
    color: #991b1b !important; /* Crimson red */
  }

  #printable-receipt .text-text-muted {
    color: #374151 !important; /* Dark grey */
  }

  #printable-receipt .bg-white\/5,
  #printable-receipt .bg-white\/\[0\.02\],
  #printable-receipt .bg-white\/\[0\.03\],
  #printable-receipt .bg-white\/10 {
    background-color: #f3f4f6 !important; /* Light gray background */
    border: 1px solid #d1d5db !important;
  }

  #printable-receipt .grid {
    display: flex !important;
    flex-wrap: wrap !important;
    gap: 15px !important;
    width: 100% !important;
  }
  
  #printable-receipt .grid > div {
    flex: 1 1 45% !important;
    min-width: 200px !important;
    box-sizing: border-box !important;
  }
  
  /* Retain clear borders for print */
  #printable-receipt .border, 
  #printable-receipt .border-t, 
  #printable-receipt .border-b,
  #printable-receipt .border-l,
  #printable-receipt .border-r {
    border-color: #9ca3af !important; /* Gray 400 */
  }
}
</style>
