<template>
  <div class="space-y-8 animate-in fade-in pb-10 duration-700" :class="{ 'print:hidden': selectedEntryDetails }">
    <!-- Professional Print Header (Visible only on print) -->
    <div class="hidden print:block print:mb-10">
      <div class="flex items-center justify-between border-b-2 border-black pb-6">
        <div>
          <h2 class="text-3xl font-black text-black">سفري علينا</h2>
          <p class="text-sm font-bold text-black mt-1">للتسويق السياحي والخدمات الإلكترونية</p>
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
              <p><span class="font-bold">إجمالي المبيعات (عليه):</span> {{ formatCurrency(stats.period_debit) }}</p>
              <p><span class="font-bold">إجمالي المسدد (له):</span> {{ formatCurrency(stats.period_credit) }}</p>
              <p class="col-span-2 text-base mt-1"><span class="font-black">صافي الرصيد المستحق:</span> <strong :class="stats.closing_balance > 0 ? 'text-red-600' : 'text-green-600'">{{ formatCurrency(Math.abs(stats.closing_balance)) }} {{ stats.closing_balance > 0 ? 'عليه (مدين)' : (stats.closing_balance < 0 ? 'له (دائن)' : '') }}</strong></p>
            </template>
          </div>
        </div>
      </div>
    </div>

    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 print:px-0">
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
              <p class="text-[11px] font-black uppercase tracking-[0.2em] text-gold/90">سجل المعاملات المالية</p>
              <div class="h-px w-8 bg-gold/30"></div>
            </div>
            <h1 class="font-display text-4xl font-black tracking-tight text-text-main mt-1">كشف الحساب التفصيلي</h1>
          </div>
        </div>
        
        <div class="flex items-center gap-3 print:hidden">
          <button
            @click="printStatement"
            class="p-3 rounded-xl border border-white/10 bg-white/5 text-text-muted hover:text-sky-400 transition-all hover:bg-white/10"
            title="طباعة الكشف"
          >
            <Printer class="w-5 h-5" />
          </button>
          <button
            v-if="statementTargetType === 'account'"
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
          class="px-6 py-3.5 rounded-2xl font-black text-sm transition-all duration-500 relative overflow-hidden flex items-center gap-2.5"
          :class="statementTargetType === 'account' ? 'bg-gradient-to-r from-gold to-amber-500 text-black shadow-xl shadow-gold/20 scale-105' : 'bg-white/5 text-text-muted hover:bg-white/10 hover:text-white'"
        >
          <Wallet class="w-4 h-4" />
          <span>حسابات الخزائن والبنوك</span>
        </button>
        <button
          type="button"
          @click="setTargetType('customer')"
          class="px-6 py-3.5 rounded-2xl font-black text-sm transition-all duration-500 relative overflow-hidden flex items-center gap-2.5"
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
                <span class="text-xs font-black uppercase tracking-widest">رصيد أول المدة</span>
              </div>
              <p class="font-mono text-2xl font-black text-text-main">{{ formatCurrency(stats.opening_balance, account.currency) }}</p>
            </div>

            <!-- Period Credit -->
            <div class="p-8 space-y-4 bg-white/[0.01]">
              <div class="flex items-center gap-3 text-success">
                <div class="p-2 rounded-lg bg-success/10">
                  <TrendingUp class="w-5 h-5" />
                </div>
                <span class="text-xs font-black uppercase tracking-widest">إجمالي الإيداعات</span>
              </div>
              <p class="font-mono text-2xl font-black text-text-main">{{ formatCurrency(stats.period_credit, account.currency) }}</p>
            </div>

            <!-- Period Debit -->
            <div class="p-8 space-y-4 bg-white/[0.01]">
              <div class="flex items-center gap-3 text-error">
                <div class="p-2 rounded-lg bg-error/10">
                  <TrendingDown class="w-5 h-5" />
                </div>
                <span class="text-xs font-black uppercase tracking-widest">إجمالي المسحوبات</span>
              </div>
              <p class="font-mono text-2xl font-black text-text-main">{{ formatCurrency(stats.period_debit, account.currency) }}</p>
            </div>

            <!-- Period Closing/Current Balance -->
            <div class="p-8 space-y-4 bg-gold/[0.03]">
              <div class="flex items-center gap-3 text-gold">
                <div class="p-2 rounded-lg bg-gold/10">
                  <Wallet class="w-5 h-5" />
                </div>
                <span class="text-xs font-black uppercase tracking-widest">رصيد آخر المدة</span>
              </div>
              <p class="font-mono text-3xl font-black" :class="stats.closing_balance >= 0 ? 'text-success' : 'text-error'">
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
              <span class="text-[11px] font-black uppercase tracking-widest text-text-muted block">إجمالي المبيعات (عليه)</span>
              <p class="font-mono text-xl font-black text-error">{{ formatCurrency(stats.period_debit) }}</p>
            </div>
            <!-- Period Credit (Paid) -->
            <div class="p-6 space-y-2 bg-white/[0.01]">
              <span class="text-[11px] font-black uppercase tracking-widest text-text-muted block">إجمالي المسدد (له)</span>
              <p class="font-mono text-xl font-black text-success">{{ formatCurrency(stats.period_credit) }}</p>
            </div>
            <!-- Closing Balance (Net) -->
            <div class="p-6 space-y-2 bg-gold/[0.03]">
              <span class="text-[11px] font-black uppercase tracking-widest text-gold block">صافي الرصيد المستحق</span>
              <p class="font-mono text-2xl font-black" :class="stats.closing_balance > 0 ? 'text-error' : (stats.closing_balance < 0 ? 'text-success' : 'text-white')">
                {{ formatCurrency(Math.abs(stats.closing_balance)) }}
                <span v-if="stats.closing_balance > 0" class="text-[10px] text-error font-bold inline-block mr-1">عليه (مدين)</span>
                <span v-else-if="stats.closing_balance < 0" class="text-[10px] text-success font-bold inline-block mr-1">له (دائن)</span>
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
              <label class="text-[10px] font-black text-text-muted uppercase tracking-widest px-1 mb-1 block">القسم الرئيسي (سياحة / مكتب)</label>
              <select v-model="accountModuleTypeFilter" @change="onModuleTypeFilterChange" class="flight-select w-full !pl-12 font-bold bg-black border-gold/30">
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
              <label class="text-[10px] font-black text-text-muted uppercase tracking-widest px-1 mb-1 block">الموديول / القسم الفرعي</label>
              <select v-model="accountModuleFilter" @change="onModuleFilterChange" class="flight-select w-full !pl-12 font-bold bg-black border-gold/30">
                <option value="">كل الموديولات</option>
                <option v-for="m in availableAccountModules" :key="m.value" :value="m.value">{{ m.label }}</option>
              </select>
              <div class="absolute left-4 bottom-3.5 text-gold">
                <LayoutGrid class="w-5 h-5" />
              </div>
            </div>

            <!-- Account Selection -->
            <div class="relative group text-right">
              <label class="text-[10px] font-black text-text-muted uppercase tracking-widest px-1 mb-1 block">اختر الحساب المالي (المحفظة)</label>
              <select 
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
              <label class="text-[10px] font-black text-text-muted uppercase tracking-widest px-1 mb-1 block">فئة العميل</label>
              <select v-model="selectedCustomerType" class="flight-select w-full !pl-12 font-bold bg-black border-gold/30">
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
              <label class="text-[10px] font-black text-text-muted uppercase tracking-widest px-1 mb-1 block">اختر العميل / الشركة</label>
              <select 
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
      <div class="mt-8 grid grid-cols-1 md:grid-cols-12 gap-4 items-end print:hidden">
        <div class="md:col-span-4 relative group">
          <Search class="absolute right-4 top-1/2 -translate-y-1/2 w-4 h-4 text-text-muted group-focus-within:text-gold transition-colors" />
          <input 
            v-model="filters.search"
            type="text"
            placeholder="البحث في الوصف، الاسم، أو رقم الحجز..."
            class="flight-input w-full pr-12 font-bold"
            @input="debounceFetch"
          />
        </div>
        
        <div class="md:col-span-2 space-y-1.5">
          <label class="text-[10px] font-black text-text-muted uppercase tracking-widest px-1">الموديول / القسم</label>
          <select v-model="filters.module" class="flight-select w-full !py-2.5 text-xs font-bold" @change="fetchStatement">
            <option value="">كل التفاصيل</option>
            <option v-for="m in financeStore.meta.transactionModules" :key="m.value" :value="m.value">{{ m.label }}</option>
          </select>
        </div>

        <div class="md:col-span-2 space-y-1.5">
          <label class="text-[10px] font-black text-text-muted uppercase tracking-widest px-1">نوع الحركة</label>
          <select v-model="filters.type" class="flight-select w-full !py-2.5 text-xs font-bold" @change="fetchStatement">
            <option value="">الكل (إيداع وسحب)</option>
            <option value="credit">إيداعات فقط (+)</option>
            <option value="debit">مسحوبات فقط (-)</option>
          </select>
        </div>

        <div class="md:col-span-3 grid grid-cols-2 gap-2">
          <div class="space-y-1.5">
            <label class="text-[10px] font-black text-text-muted uppercase tracking-widest px-1">من تاريخ</label>
            <input v-model="filters.from_date" type="date" class="flight-input !py-2.5 text-[11px] font-bold" @change="fetchStatement" />
          </div>
          <div class="space-y-1.5">
            <label class="text-[10px] font-black text-text-muted uppercase tracking-widest px-1">إلى تاريخ</label>
            <input v-model="filters.to_date" type="date" class="flight-input !py-2.5 text-[11px] font-bold" @change="fetchStatement" />
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
      <div class="mt-8 flight-panel !p-0 overflow-hidden border border-white/5 shadow-2xl relative">
        <!-- Loading Overlay -->
        <div v-if="loading" class="absolute inset-0 z-10 bg-black/40 backdrop-blur-[2px] flex items-center justify-center animate-in fade-in duration-300">
          <div class="flex flex-col items-center gap-4">
            <div class="w-12 h-12 border-4 border-gold/20 border-t-gold rounded-full animate-spin"></div>
            <p class="text-xs font-black text-gold uppercase tracking-[0.2em]">جاري تحديث السجل...</p>
          </div>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-right border-collapse">
            <thead>
              <!-- Customer Statement Layout -->
              <tr v-if="statementTargetType === 'customer'" class="bg-white/[0.03] text-[10px] font-black text-text-muted uppercase tracking-[0.2em] border-b border-white/5">
                <th class="px-6 py-5">القسم</th>
                <th class="px-6 py-5">التاريخ</th>
                <th class="px-6 py-5">المرجع / PNR</th>
                <th class="px-6 py-5">البيان / الوصف</th>
                <th class="px-6 py-5">نوع القيد</th>
                <th class="px-6 py-5">مبيعات (عليه)</th>
                <th class="px-6 py-5">مسدد (له)</th>
                <th class="px-6 py-5">الرصيد المستحق</th>
                <th class="px-6 py-5 text-center print:hidden">إجراءات</th>
              </tr>

              <!-- Treasury Layout (Cash, Bank, Post, Wallet) -->
              <tr v-else-if="layoutMode === 'treasury'" class="bg-white/[0.03] text-[10px] font-black text-text-muted uppercase tracking-[0.2em] border-b border-white/5">
                <th class="px-6 py-5">رقم القيد</th>
                <th class="px-6 py-5">التاريخ</th>
                <th class="px-6 py-5">نوع العملية</th>
                <th class="px-6 py-5">البيان / الوصف</th>
                <th class="px-6 py-5">الاسم / الجهة</th>
                <th class="px-6 py-5">إيداع (+)</th>
                <th class="px-6 py-5">سحب (-)</th>
                <th class="px-6 py-5">الرصيد</th>
                <th class="px-6 py-5 text-center">الملاحظات</th>
                <th class="px-6 py-5 text-center print:hidden">إجراءات</th>
              </tr>

              <!-- Online Service Layout -->
              <tr v-else-if="layoutMode === 'online'" class="bg-white/[0.03] text-[10px] font-black text-text-muted uppercase tracking-[0.2em] border-b border-white/5">
                <th class="px-6 py-5">#</th>
                <th class="px-6 py-5">التاريخ</th>
                <th class="px-6 py-5">الموظف</th>
                <th class="px-6 py-5">رقم الطلب</th>
                <th class="px-6 py-5">العميل</th>
                <th class="px-6 py-5">المزود</th>
                <th class="px-6 py-5">الحالة</th>
                <th class="px-6 py-5">خصم</th>
                <th class="px-6 py-5">إضافة</th>
                <th class="px-6 py-5">الرصيد</th>
                <th class="px-6 py-5 text-center print:hidden">إجراءات</th>
              </tr>

              <!-- Commercial Layout (Supplier, Customer, Bus, Flight) -->
              <tr v-else class="bg-white/[0.03] text-[10px] font-black text-text-muted uppercase tracking-[0.2em] border-b border-white/5">
                <th class="px-6 py-5">رقم العملية</th>
                <th class="px-6 py-5">التاريخ</th>
                <th class="px-6 py-5">الموظف</th>
                <th class="px-6 py-5">رقم الحجز</th>
                <th class="px-6 py-5">الاسم</th>
                <th class="px-6 py-5">البيان</th>
                <th class="px-6 py-5">العملية</th>
                <th class="px-6 py-5">دائن</th>
                <th class="px-6 py-5">مدين</th>
                <th class="px-6 py-5">الرصيد بعد</th>
                <th class="px-6 py-5">ملاحظات</th>
                <th class="px-6 py-5 text-center print:hidden">إجراءات</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-white/5">
              <tr v-if="!statement.length && !loading">
                <td :colspan="layoutMode === 'treasury' ? 9 : (layoutMode === 'online' ? 10 : 11)" class="px-6 py-20 text-center">
                  <div class="flex flex-col items-center gap-4 opacity-30">
                    <History class="w-16 h-16" />
                    <p class="text-sm font-bold">لا توجد حركات مالية مسجلة لهذا الحساب في الفترة المحددة</p>
                  </div>
                </td>
              </tr>
              <tr 
                v-for="(entry, idx) in statement" 
                :key="entry.id"
                class="hover:bg-white/[0.02] transition-colors group"
                :class="{ 'animate-in slide-in-from-right-4 fade-in duration-500': true }"
                :style="{ animationDelay: `${idx * 30}ms` }"
              >
                <!-- Customer Statement Body -->
                <template v-if="statementTargetType === 'customer'">
                  <td class="px-6 py-4">
                    <div class="flex items-center gap-2">
                      <span class="p-1.5 rounded-lg bg-white/5 text-gold">
                        <Plane v-if="entry.module === 'flight'" class="w-3.5 h-3.5" />
                        <Compass v-else-if="entry.module === 'hajj_umra'" class="w-3.5 h-3.5" />
                        <FileText v-else-if="entry.module === 'visa'" class="w-3.5 h-3.5" />
                        <Bus v-else-if="entry.module === 'bus'" class="w-3.5 h-3.5" />
                        <Globe v-else class="w-3.5 h-3.5" />
                      </span>
                      <span class="text-xs font-black">{{ getModuleLabel(entry.module) }}</span>
                    </div>
                  </td>
                  <td class="px-6 py-4 text-xs font-bold text-text-main">{{ entry.date_human }}</td>
                  <td class="px-6 py-4 font-mono text-xs text-sky-400 font-black">
                    {{ entry.booking_details?.pnr || entry.reference_id || '—' }}
                  </td>
                  <td class="px-6 py-4">
                    <div class="space-y-1.5">
                      <p class="text-xs font-bold text-text-main">{{ entry.description }}</p>
                      <div v-if="entry.booking_details" class="p-2.5 rounded-xl bg-black/40 border border-white/5 space-y-1 text-[11px] print:border-none print:bg-transparent print:p-0">
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-text-muted font-medium">
                          <span v-if="entry.booking_details.provider_name"><strong class="text-gold print:text-black">الجهة/المزود:</strong> {{ entry.booking_details.provider_name }}</span>
                          <span v-if="entry.booking_details.route"><strong class="text-gold print:text-black">خط السير:</strong> {{ entry.booking_details.route }}</span>
                        </div>
                        <div v-if="entry.booking_details.passengers" class="text-text-muted/80 truncate max-w-md print:max-w-none" :title="entry.booking_details.passengers">
                          <strong class="text-white/70 font-bold print:text-black">المسافرين:</strong> {{ entry.booking_details.passengers }}
                        </div>
                      </div>
                    </div>
                  </td>
                  <td class="px-6 py-4">
                    <span 
                      class="text-[9px] px-2 py-0.5 rounded font-black uppercase print:border print:px-1"
                      :class="entry.credit > 0 ? 'bg-success/10 text-success' : 'bg-gold/10 text-gold'"
                    >
                      {{ entry.process_type }}
                    </span>
                  </td>
                  <td class="px-6 py-4 font-mono">
                    <span v-if="entry.debit > 0" class="text-sm font-black text-error">{{ formatCurrency(entry.debit) }}</span>
                    <span v-else class="text-text-muted/20">—</span>
                  </td>
                  <td class="px-6 py-4 font-mono">
                    <span v-if="entry.credit > 0" class="text-sm font-black text-success">{{ formatCurrency(entry.credit) }}</span>
                    <span v-else class="text-text-muted/20">—</span>
                  </td>
                  <td class="px-6 py-4 font-mono text-sm font-black" :class="entry.balance_after > 0 ? 'text-error' : (entry.balance_after < 0 ? 'text-success' : 'text-text-main')">
                    {{ formatCurrency(Math.abs(entry.balance_after)) }}
                    <span v-if="entry.balance_after > 0" class="text-[9px] text-error block print:inline print:text-black">عليه (مدين)</span>
                    <span v-else-if="entry.balance_after < 0" class="text-[9px] text-success block print:inline print:text-black">له (دائن)</span>
                  </td>
                  <td class="px-6 py-4 text-center print:hidden">
                    <button @click="showEntryDetails(entry)" class="p-1.5 rounded-lg bg-white/5 text-text-muted hover:text-gold transition-colors" title="عرض التفاصيل والطباعة">
                      <Eye class="w-4 h-4" />
                    </button>
                  </td>
                </template>

                <!-- Treasury Body -->
                <template v-else-if="layoutMode === 'treasury'">
                  <td class="px-6 py-4 font-mono text-[10px] text-text-muted">#{{ entry.id }}</td>
                  <td class="px-6 py-4 text-xs font-bold text-text-main">{{ entry.date_human }}</td>
                  <td class="px-6 py-4">
                    <span class="text-[9px] px-2 py-0.5 rounded bg-sky-500/10 text-sky-400 font-black uppercase">{{ entry.payment_method }}</span>
                  </td>
                  <td class="px-6 py-4 text-sm font-bold text-text-main">{{ entry.description }}</td>
                  <td class="px-6 py-4 text-xs text-gold font-bold">{{ entry.entity_name || '—' }}</td>
                  <td class="px-6 py-4 font-mono">
                    <span v-if="entry.credit > 0" class="text-lg font-black text-success">+{{ formatCurrency(entry.credit, account?.currency) }}</span>
                    <span v-else class="text-text-muted/10">—</span>
                  </td>
                  <td class="px-6 py-4 font-mono">
                    <span v-if="entry.debit > 0" class="text-lg font-black text-error">-{{ formatCurrency(entry.debit, account?.currency) }}</span>
                    <span v-else class="text-text-muted/10">—</span>
                  </td>
                  <td class="px-6 py-4 font-mono text-lg font-black text-text-main">{{ formatCurrency(entry.balance_after, account?.currency) }}</td>
                  <td class="px-6 py-4 text-center text-[10px] text-text-muted italic max-w-[150px] truncate">{{ entry.description !== 'معاملة مالية' ? entry.description : '—' }}</td>
                  <td class="px-6 py-4 text-center print:hidden">
                    <button @click="showEntryDetails(entry)" class="p-1.5 rounded-lg bg-white/5 text-text-muted hover:text-gold transition-colors" title="عرض التفاصيل والطباعة">
                      <Eye class="w-4 h-4" />
                    </button>
                  </td>
                </template>

                <!-- Online Body -->
                <template v-else-if="layoutMode === 'online'">
                  <td class="px-6 py-4 font-mono text-[10px] text-text-muted">{{ entry.id }}</td>
                  <td class="px-6 py-4 text-xs font-bold">{{ entry.date_human }}</td>
                  <td class="px-6 py-4 text-xs text-sky-400 font-black">{{ entry.user_name }}</td>
                  <td class="px-6 py-4 font-mono text-xs text-text-main">{{ entry.reference_id || '—' }}</td>
                  <td class="px-6 py-4 text-xs font-bold">{{ entry.entity_name || '—' }}</td>
                  <td class="px-6 py-4 text-[10px] text-text-muted uppercase tracking-wider">{{ entry.provider_name || '—' }}</td>
                  <td class="px-6 py-4">
                    <span 
                      class="text-[9px] px-2 py-0.5 rounded font-black uppercase"
                      :class="entry.status === 'completed' ? 'bg-success/10 text-success' : 'bg-gold/10 text-gold'"
                    >
                      {{ entry.status || 'معلق' }}
                    </span>
                  </td>
                  <td class="px-6 py-4 font-mono text-error font-black">-{{ formatCurrency(entry.debit, account?.currency) }}</td>
                  <td class="px-6 py-4 font-mono text-success font-black">+{{ formatCurrency(entry.credit, account?.currency) }}</td>
                  <td class="px-6 py-4 font-mono text-lg font-black">{{ formatCurrency(entry.balance_after, account?.currency) }}</td>
                  <td class="px-6 py-4 text-center print:hidden">
                    <button @click="showEntryDetails(entry)" class="p-1.5 rounded-lg bg-white/5 text-text-muted hover:text-gold transition-colors" title="عرض التفاصيل والطباعة">
                      <Eye class="w-4 h-4" />
                    </button>
                  </td>
                </template>

                <!-- Commercial Body -->
                <template v-else>
                  <td class="px-6 py-4 font-mono text-[10px] text-text-muted">{{ entry.transaction_id || entry.id }}</td>
                  <td class="px-6 py-4 text-xs font-bold">{{ entry.date_human }}</td>
                  <td class="px-6 py-4 text-[10px] font-black text-sky-400">{{ entry.user_name }}</td>
                  <td class="px-6 py-4 font-mono text-xs text-text-main">{{ entry.reference_id || '—' }}</td>
                  <td class="px-6 py-4 text-sm font-bold">{{ entry.entity_name || '—' }}</td>
                  <td class="px-6 py-4 text-xs text-text-muted max-w-[200px] truncate" :title="entry.description">{{ entry.description }}</td>
                  <td class="px-6 py-4">
                    <span class="text-[9px] px-1.5 py-0.5 rounded bg-white/5 text-text-muted font-black uppercase">{{ entry.process_type }}</span>
                  </td>
                  <td class="px-6 py-4 font-mono text-success font-black">{{ formatCurrency(entry.credit, account?.currency) }}</td>
                  <td class="px-6 py-4 font-mono text-error font-black">{{ formatCurrency(entry.debit, account?.currency) }}</td>
                  <td class="px-6 py-4 font-mono text-lg font-black" :class="entry.balance_after >= 0 ? 'text-text-main' : 'text-error'">
                    {{ formatCurrency(entry.balance_after, account?.currency) }}
                  </td>
                  <td class="px-6 py-4 text-[10px] text-text-muted italic">{{ entry.description !== 'معاملة مالية' ? 'مدرج' : '—' }}</td>
                  <td class="px-6 py-4 text-center print:hidden">
                    <button @click="showEntryDetails(entry)" class="p-1.5 rounded-lg bg-white/5 text-text-muted hover:text-gold transition-colors" title="عرض التفاصيل والطباعة">
                      <Eye class="w-4 h-4" />
                    </button>
                  </td>
                </template>
              </tr>
            </tbody>
            <tfoot class="bg-white/[0.05] border-t-2 border-white/10 font-mono">
              <tr v-if="statementTargetType === 'customer'" class="text-text-main font-black">
                <td colspan="5" class="px-6 py-5 text-left text-xs uppercase tracking-widest text-text-muted">إجماليات مبيعات ومسددات العميل</td>
                <td class="px-6 py-5 text-error font-black">{{ formatCurrency(stats.period_debit) }}</td>
                <td class="px-6 py-5 text-success font-black">{{ formatCurrency(stats.period_credit) }}</td>
                <td class="px-6 py-5 text-xl font-black" :class="stats.closing_balance > 0 ? 'text-error' : (stats.closing_balance < 0 ? 'text-success' : 'text-white')">
                  {{ formatCurrency(Math.abs(stats.closing_balance)) }}
                  <span v-if="stats.closing_balance > 0" class="text-[9px] text-error block print:inline print:text-black">عليه</span>
                  <span v-else-if="stats.closing_balance < 0" class="text-[9px] text-success block print:inline print:text-black">له</span>
                </td>
                <td class="print:hidden"></td>
              </tr>
              <tr v-else-if="layoutMode === 'treasury'" class="text-text-main font-black">
                <td colspan="5" class="px-6 py-5 text-left text-xs uppercase tracking-widest text-text-muted">إجماليات الفترة المختارة</td>
                <td class="px-6 py-5 text-lg text-success">+{{ formatCurrency(stats.period_credit, account?.currency) }}</td>
                <td class="px-6 py-5 text-lg text-error">-{{ formatCurrency(stats.period_debit, account?.currency) }}</td>
                <td colspan="2" class="px-6 py-5 text-2xl text-gold">{{ formatCurrency(stats.closing_balance, account?.currency) }}</td>
                <td class="print:hidden"></td>
              </tr>
              <tr v-else-if="layoutMode === 'online'" class="text-text-main font-black">
                <td colspan="7" class="px-6 py-5 text-left text-xs uppercase tracking-widest text-text-muted">إجمالي العمليات في الفترة</td>
                <td class="px-6 py-5 text-error">-{{ formatCurrency(stats.period_debit, account?.currency) }}</td>
                <td class="px-6 py-5 text-success">+{{ formatCurrency(stats.period_credit, account?.currency) }}</td>
                <td class="px-6 py-5 text-xl text-gold">{{ formatCurrency(stats.closing_balance, account?.currency) }}</td>
                <td class="print:hidden"></td>
              </tr>
              <tr v-else class="text-text-main font-black">
                <td colspan="7" class="px-6 py-5 text-left text-xs uppercase tracking-widest text-text-muted">إجماليات المديونية والدائنية للفترة</td>
                <td class="px-6 py-5 text-success">+{{ formatCurrency(stats.period_credit, account?.currency) }}</td>
                <td class="px-6 py-5 text-error">-{{ formatCurrency(stats.period_debit, account?.currency) }}</td>
                <td colspan="2" class="px-6 py-5 text-2xl text-gold">{{ formatCurrency(stats.closing_balance, account?.currency) }}</td>
                <td class="print:hidden"></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>

        <!-- Pagination -->
        <div v-if="pagination.last_page > 1" class="px-6 py-6 bg-white/[0.02] border-t border-white/5 flex items-center justify-between print:hidden">
          <p class="text-xs font-bold text-text-muted uppercase tracking-widest">
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
            <div class="flex items-center gap-1 px-4 text-xs font-black">
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
          @click.self="showTransferModal = false"
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
              <button @click="showTransferModal = false" class="p-2 text-text-muted hover:text-white transition-colors">
                <X class="w-6 h-6" />
              </button>
            </div>

            <form @submit.prevent="transferFunds" class="p-8 space-y-6">
              <div class="space-y-2">
                <label class="text-[10px] font-black text-text-muted uppercase tracking-widest px-1">الحساب المستهدف</label>
                <div class="relative group">
                  <select 
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
                      {{ acc.name }} ({{ formatCurrency(acc.balance, acc.currency) }})
                    </option>
                  </select>
                  <div class="absolute left-4 top-1/2 -translate-y-1/2 text-text-muted group-focus-within:text-gold transition-colors">
                    <ArrowRight class="w-5 h-5" />
                  </div>
                </div>
              </div>

              <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="space-y-2">
                  <label class="text-[10px] font-black text-text-muted uppercase tracking-widest px-1">المبلغ</label>
                  <div class="relative group">
                    <input 
                      v-model.number="transfer.amount" 
                      type="number" 
                      step="0.01" 
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
                  <label class="text-[10px] font-black text-text-muted uppercase tracking-widest px-1">العملة</label>
                  <div class="flight-input w-full bg-white/5 font-black flex items-center justify-between">
                    <span>{{ account?.currency || 'EGP' }}</span>
                    <Globe class="w-4 h-4 text-text-muted" />
                  </div>
                </div>
              </div>

              <div class="space-y-2">
                <label class="text-[10px] font-black text-text-muted uppercase tracking-widest px-1">ملاحظات العملية</label>
                <textarea 
                  v-model="transfer.notes" 
                  rows="3" 
                  class="flight-input w-full resize-none placeholder:text-text-muted/20"
                  placeholder="اكتب سبب التحويل أو أي تفاصيل إضافية هنا..."
                ></textarea>
              </div>

              <div class="flex gap-4 pt-4">
                <button 
                  type="submit" 
                  :disabled="submitting"
                  class="btn-airline flex-1 py-4 text-base font-black shadow-2xl disabled:opacity-50 flex items-center justify-center gap-3"
                >
                  <span v-if="submitting" class="w-5 h-5 border-2 border-white/30 border-t-white animate-spin rounded-full"></span>
                  {{ submitting ? 'جاري تنفيذ العملية...' : 'اعتماد التحويل المالي' }}
                </button>
                <button 
                  type="button" 
                  @click="showTransferModal = false"
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
          class="fixed inset-0 z-[500] flex items-center justify-center bg-black/90 p-4 sm:p-6 backdrop-blur-xl animate-in fade-in duration-300 print:static print:block print:w-full print:bg-white print:p-0"
          @click.self="selectedEntryDetails = null"
        >
          <div class="flight-panel w-full max-w-2xl !p-0 overflow-hidden shadow-2xl border border-white/10 animate-in zoom-in-95 duration-300 print:border-none print:shadow-none print:max-w-2xl print:mx-auto print:w-full print:block print:rounded-none">
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
            <div id="printable-receipt" class="p-8 space-y-6 text-text-main text-right print:w-full">
              <!-- Official Document Header -->
              <div class="border-b border-white/10 pb-4 text-center">
                <h2 class="text-2xl font-black text-gold">سفري علينا للسياحة</h2>
                <p class="text-xs font-bold text-text-muted mt-1">سند معاملة مالية / كشف تفاصيل قيد شامل</p>
              </div>

              <!-- General Meta Layer -->
              <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs bg-white/[0.02] p-4 rounded-xl border border-white/5 text-right leading-relaxed">
                <div class="space-y-2">
                  <p><span class="font-black text-text-muted">رقم المرجع/القيد:</span> <span class="font-mono text-sky-400 font-bold">{{ selectedEntryDetails.reference_id || selectedEntryDetails.booking_details?.pnr || selectedEntryDetails.id || selectedEntryDetails.transaction_id }}</span></p>
                  <p><span class="font-black text-text-muted">التاريخ والوقت:</span> <span>{{ selectedEntryDetails.date_human }}</span></p>
                  <p><span class="font-black text-text-muted">نوع النظام / القسم:</span> <span class="text-gold font-bold">{{ getModuleLabel(selectedEntryDetails.module || account?.module || 'general') }} <span class="text-[10px] text-text-muted/60 font-mono">({{ selectedEntryDetails.module || account?.module || 'عام' }})</span></span></p>
                  <p><span class="font-black text-text-muted">نوع القيد / الإجراء:</span> <span class="font-bold">{{ selectedEntryDetails.process_type || selectedEntryDetails.payment_method || 'حركة مالية وتسوية' }}</span></p>
                </div>
                <div class="space-y-2">
                  <p><span class="font-black text-text-muted">الحساب المالي المستهدف:</span> <span class="text-sky-400 font-bold">{{ account?.name || selectedEntryDetails.account_name || selectedEntryDetails.treasury_name || 'الحساب الحالي المفتوح' }}</span></p>
                  <p><span class="font-black text-text-muted">الجهة / العميل المستفيد:</span> <span class="text-gold font-bold">{{ statementTargetType === 'customer' ? (selectedCustomer?.name || selectedEntryDetails.entity_name) : (selectedEntryDetails.entity_name || selectedEntryDetails.customer_name || selectedEntryDetails.user_name || '—') }}</span></p>
                  <p><span class="font-black text-text-muted">الموظف المسؤول:</span> <span>{{ selectedEntryDetails.user_name || 'النظام (تلقائي)' }}</span></p>
                </div>
              </div>

              <!-- Main Amount Box -->
              <div class="p-4 rounded-2xl bg-white/5 border border-white/10 text-center">
                <span class="text-[10px] font-black text-text-muted block mb-1 uppercase tracking-widest">قيمة الحركة المالية المسجلة</span>
                <p class="text-3xl font-black font-mono" :class="selectedEntryDetails.credit > 0 ? 'text-success' : 'text-error'">
                  {{ formatCurrency(selectedEntryDetails.credit > 0 ? selectedEntryDetails.credit : selectedEntryDetails.debit, account?.currency) }}
                </p>
                <span class="text-xs font-bold block mt-1" :class="selectedEntryDetails.credit > 0 ? 'text-success' : 'text-error'">
                  ({{ selectedEntryDetails.credit > 0 ? 'إيداع / دائن (إضافة للرصيد)' : 'سحب / مدين (خصم من الرصيد)' }})
                </span>
              </div>

              <!-- Statement Settlement Status Box (عليه فلوس لا حسابه خالص كل حاجة) -->
              <div class="p-4 rounded-xl bg-white/[0.02] border border-white/5 text-right space-y-2">
                <span class="text-[10px] font-black text-gold block uppercase tracking-widest">حالة الحساب والرصيد التراكمي التفصيلي</span>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
                  <div>
                    <span class="text-text-muted block mb-0.5">الرصيد التراكمي بعد هذه الحركة:</span>
                    <span class="font-mono font-bold block text-sm" :class="selectedEntryDetails.balance_after > 0 ? 'text-error' : (selectedEntryDetails.balance_after < 0 ? 'text-success' : 'text-text-main')">
                      {{ formatCurrency(Math.abs(selectedEntryDetails.balance_after || 0), account?.currency) }}
                      <span class="text-[10px] font-sans">({{ selectedEntryDetails.balance_after > 0 ? 'عليه / مدين' : (selectedEntryDetails.balance_after < 0 ? 'له / دائن' : 'رصيد مصفر') }})</span>
                    </span>
                  </div>
                  <div>
                    <span class="text-text-muted block mb-0.5">الموقف المالي الختامي للحساب/العميل:</span>
                    <span class="font-bold block text-xs mt-0.5" :class="stats.closing_balance > 0 ? 'text-error' : (stats.closing_balance < 0 ? 'text-success' : 'text-success')">
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
              <div class="space-y-2 border-t border-white/10 pt-4 text-right">
                <label class="text-[10px] font-black text-gold block uppercase tracking-widest">البيان والتفاصيل</label>
                <p class="text-sm font-bold text-text-main bg-white/5 p-3.5 rounded-xl leading-relaxed">
                  {{ selectedEntryDetails.description }}
                </p>
              </div>

              <!-- Extra Notes if any -->
              <div v-if="selectedEntryDetails.notes" class="space-y-2 border-t border-white/10 pt-4 text-right">
                <label class="text-[10px] font-black text-gold block uppercase tracking-widest">ملاحظات إضافية</label>
                <p class="text-xs font-medium text-text-muted italic">
                  {{ selectedEntryDetails.notes }}
                </p>
              </div>

              <!-- Rich Booking Details Layer (تفاصيل الرحلة كله بطريقة منظمة) -->
              <div v-if="(selectedEntryDetails.booking_details && Object.keys(selectedEntryDetails.booking_details).length) || selectedEntryDetails.booking" class="space-y-3 border-t border-white/10 pt-4 text-right">
                <label class="text-[10px] font-black text-gold block uppercase tracking-widest">تفاصيل الرحلة / الحجز المنظمة بالكامل</label>
                
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3 text-xs bg-white/[0.02] p-4 rounded-xl border border-white/5">
                  
                  <!-- Dedicated layout for specific fields -->
                  <div v-if="selectedEntryDetails.booking_details?.pnr || selectedEntryDetails.booking?.pnr" class="flex flex-col gap-0.5">
                    <span class="text-[10px] text-text-muted">رقم الحجز / PNR</span>
                    <span class="font-mono font-black text-sky-400">{{ selectedEntryDetails.booking_details?.pnr || selectedEntryDetails.booking?.pnr }}</span>
                  </div>
                  <div v-if="selectedEntryDetails.booking_details?.ticket_number || selectedEntryDetails.booking?.ticket_number" class="flex flex-col gap-0.5">
                    <span class="text-[10px] text-text-muted">رقم التذكرة</span>
                    <span class="font-mono font-bold text-text-main">{{ selectedEntryDetails.booking_details?.ticket_number || selectedEntryDetails.booking?.ticket_number }}</span>
                  </div>
                  <div v-if="selectedEntryDetails.booking_details?.route || selectedEntryDetails.booking?.route" class="flex flex-col gap-0.5 sm:col-span-2">
                    <span class="text-[10px] text-text-muted">خط السير / المطارات</span>
                    <span class="font-bold text-text-main">{{ selectedEntryDetails.booking_details?.route || selectedEntryDetails.booking?.route }}</span>
                  </div>
                  <div v-if="selectedEntryDetails.booking_details?.passengers || selectedEntryDetails.booking?.passengers" class="flex flex-col gap-0.5 sm:col-span-2 md:col-span-3">
                    <span class="text-[10px] text-text-muted">أسماء المسافرين</span>
                    <span class="font-bold text-text-main">{{ selectedEntryDetails.booking_details?.passengers || selectedEntryDetails.booking?.passengers }}</span>
                  </div>
                  <div v-if="selectedEntryDetails.booking_details?.flight_number || selectedEntryDetails.booking?.flight_number" class="flex flex-col gap-0.5">
                    <span class="text-[10px] text-text-muted">رقم الرحلة</span>
                    <span class="font-mono text-text-main">{{ selectedEntryDetails.booking_details?.flight_number || selectedEntryDetails.booking?.flight_number }}</span>
                  </div>
                  <div v-if="selectedEntryDetails.booking_details?.provider_name || selectedEntryDetails.booking?.provider_name || selectedEntryDetails.booking_details?.airline" class="flex flex-col gap-0.5">
                    <span class="text-[10px] text-text-muted">المزود / شركة الطيران</span>
                    <span class="font-bold text-gold">{{ selectedEntryDetails.booking_details?.provider_name || selectedEntryDetails.booking?.provider_name || selectedEntryDetails.booking_details?.airline }}</span>
                  </div>
                  <div v-if="selectedEntryDetails.booking_details?.status || selectedEntryDetails.booking?.status" class="flex flex-col gap-0.5">
                    <span class="text-[10px] text-text-muted">حالة التذكرة / الحجز</span>
                    <span class="font-bold text-text-main uppercase">{{ selectedEntryDetails.booking_details?.status || selectedEntryDetails.booking?.status }}</span>
                  </div>
                  
                  <!-- Fallback mapping loop for ANY extra unlisted key inside booking_details -->
                  <template v-if="selectedEntryDetails.booking_details && typeof selectedEntryDetails.booking_details === 'object'">
                    <div 
                      v-for="(val, key) in selectedEntryDetails.booking_details" 
                      :key="key"
                      v-show="!['pnr', 'ticket_number', 'route', 'passengers', 'flight_number', 'provider_name', 'airline', 'status'].includes(key)"
                      class="flex flex-col gap-0.5"
                    >
                      <span class="text-[10px] text-text-muted capitalize">{{ String(key).replace(/_/g, ' ') }}</span>
                      <span class="font-bold text-text-main truncate" :title="String(val)">{{ val || '—' }}</span>
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
</template>

<script setup>
import { ref, computed, onMounted, nextTick, watch } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { storeToRefs } from 'pinia';
import { useAccountStore } from '@/stores/accountStore';
import { useFinanceStore } from '@/stores/financeStore';
import axios from 'axios';
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
  Eye
} from 'lucide-vue-next';

const router = useRouter();
const route = useRoute();
const accountStore = useAccountStore();
const financeStore = useFinanceStore();

// Entry Details Modal State
const selectedEntryDetails = ref(null);

function showEntryDetails(entry) {
  selectedEntryDetails.value = entry;
}

function printSingleEntry() {
  setTimeout(() => {
    window.print();
  }, 100);
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
const loading = ref(false);
const submitting = ref(false);
const showTransferModal = ref(false);
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
  return accounts.value.filter(acc => {
    const matchActive = acc.is_active;
    const matchModuleType = !accountModuleTypeFilter.value || acc.module_type === accountModuleTypeFilter.value;
    const matchModule = !accountModuleFilter.value || 
                       (accountModuleFilter.value === 'general' ? !acc.module : acc.module === accountModuleFilter.value);
    return matchActive && matchModuleType && matchModule;
  });
});

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
  submitting.value = true;
  try {
    await axios.post('/api/v1/finance/transfers', {
      from_account_id: route.params.id,
      to_account_id: transfer.value.to_account_id,
      amount: transfer.value.amount,
      notes: transfer.value.notes
    });
    
    if (window.addToast) window.addToast('تم تنفيذ التحويل بنجاح', 'success');
    showTransferModal.value = false;
    transfer.value = { to_account_id: '', amount: null, notes: '' };
    await fetchAccountData();
    await fetchStatement();
  } catch (err) {
    const msg = err.response?.data?.message || 'فشل تنفيذ التحويل';
    if (window.addToast) window.addToast(msg, 'error');
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

function formatCurrency(amount, currency = 'EGP') {
  return new Intl.NumberFormat('ar-EG', {
    style: 'currency',
    currency: currency,
    minimumFractionDigits: 0,
    maximumFractionDigits: 2
  }).format(Number(amount) || 0);
}

onMounted(async () => {
  await accountStore.fetchAccounts({ per_page: 100 });
  
  if (!financeStore.meta.transactionModules.length) {
    await financeStore.fetchSettingsMeta();
  }
  
  if (route.params.id) {
    statementTargetType.value = 'account';
    selectedAccountId.value = route.params.id;
    await fetchAccountData();
    await fetchStatement();
  } else {
    // Default to loading customers if launched without account ID
    loadCustomers();
  }
});
</script>

<style scoped>
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
  }
  .sidebar, .top-bar, .toast-rack, .backdrop {
    display: none !important;
  }

  /* Flawless Premium Dark Mode Voucher Print Layout */
  body:has(#printable-receipt), html:has(#printable-receipt) {
    background-color: #000 !important;
  }

  #printable-receipt {
    display: block !important;
    width: 100% !important;
    max-width: 672px !important;
    margin: 0 auto !important;
    padding: 20px !important;
    background-color: #000 !important;
    box-sizing: border-box !important;
    font-size: 11pt !important;
    line-height: 1.8 !important;
    direction: rtl !important;
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
  
  /* Retain elegant translucent borders on print */
  #printable-receipt .border, 
  #printable-receipt .border-t, 
  #printable-receipt .border-b {
    border-color: rgba(255, 255, 255, 0.1) !important;
  }
}
</style>
