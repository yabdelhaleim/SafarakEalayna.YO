<template>
  <div class="space-y-8 animate-in fade-in pb-10 duration-700">
    <!-- Header Section -->
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 print:hidden">
      <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <p class="text-[11px] font-bold uppercase tracking-[0.18em] text-gold/90">النظام المالي والمحاسبي</p>
          <h1 class="font-display text-4xl font-black tracking-tight text-text-main">الخزينة العامة الموحدة</h1>
        </div>
        <div class="flex items-center gap-3">
          <button 
            @click="fetchOverview"
            class="p-2.5 rounded-xl border border-white/10 bg-white/5 text-text-muted hover:text-gold transition-all duration-300 hover:bg-white/10"
            title="تحديث البيانات"
          >
            <RefreshCw class="w-5 h-5" :class="{ 'animate-spin': loading }" />
          </button>
          <button 
            @click="openTransferModal"
            class="btn-airline inline-flex items-center gap-2 px-6 py-3 text-sm font-black shadow-xl shadow-gold/10 hover:shadow-gold/20 transition-all duration-500"
          >
            <ArrowRightLeft class="w-5 h-5" />
            تحويل بين الخزن والمحافظ
          </button>
        </div>
      </div>

      <!-- اختيار القسم -->
      <div class="mt-10 flex justify-center print:hidden">
        <div class="bg-white/5 border border-white/10 p-1.5 rounded-2xl flex items-center gap-1">
          <button
            v-for="cat in categories"
            :key="cat.id"
            type="button"
            @click="selectedCategory = cat.id"
            class="px-8 py-3 rounded-xl text-sm font-black transition-all duration-500 flex items-center gap-3"
            :class="selectedCategory === cat.id ? 'bg-gold text-black shadow-xl' : 'text-text-muted hover:text-white'"
          >
            <component :is="cat.icon" class="w-5 h-5" />
            {{ cat.label }}
            <span class="font-mono text-[10px] opacity-80">({{ statsByCategory[cat.id]?.accounts_count || 0 }})</span>
          </button>
        </div>
      </div>

      <!-- Main Sections for Treasury Categories -->
      <template v-if="selectedCategory !== 'trial_balance'">
        <!-- Quick Stats -->
        <div class="mt-8 space-y-3">
        <p class="text-xs text-text-muted text-center">
          {{ statsScopeLabel }}
        </p>
        <div class="flex flex-wrap items-center justify-center gap-2">
          <span
            v-for="chip in categoryModuleChips"
            :key="chip.key"
            class="rounded-lg border px-3 py-1 text-[11px] font-bold"
            :class="chip.active
              ? 'border-gold/30 bg-gold/10 text-gold'
              : 'border-white/5 bg-white/[0.02] text-text-muted/50'"
            :title="chip.active ? formatCurrency(chip.balance) : 'لا توجد حسابات مسجلة'"
          >
            {{ chip.label }}
            <span v-if="chip.active" class="mr-1 font-mono text-[10px]">{{ formatCurrency(chip.balance) }}</span>
          </span>
        </div>
        <div
          class="grid grid-cols-1 gap-4 sm:grid-cols-2"
          :class="displayStats.total_treasury > 0 ? 'xl:grid-cols-6' : 'xl:grid-cols-5'"
        >
          <div class="flight-panel p-5 relative overflow-hidden group border-l-4 border-l-gold">
            <p class="text-xs font-bold text-text-muted uppercase tracking-widest">إجمالي السيولة</p>
            <p class="mt-2 font-mono text-2xl font-black text-gold">{{ formatCurrency(displayStats.total_liquidity) }}</p>
            <p class="mt-1 text-[10px] text-text-muted">{{ displayStats.accounts_count }} حساب</p>
          </div>
          <div class="flight-panel p-5 border-l-4 border-l-sky-500">
            <p class="text-xs font-bold text-text-muted uppercase tracking-widest">إجمالي البنوك</p>
            <p class="mt-2 font-mono text-2xl font-black text-text-main">{{ formatCurrency(displayStats.total_banks) }}</p>
          </div>
          <div class="flight-panel p-5 border-l-4 border-l-amber-500">
            <p class="text-xs font-bold text-text-muted uppercase tracking-widest">النقدي الإجمالي</p>
            <p class="mt-2 font-mono text-2xl font-black text-text-main">{{ formatCurrency(displayStats.total_cashbox) }}</p>
          </div>
          <div class="flight-panel p-5 border-l-4 border-l-purple-500">
            <p class="text-xs font-bold text-text-muted uppercase tracking-widest">الكاش الإجمالي</p>
            <p class="mt-2 font-mono text-2xl font-black text-text-main">{{ formatCurrency(displayStats.total_wallets) }}</p>
          </div>
          <div class="flight-panel p-5 border-l-4 border-l-emerald-500">
            <p class="text-xs font-bold text-text-muted uppercase tracking-widest">البريد الإجمالي</p>
            <p class="mt-2 font-mono text-2xl font-black text-text-main">{{ formatCurrency(displayStats.total_post) }}</p>
          </div>
          <div
            v-if="displayStats.total_treasury > 0"
            class="flight-panel p-5 border-l-4 border-l-rose-500"
          >
            <p class="text-xs font-bold text-text-muted uppercase tracking-widest">خزائن عامة</p>
            <p class="mt-2 font-mono text-2xl font-black text-text-main">{{ formatCurrency(displayStats.total_treasury) }}</p>
          </div>
        </div>
        <p
          v-if="!displayStats.accounts_count"
          class="rounded-xl border border-amber-500/25 bg-amber-500/10 px-4 py-3 text-center text-xs text-amber-100/90"
        >
          لا توجد حسابات سيولة مسجلة في «{{ currentCategoryLabel }}».
          <button
            v-if="otherCategoryHasAccounts"
            type="button"
            class="mr-1 font-bold text-gold underline"
            @click="selectedCategory = otherCategoryId"
          >
            انتقل إلى {{ otherCategoryLabel }}
          </button>
          <span v-else>لا توجد حسابات في أي قسم حالياً.</span>
        </p>
      </div>

      <p class="mt-6 text-xs text-text-muted text-center max-w-3xl mx-auto leading-relaxed">
        {{ unifiedHintText }}
      </p>

      <!-- Unified accounts (per section) -->
      <div class="mt-8 space-y-6">
        <div class="flex flex-wrap gap-2">
          <button
            v-for="tab in typeTabs"
            :key="tab.id"
            type="button"
            @click="selectedTypeTab = tab.id"
            class="px-4 py-2 rounded-xl text-xs font-black border transition-all"
            :class="selectedTypeTab === tab.id
              ? 'border-gold/40 bg-gold/15 text-gold'
              : 'border-white/10 bg-white/5 text-text-muted hover:text-white'"
          >
            {{ tab.label }}
            <span class="mr-1 font-mono text-[10px] opacity-80">({{ unifiedTypeCount(tab.id) }})</span>
          </button>
        </div>

        <div class="grid grid-cols-1 gap-4">
          <div
            v-for="group in filteredUnifiedAccounts"
            :key="group.key"
            class="flight-panel !p-0 overflow-hidden border border-white/5"
          >
            <button
              type="button"
              class="w-full px-6 py-5 flex items-center justify-between gap-4 text-right hover:bg-white/[0.02] transition-colors"
              @click="toggleUnifiedGroup(group.key)"
            >
              <div class="flex items-center gap-4 min-w-0">
                <div class="w-2.5 h-2.5 rounded-full shrink-0" :class="getTypeColor(group.type)"></div>
                <div class="min-w-0 text-right">
                  <div class="flex flex-wrap items-center gap-2">
                    <h3 class="font-black text-text-main truncate">{{ group.display_name }}</h3>
                    <span class="text-[10px] px-2 py-0.5 rounded bg-white/10 text-text-muted font-bold">{{ group.type_label }}</span>
                    <span class="text-[10px] px-2 py-0.5 rounded bg-white/5 text-text-muted font-mono">{{ group.currency }}</span>
                  </div>
                  <p class="text-[11px] text-text-muted mt-1">
                    {{ group.accounts_count }} حساب · {{ group.modules?.length || 0 }} موديول
                  </p>
                </div>
              </div>
              <div class="flex items-center gap-4 shrink-0">
                <p class="font-mono text-xl font-black text-gold">{{ formatCurrency(group.total_balance, group.currency) }}</p>
                <ChevronDown
                  class="w-5 h-5 text-text-muted transition-transform"
                  :class="{ 'rotate-180': expandedUnifiedKeys.has(group.key) }"
                />
              </div>
            </button>

            <div
              v-if="expandedUnifiedKeys.has(group.key)"
              class="border-t border-white/5 bg-black/20 divide-y divide-white/5"
            >
              <div
                v-for="mod in group.modules"
                :key="group.key + '-' + mod.key"
                class="px-6 py-4"
              >
                <div class="flex items-center justify-between gap-3 mb-3">
                  <div class="flex items-center gap-2">
                    <component :is="getModuleIcon(mod.key)" class="w-4 h-4 text-gold/70" />
                    <span class="font-bold text-sm text-text-main">{{ mod.label }}</span>
                  </div>
                  <span class="font-mono text-sm font-black text-emerald-300">{{ formatCurrency(mod.balance, group.currency) }}</span>
                </div>
                <div class="space-y-2">
                  <div
                    v-for="acc in mod.accounts"
                    :key="acc.id"
                    class="flex items-center justify-between gap-3 rounded-lg border border-white/5 bg-white/[0.02] px-4 py-3"
                  >
                    <div class="min-w-0">
                      <p class="font-bold text-sm text-white truncate">{{ acc.name }}</p>
                      <p class="text-[10px] text-text-muted">{{ acc.type_label }}</p>
                    </div>
                    <div class="flex items-center gap-3 shrink-0">
                      <div class="text-right">
                        <span class="font-mono text-sm font-bold text-success block">{{ formatCurrency(acc.balance, acc.currency) }}</span>
                        <span v-if="acc.currency && acc.currency !== 'EGP'" class="text-[10px] text-text-muted block mt-0.5 font-bold">
                          (= {{ formatCurrency(acc.balance_egp, 'EGP') }})
                        </span>
                      </div>
                      <button
                        type="button"
                        class="p-1.5 rounded-lg bg-white/5 text-text-muted hover:text-sky-400"
                        title="كشف الحساب"
                        @click.stop="viewStatement(acc.id)"
                      >
                        <FileText class="w-4 h-4" />
                      </button>
                      <button
                        type="button"
                        class="p-1.5 rounded-lg bg-white/5 text-text-muted hover:text-gold"
                        title="تحويل"
                        @click.stop="quickTransfer(acc)"
                      >
                        <ArrowRightLeft class="w-4 h-4" />
                      </button>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div
            v-if="!filteredUnifiedAccounts.length"
            class="flight-panel py-16 text-center text-text-muted italic"
          >
            لا توجد حسابات في هذا التصنيف حالياً
          </div>
        </div>
      </div>

      <!-- Liquidity Distribution Section -->
      <div class="mt-8 flight-panel p-8">
        <div class="flex items-center justify-between mb-8">
          <div>
            <h2 class="text-xl font-black text-text-main">توزيع السيولة ({{ currentCategoryLabel }})</h2>
            <p class="text-xs text-text-muted mt-1">مساهمة كل موديول ضمن {{ categoryModulesListText }}</p>
          </div>
          <PieChart class="w-6 h-6 text-gold" />
        </div>
        <div class="space-y-6">
          <div v-for="(module, key) in filteredModules" :key="'dist-'+key" class="space-y-2">
            <div class="flex items-center justify-between text-xs">
              <div class="flex items-center gap-2">
                <component :is="getModuleIcon(key)" class="w-4 h-4 text-gold/60" />
                <span class="font-bold text-text-main">{{ module.label }}</span>
              </div>
              <div class="flex items-center gap-4">
                <span class="font-mono text-text-muted">{{ formatCurrency(getModuleTotal(module.accounts)) }}</span>
                <span class="font-black text-gold">{{ getModulePercentage(module.accounts) }}%</span>
              </div>
            </div>
            <div class="h-1.5 w-full bg-white/5 rounded-full overflow-hidden">
              <div 
                class="h-full bg-gradient-to-l from-gold to-amber-500 transition-all duration-1000 ease-out"
                :style="{ width: getModulePercentage(module.accounts) + '%' }"
              ></div>
            </div>
          </div>
          <div v-if="!Object.keys(filteredModules).length" class="text-center py-4 text-text-muted italic text-sm">
            لا توجد حسابات مسجلة لهذا القسم حالياً
          </div>
        </div>
      </div>

      <!-- Recent Transfers History Section -->
      <div class="mt-16 space-y-6">
        <div class="flex items-center justify-between px-2">
          <div>
            <h2 class="text-2xl font-black text-text-main">أحدث التحويلات المالية</h2>
            <p class="text-sm text-text-muted">سجل العمليات التي تمت بين الخزن والمحافظ مؤخراً</p>
          </div>
          <History class="w-8 h-8 text-white/10" />
        </div>

        <div class="flight-panel !p-0 overflow-hidden border border-white/5 shadow-2xl">
          <table class="w-full text-right border-collapse">
            <thead>
              <tr class="bg-white/[0.03] text-[10px] font-black text-text-muted uppercase tracking-widest border-b border-white/5">
                <th class="px-6 py-4">التاريخ</th>
                <th class="px-6 py-4">من (المصدر)</th>
                <th class="px-6 py-4 text-center"><ArrowRight class="w-4 h-4 inline" /></th>
                <th class="px-6 py-4">إلى (المستهدف)</th>
                <th class="px-6 py-4">المبلغ</th>
                <th class="px-6 py-4">المسؤول</th>
                <th class="px-6 py-4">ملاحظات</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-white/5">
              <tr v-if="!recentTransfers.length" class="hover:bg-white/[0.01]">
                <td colspan="7" class="px-6 py-12 text-center text-text-muted italic">لا توجد عمليات تحويل مسجلة حالياً</td>
              </tr>
              <tr v-for="t in recentTransfers" :key="t.id" class="hover:bg-white/[0.02] transition-colors group">
                <td class="px-6 py-4 text-xs font-bold text-text-muted">{{ t.date }}</td>
                <td class="px-6 py-4 text-sm font-bold text-text-main">{{ t.from_account }}</td>
                <td class="px-6 py-4 text-center">
                  <div class="w-6 h-6 rounded-full bg-gold/10 flex items-center justify-center mx-auto">
                    <ChevronLeft class="w-3 h-3 text-gold" />
                  </div>
                </td>
                <td class="px-6 py-4 text-sm font-bold text-text-main">{{ t.to_account }}</td>
                <td class="px-6 py-4">
                  <span class="font-mono text-sm font-black text-gold">{{ formatCurrency(t.amount) }}</span>
                </td>
                <td class="px-6 py-4 text-xs font-bold text-sky-400">{{ t.user }}</td>
                <td class="px-6 py-4 text-xs text-text-muted max-w-[200px] truncate" :title="t.notes">{{ t.notes || '-' }}</td>
              </tr>
              </tbody>
            </table>
          </div>
        </div>
      </template>

      <!-- ==================== TAB 3: TRIAL BALANCE ==================== -->
      <template v-else-if="selectedCategory === 'trial_balance'">
        <div v-if="loading && !trialBalance" class="flight-panel py-20 text-center text-text-muted">
          <RefreshCw class="w-8 h-8 mx-auto mb-4 animate-spin text-gold" />
          <p class="text-sm font-bold">جاري تحميل ميزان الحسابات...</p>
        </div>
        <div v-else-if="!trialBalance" class="flight-panel py-20 text-center text-text-muted">
          <p class="text-sm font-bold">تعذر تحميل بيانات ميزان الحسابات</p>
          <button type="button" class="btn-airline mt-4 px-6 py-2 text-xs" @click="fetchOverview">إعادة المحاولة</button>
        </div>
        <div v-else class="space-y-8 animate-in fade-in duration-500">
          
          <!-- Professional Print Header (Visible only on print) -->
          <div class="hidden print:block print:mb-8">
            <div class="flex items-center justify-between border-b-2 border-black pb-4">
              <div>
                <h2 class="text-2xl font-black text-black">سفري علينا</h2>
                <p class="text-xs font-bold text-black mt-1">للتسويق السياحي والخدمات الإلكترونية</p>
              </div>
              <div class="text-right">
                <h1 class="text-xl font-black text-black">تقرير ميزان حسابات قسم السياحة (جرد لحظي لرأس المال)</h1>
                <p class="text-[10px] font-bold text-black mt-1 font-mono">تاريخ الطباعة: {{ new Date().toLocaleString('ar-EG') }}</p>
              </div>
            </div>
          </div>
          
          <!-- Excel Download & Capital Status Banner -->
          <div class="flight-panel p-6 border-l-4" :class="statusBorderColor(trialBalance.status)">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-6">
              <div>
                <h3 class="text-lg font-black text-text-main flex items-center gap-2">
                  <Scale class="w-5 h-5 text-gold" />
                  حالة توازن رأس المال والعمليات المحاسبية
                </h3>
                <p class="text-xs text-text-muted mt-1">
                  المقارنة والتدقيق بين رأس المال الفعلي (السيولة والأرصدة والديون) ورأس المال المفترض (الأساسي والأرباح)
                </p>
                
                <!-- Status Badge -->
                <div class="mt-4 flex items-center gap-3">
                  <span class="text-xs font-bold text-gray-400">حالة المطابقة:</span>
                  <span :class="statusBadgeClass(trialBalance.status)" class="px-4 py-1.5 rounded-full text-xs font-black flex items-center gap-2 border">
                    <span class="w-2 h-2 rounded-full animate-pulse" :class="statusDotColor(trialBalance.status)"></span>
                    {{ trialBalance.status }}
                  </span>
                  <span v-if="trialBalance.variance !== 0" class="font-mono text-sm" :class="trialBalance.variance > 0 ? 'text-sky-400' : 'text-rose-400'">
                    (الفرق: {{ formatCurrency(trialBalance.variance) }})
                  </span>
                </div>
              </div>
              <div class="shrink-0 flex items-center gap-3 print:hidden">
                <button
                  type="button"
                  @click="printTrialBalance"
                  class="flex items-center gap-2 px-6 py-3.5 rounded-xl border border-rose-500/20 bg-rose-500/5 text-rose-300 hover:text-white hover:bg-rose-500/20 transition-all font-black text-sm"
                  title="تصدير كملف PDF"
                >
                  <FileText class="w-5 h-5 text-rose-400" />
                  تصدير PDF
                </button>

                <button
                  type="button"
                  @click="exportTrialBalanceExcel"
                  class="btn-airline inline-flex items-center gap-2 px-6 py-3.5 text-sm font-black shadow-xl shadow-gold/10 hover:shadow-gold/20 transition-all duration-300"
                >
                  <Download class="w-5 h-5" />
                  تحميل كشف ميزان الحسابات (ميزان (1).xlsx)
                </button>
              </div>
            </div>
          </div>

          <!-- Dynamic Capital Equation Cards (Visual Flow) -->
          <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            
            <!-- 1. Total Module Balances (إجمالي أرصدة الموديولات) -->
            <div class="flight-panel p-6 border-t-4 border-t-amber-500 relative overflow-hidden h-full flex flex-col justify-between">
              <div>
                <span class="absolute top-2 left-2 text-[9px] font-bold text-amber-500 bg-amber-500/10 px-1.5 py-0.5 rounded">موجب (+)</span>
                <p class="text-xs font-bold text-text-muted uppercase tracking-wider">1. إجمالي أرصدة الموديولات</p>
                <p class="mt-3 font-mono text-2xl font-black text-text-main">{{ formatCurrency(trialBalance.total_balances) }}</p>
              </div>
              <div class="mt-6 pt-4 border-t border-white/5 text-xs text-text-muted space-y-2">
                <div class="flex justify-between items-center">
                  <span>طيران (أنظمة/ناقلين):</span>
                  <span class="font-mono font-bold text-white">{{ formatCurrency(trialBalance.details?.flight_balances || 0) }}</span>
                </div>
                <div class="flex justify-between items-center">
                  <span>حج وعمرة (تأمين ودائع):</span>
                  <span class="font-mono font-bold text-white">{{ formatCurrency(trialBalance.details?.hajj_umra_balances || 0) }}</span>
                </div>
                <div class="flex justify-between items-center">
                  <span>تأشيرات (عهد وكلاء):</span>
                  <span class="font-mono font-bold text-white">{{ formatCurrency(trialBalance.details?.visa_balances || 0) }}</span>
                </div>
              </div>
            </div>

            <!-- 2. Total Liquidity (إجمالي السيولة) -->
            <div class="flight-panel p-6 border-t-4 border-t-sky-500 relative overflow-hidden h-full flex flex-col justify-between">
              <div>
                <span class="absolute top-2 left-2 text-[9px] font-bold text-sky-500 bg-sky-500/10 px-1.5 py-0.5 rounded">موجب (+)</span>
                <p class="text-xs font-bold text-text-muted uppercase tracking-wider">2. إجمالي السيولة</p>
                <p class="mt-3 font-mono text-2xl font-black text-text-main">{{ formatCurrency(trialBalance.total_liquidity) }}</p>
              </div>
              <div class="mt-6 pt-4 border-t border-white/5 text-xs text-text-muted leading-relaxed">
                تجميع أرصدة البنوك، الخزن، والمحافظ الإلكترونية النشطة والمتاحة في موديولات قسم السياحة.
              </div>
            </div>

            <!-- 3. Due to Us (المستحق لنا) -->
            <div class="flight-panel p-6 border-t-4 border-t-emerald-500 relative overflow-hidden h-full flex flex-col justify-between">
              <div>
                <span class="absolute top-2 left-2 text-[9px] font-bold text-emerald-500 bg-emerald-500/10 px-1.5 py-0.5 rounded">موجب (+)</span>
                <p class="text-xs font-bold text-text-muted uppercase tracking-wider">3. المستحق لنا (المدينون)</p>
                <p class="mt-3 font-mono text-2xl font-black text-text-main">{{ formatCurrency(trialBalance.due_to_us) }}</p>
              </div>
              <div class="mt-6 pt-4 border-t border-white/5 text-xs text-text-muted leading-relaxed">
                يشمل مديونيات العملاء والعهد الخارجية ومستحقات الموردين المدينة.
              </div>
            </div>

          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            
            <!-- 4. Due from Us (المستحق علينا) -->
            <div class="flight-panel p-6 border-t-4 border-t-rose-500 relative overflow-hidden h-full flex flex-col justify-between">
              <div>
                <span class="absolute top-2 left-2 text-[9px] font-bold text-rose-500 bg-rose-500/10 px-1.5 py-0.5 rounded">يُطرح (-)</span>
                <p class="text-xs font-bold text-text-muted uppercase tracking-wider">4. المستحق علينا (الدائنون)</p>
                <p class="mt-3 font-mono text-2xl font-black text-rose-400">-{{ formatCurrency(trialBalance.due_from_us) }}</p>
              </div>
              <div class="mt-6 pt-4 border-t border-white/5 text-xs text-text-muted leading-relaxed">
                يشمل مستحقات الموردين المعلقة، والعهد الدائنة، ودفعات العملاء المقدمة.
              </div>
            </div>

            <!-- 5. Current Capital (رأس المال الحالي) -->
            <div class="flight-panel p-6 bg-gradient-to-br from-slate-900 to-amber-950/40 border border-amber-500/30 relative overflow-hidden h-full flex flex-col justify-between">
              <div>
                <span class="absolute top-2 left-2 text-[9px] font-bold text-gold bg-gold/10 px-1.5 py-0.5 rounded">الفعلي (=)</span>
                <p class="text-xs font-bold text-gold uppercase tracking-wider">رأس المال الحالي</p>
                <p class="mt-3 font-mono text-3xl font-black text-gold">{{ formatCurrency(trialBalance.current_capital) }}</p>
              </div>
              <div class="mt-6 pt-4 border-t border-white/5 text-xs text-text-muted leading-relaxed">
                ناتج المعادلة: (أرصدة الموديولات + السيولة + المستحق لنا) - المستحق علينا.
              </div>
            </div>

          </div>

          <!-- Matching verification vs Base Capital + Profits -->
          <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            
            <!-- Capital Settings & verification -->
            <div class="flight-panel lg:col-span-2 space-y-6">
              <h4 class="text-base font-bold text-white flex items-center gap-2">
                <Scale class="w-5 h-5 text-emerald-400" />
                مطابقة توازن رأس المال
              </h4>

              <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="p-4 bg-white/5 rounded-2xl border border-white/5">
                  <span class="text-xs text-text-muted">رأس المال الحالي (الفعلي)</span>
                  <div class="mt-1.5 font-mono text-xl font-black text-white">{{ formatCurrency(trialBalance.current_capital) }}</div>
                </div>
                <div class="p-4 bg-white/5 rounded-2xl border border-white/5">
                  <span class="text-xs text-text-muted">رأس المال المستهدف (الأساسي + الأرباح)</span>
                  <div class="mt-1.5 font-mono text-xl font-black text-white">{{ formatCurrency(trialBalance.expected_capital) }}</div>
                </div>
              </div>

              <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 border-t border-white/5 pt-6">
                <div class="p-4 bg-white/5 rounded-2xl border border-white/5">
                  <span class="text-xs text-text-muted">رأس المال الأساسي (الافتتاحي)</span>
                  <div class="mt-1.5 font-mono text-lg font-bold text-white">{{ formatCurrency(trialBalance.base_capital) }}</div>
                </div>
                <div class="p-4 bg-white/5 rounded-2xl border border-white/5">
                  <span class="text-xs text-text-muted">إجمالي الأرباح المحققة</span>
                  <div class="mt-1.5 font-mono text-lg font-bold text-emerald-400">{{ formatCurrency(trialBalance.profits) }}</div>
                </div>
              </div>

              <!-- Base Capital Settings Form -->
              <form @submit.prevent="updateBaseCapital" class="border-t border-white/5 pt-6 space-y-4 print:hidden">
                <div>
                  <h5 class="text-sm font-bold text-white mb-1">تعديل رأس المال الأساسي (الافتتاحي)</h5>
                  <p class="text-xs text-text-muted">تحديد رأس مال الشركة الأساسي لمقارنة توازن الحسابات والقيود بناءً عليه</p>
                </div>
                <div class="flex gap-3">
                  <div class="relative flex-1 group">
                    <input
                      v-model.number="baseCapitalInput"
                      type="number"
                      step="0.01"
                      min="0"
                      required
                      class="flight-input w-full font-mono font-bold text-white bg-black/40"
                    />
                    <div class="absolute left-4 top-1/2 -translate-y-1/2 text-gold">EGP</div>
                  </div>
                  <button
                    type="submit"
                    :disabled="submitting"
                    class="btn-airline px-6 py-3 text-xs font-black shadow-lg flex items-center gap-2"
                  >
                    <span v-if="submitting" class="w-3.5 h-3.5 border border-black/30 border-t-black animate-spin rounded-full"></span>
                    {{ submitting ? 'جاري الحفظ...' : 'تحديث رأس المال الافتتاحي' }}
                  </button>
                </div>
              </form>
            </div>

            <!-- Average Purchase Price card -->
            <div class="flight-panel space-y-6">
              <h4 class="text-base font-bold text-white flex items-center gap-2">
                <RefreshCw class="w-5 h-5 text-gold animate-spin-slow" />
                سعر شراء العملات الأجنبية
              </h4>
              <p class="text-xs text-text-muted leading-relaxed">
                يُحسب متوسط سعر الشراء آلياً وبلحظته بناءً على تكاليف حجز الطيران الفعلية بالعملة الأجنبية مقابل الجنيه المصري في موديول الطيران.
              </p>

              <div class="space-y-3">
                <div v-for="(rate, curr) in trialBalance.rates" :key="curr" class="p-3 bg-white/5 rounded-xl flex items-center justify-between">
                  <span class="text-xs font-bold text-white">{{ curr }} / EGP</span>
                  <span class="font-mono text-sm font-black text-gold">{{ rate.toFixed(4) }}</span>
                </div>
              </div>
              
              <div class="text-[10px] text-text-muted bg-white/[0.02] border border-white/5 p-3 rounded-xl leading-relaxed">
                ⚠️ في حال عدم وجود حجوزات للعملة، يتم الاعتماد على أحدث أسعار صرف مسجلة بنظام أسعار الصرف.
              </div>
            </div>

          </div>

        </div>
      </template>

      <!-- ==================== TAB 4: OFFICE TRIAL BALANCE ==================== -->
      <template v-else-if="selectedCategory === 'office_trial_balance'">
        <div v-if="loading && !officeTrialBalance" class="flight-panel py-20 text-center text-text-muted">
          <RefreshCw class="w-8 h-8 mx-auto mb-4 animate-spin text-gold" />
          <p class="text-sm font-bold">جاري تحميل ميزان الحسابات للمكتب...</p>
        </div>
        <div v-else-if="!officeTrialBalance" class="flight-panel py-20 text-center text-text-muted">
          <p class="text-sm font-bold">تعذر تحميل بيانات ميزان الحسابات للمكتب</p>
          <button type="button" class="btn-airline mt-4 px-6 py-2 text-xs" @click="fetchOverview">إعادة المحاولة</button>
        </div>
        <div v-else class="space-y-8 animate-in fade-in duration-500">
          
          <!-- Professional Print Header (Visible only on print) -->
          <div class="hidden print:block print:mb-8">
            <div class="flex items-center justify-between border-b-2 border-black pb-4">
              <div>
                <h2 class="text-2xl font-black text-black">سفري علينا</h2>
                <p class="text-xs font-bold text-black mt-1">للتسويق السياحي والخدمات الإلكترونية</p>
              </div>
              <div class="text-right">
                <h1 class="text-xl font-black text-black">تقرير ميزان حسابات قسم المكتب (جرد لحظي لرأس المال)</h1>
                <p class="text-[10px] font-bold text-black mt-1 font-mono">تاريخ الطباعة: {{ new Date().toLocaleString('ar-EG') }}</p>
              </div>
            </div>
          </div>
          
          <!-- Excel Download & Capital Status Banner -->
          <div class="flight-panel p-6 border-l-4" :class="statusBorderColor(officeTrialBalance.status)">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-6">
              <div>
                <h3 class="text-lg font-black text-text-main flex items-center gap-2">
                  <Scale class="w-5 h-5 text-gold" />
                  حالة توازن رأس المال والعمليات المحاسبية للمكتب
                </h3>
                <p class="text-xs text-text-muted mt-1">
                  المقارنة والتدقيق بين رأس المال الفعلي للمكتب (السيولة والأرصدة والديون) ورأس المال المفترض (الأساسي والأرباح)
                </p>
                
                <!-- Status Badge -->
                <div class="mt-4 flex items-center gap-3">
                  <span class="text-xs font-bold text-gray-400">حالة المطابقة:</span>
                  <span :class="statusBadgeClass(officeTrialBalance.status)" class="px-4 py-1.5 rounded-full text-xs font-black flex items-center gap-2 border">
                    <span class="w-2 h-2 rounded-full animate-pulse" :class="statusDotColor(officeTrialBalance.status)"></span>
                    {{ officeTrialBalance.status }}
                  </span>
                  <span v-if="officeTrialBalance.variance !== 0" class="font-mono text-sm" :class="officeTrialBalance.variance > 0 ? 'text-sky-400' : 'text-rose-400'">
                    (الفرق: {{ formatCurrency(officeTrialBalance.variance) }})
                  </span>
                </div>
              </div>
              <div class="shrink-0 flex items-center gap-3 print:hidden">
                <button
                  type="button"
                  @click="printTrialBalance"
                  class="flex items-center gap-2 px-6 py-3.5 rounded-xl border border-rose-500/20 bg-rose-500/5 text-rose-300 hover:text-white hover:bg-rose-500/20 transition-all font-black text-sm"
                  title="تصدير كملف PDF"
                >
                  <FileText class="w-5 h-5 text-rose-400" />
                  تصدير PDF
                </button>

                <button
                  type="button"
                  @click="exportTrialBalanceExcel('office')"
                  class="btn-airline inline-flex items-center gap-2 px-6 py-3.5 text-sm font-black shadow-xl shadow-gold/10 hover:shadow-gold/20 transition-all duration-300"
                >
                  <Download class="w-5 h-5" />
                  تحميل كشف ميزان الحسابات (ميزان_المكتب.xlsx)
                </button>
              </div>
            </div>
          </div>

          <!-- Dynamic Capital Equation Cards (Visual Flow) -->
          <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            
            <!-- 1. Total Module Balances (إجمالي أرصدة الموديولات) -->
            <div class="flight-panel p-6 border-t-4 border-t-amber-500 relative overflow-hidden h-full flex flex-col justify-between">
              <div>
                <span class="absolute top-2 left-2 text-[9px] font-bold text-amber-500 bg-amber-500/10 px-1.5 py-0.5 rounded">موجب (+)</span>
                <p class="text-xs font-bold text-text-muted uppercase tracking-wider">1. أرصدة موديولات المكتب</p>
                <p class="mt-3 font-mono text-2xl font-black text-text-main">{{ formatCurrency(officeTrialBalance.total_balances) }}</p>
              </div>
              <div class="mt-6 pt-4 border-t border-white/5 text-xs text-text-muted space-y-2">
                <div class="flex justify-between items-center">
                  <span>باص (شركات النقل):</span>
                  <span class="font-mono font-bold text-white">{{ formatCurrency(officeTrialBalance.details?.bus_company_balances || 0) }}</span>
                </div>
                <div class="flex justify-between items-center">
                  <span>فوري (أرصدة الماكينات):</span>
                  <span class="font-mono font-bold text-white">{{ formatCurrency(officeTrialBalance.details?.fawry_machine_balances || 0) }}</span>
                </div>
              </div>
            </div>

            <!-- 2. Total Liquidity (إجمالي السيولة) -->
            <div class="flight-panel p-6 border-t-4 border-t-sky-500 relative overflow-hidden h-full flex flex-col justify-between">
              <div>
                <span class="absolute top-2 left-2 text-[9px] font-bold text-sky-500 bg-sky-500/10 px-1.5 py-0.5 rounded">موجب (+)</span>
                <p class="text-xs font-bold text-text-muted uppercase tracking-wider">2. إجمالي السيولة</p>
                <p class="mt-3 font-mono text-2xl font-black text-text-main">{{ formatCurrency(officeTrialBalance.total_liquidity) }}</p>
              </div>
              <div class="mt-6 pt-4 border-t border-white/5 text-xs text-text-muted leading-relaxed">
                تجمع أرصدة البنوك، الخزن، والمحافظ الإلكترونية النشطة والمتاحة في موديولات قسم المكتب.
              </div>
            </div>

            <!-- 3. Due to Us (المستحق لنا) -->
            <div class="flight-panel p-6 border-t-4 border-t-emerald-500 relative overflow-hidden h-full flex flex-col justify-between">
              <div>
                <span class="absolute top-2 left-2 text-[9px] font-bold text-emerald-500 bg-emerald-500/10 px-1.5 py-0.5 rounded">موجب (+)</span>
                <p class="text-xs font-bold text-text-muted uppercase tracking-wider">3. المستحق لنا (العملاء)</p>
                <p class="mt-3 font-mono text-2xl font-black text-text-main">{{ formatCurrency(officeTrialBalance.due_to_us) }}</p>
              </div>
              <div class="mt-6 pt-4 border-t border-white/5 text-xs text-text-muted leading-relaxed">
                يشمل مديونيات عملاء المكتب والعهد الخارجية ومستحقات الموردين لقطاع المكتب.
              </div>
            </div>

          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            
            <!-- 4. Due from Us (المستحق علينا) -->
            <div class="flight-panel p-6 border-t-4 border-t-rose-500 relative overflow-hidden h-full flex flex-col justify-between">
              <div>
                <span class="absolute top-2 left-2 text-[9px] font-bold text-rose-500 bg-rose-500/10 px-1.5 py-0.5 rounded">يُطرح (-)</span>
                <p class="text-xs font-bold text-text-muted uppercase tracking-wider">4. المستحق علينا (الدائنون)</p>
                <p class="mt-3 font-mono text-2xl font-black text-rose-400">-{{ formatCurrency(officeTrialBalance.due_from_us) }}</p>
              </div>
              <div class="mt-6 pt-4 border-t border-white/5 text-xs text-text-muted leading-relaxed">
                يشمل التزامات المكتب لشركات الباص والموردين ودفعات عملاء المكتب المقدمة.
              </div>
            </div>

            <!-- 5. Current Capital (رأس المال الحالي) -->
            <div class="flight-panel p-6 bg-gradient-to-br from-slate-900 to-amber-950/40 border border-amber-500/30 relative overflow-hidden h-full flex flex-col justify-between">
              <div>
                <span class="absolute top-2 left-2 text-[9px] font-bold text-gold bg-gold/10 px-1.5 py-0.5 rounded">الفعلي (=)</span>
                <p class="text-xs font-bold text-gold uppercase tracking-wider">رأس المال الحالي للمكتب</p>
                <p class="mt-3 font-mono text-3xl font-black text-gold">{{ formatCurrency(officeTrialBalance.current_capital) }}</p>
              </div>
              <div class="mt-6 pt-4 border-t border-white/5 text-xs text-text-muted leading-relaxed">
                ناتج المعادلة: (أرصدة موديولات المكتب + السيولة + المستحق لنا) - المستحق علينا.
              </div>
            </div>

          </div>

          <!-- Matching verification vs Base Capital + Profits -->
          <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            
            <!-- Capital Settings & verification -->
            <div class="flight-panel lg:col-span-2 space-y-6">
              <h4 class="text-base font-bold text-white flex items-center gap-2">
                <Scale class="w-5 h-5 text-emerald-400" />
                مطابقة توازن رأس مال المكتب
              </h4>

              <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="p-4 bg-white/5 rounded-2xl border border-white/5">
                  <span class="text-xs text-text-muted">رأس المال الحالي للمكتب (الفعلي)</span>
                  <div class="mt-1.5 font-mono text-xl font-black text-white">{{ formatCurrency(officeTrialBalance.current_capital) }}</div>
                </div>
                <div class="p-4 bg-white/5 rounded-2xl border border-white/5">
                  <span class="text-xs text-text-muted">رأس المال المستهدف (الأساسي + الأرباح)</span>
                  <div class="mt-1.5 font-mono text-xl font-black text-white">{{ formatCurrency(officeTrialBalance.expected_capital) }}</div>
                </div>
              </div>

              <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 border-t border-white/5 pt-6">
                <div class="p-4 bg-white/5 rounded-2xl border border-white/5">
                  <span class="text-xs text-text-muted">رأس المال الأساسي للمكتب</span>
                  <div class="mt-1.5 font-mono text-lg font-bold text-white">{{ formatCurrency(officeTrialBalance.base_capital) }}</div>
                </div>
                <div class="p-4 bg-white/5 rounded-2xl border border-white/5">
                  <span class="text-xs text-text-muted">صافي أرباح المكتب التراكمية</span>
                  <div class="mt-1.5 font-mono text-lg font-bold text-emerald-400">{{ formatCurrency(officeTrialBalance.profits) }}</div>
                </div>
              </div>
            </div>

            <!-- Average Purchase Price card -->
            <div class="flight-panel space-y-6">
              <h4 class="text-base font-bold text-white flex items-center gap-2">
                <RefreshCw class="w-5 h-5 text-gold animate-spin-slow" />
                سعر شراء العملات الأجنبية
              </h4>
              <p class="text-xs text-text-muted leading-relaxed">
                يُحسب متوسط سعر الشراء آلياً وبلحظته بناءً على تكاليف حجز الطيران الفعلية بالعملة الأجنبية مقابل الجنيه المصري في موديول الطيران.
              </p>

              <div class="space-y-3">
                <div v-for="(rate, curr) in officeTrialBalance.rates || trialBalance?.rates || {}" :key="curr" class="p-3 bg-white/5 rounded-xl flex items-center justify-between">
                  <span class="text-xs font-bold text-white">{{ curr }} / EGP</span>
                  <span class="font-mono text-sm font-black text-gold">{{ rate.toFixed(4) }}</span>
                </div>
              </div>
            </div>

          </div>

        </div>
      </template>
    </div>

    <!-- Transfer Modal -->
    <teleport to="body">
      <div 
        v-if="showTransferModal" 
        class="fixed inset-0 z-[200] flex items-center justify-center bg-black/80 p-4 backdrop-blur-md animate-in fade-in duration-300"
        @click.self="closeTransferModal"
      >
        <div class="flight-panel w-full max-w-2xl !p-0 overflow-hidden shadow-2xl border border-white/10 animate-in zoom-in-95 duration-300">
          <!-- Modal Header -->
          <div class="px-8 py-6 bg-white/[0.03] border-b border-white/5 flex items-center justify-between">
            <div class="flex items-center gap-4">
              <div class="w-12 h-12 rounded-2xl bg-gold/10 flex items-center justify-center border border-gold/20 text-gold shadow-2xl shadow-gold/5">
                <ArrowRightLeft class="w-7 h-7" />
              </div>
              <div>
                <h3 class="font-display text-2xl font-black text-text-main">تحويل مالي بين الموديولات</h3>
                <p class="text-sm text-text-muted">نقل الأرصدة والسيولة بين الخزن والمحافظ بكل احترافية</p>
              </div>
            </div>
            <button @click="closeTransferModal" class="p-2 text-text-muted hover:text-text-main transition-colors">
              <X class="w-6 h-6" />
            </button>
          </div>

          <form @submit.prevent="executeTransfer" class="p-8 space-y-6">
            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
              <!-- Source -->
              <div class="space-y-2">
                <label class="text-xs font-bold text-text-muted uppercase tracking-widest block px-1">من حساب (المصدر)</label>
                <div class="relative group">
                  <select 
                    v-model="transferForm.from_account_id" 
                    required
                    class="flight-select w-full !pl-11 font-bold group-hover:border-gold/30 transition-all text-white bg-black"
                  >
                    <option value="" disabled>اختر حساب المصدر</option>
                    <optgroup v-for="(module, mKey) in safeModules" :key="'src-'+mKey" :label="module.label" class="text-gold bg-black">
                      <option v-for="acc in module.accounts" :key="'src-acc-'+acc.id" :value="acc.id" class="text-white bg-black">
                        {{ acc.name }} ({{ formatCurrency(acc.balance, acc.currency) }})
                      </option>
                    </optgroup>
                  </select>
                  <div class="absolute left-4 top-1/2 -translate-y-1/2 text-text-muted group-hover:text-gold transition-colors">
                    <Upload class="w-5 h-5" />
                  </div>
                </div>
              </div>

              <!-- Destination -->
              <div class="space-y-2">
                <label class="text-xs font-bold text-text-muted uppercase tracking-widest block px-1">إلى حساب (المستهدف)</label>
                <div class="relative group">
                  <select 
                    v-model="transferForm.to_account_id" 
                    required
                    class="flight-select w-full !pl-11 font-bold group-hover:border-emerald-500/30 transition-all text-white bg-black"
                  >
                    <option value="" disabled>اختر حساب المستهدف</option>
                    <optgroup v-for="(module, mKey) in safeModules" :key="'dst-'+mKey" :label="module.label" class="text-gold bg-black">
                      <option v-for="acc in module.accounts" :key="'dst-acc-'+acc.id" :value="acc.id" class="text-white bg-black">
                        {{ acc.name }} ({{ formatCurrency(acc.balance, acc.currency) }})
                      </option>
                    </optgroup>
                  </select>
                  <div class="absolute left-4 top-1/2 -translate-y-1/2 text-text-muted group-hover:text-emerald-500 transition-colors">
                    <Download class="w-5 h-5" />
                  </div>
                </div>
              </div>
            </div>

            <div class="space-y-2">
              <label class="text-xs font-bold text-text-muted uppercase tracking-widest block px-1">
                المبلغ المراد تحويله
                <span v-if="transferFromAccount" class="text-gold normal-case">({{ transferFromAccount.currency }})</span>
              </label>
              <div class="relative group">
                <input
                  v-model.number="transferForm.amount"
                  type="number"
                  step="0.01"
                  min="0.01"
                  required
                  placeholder="0.00"
                  class="flight-input w-full font-mono text-xl font-black group-hover:border-gold/30 transition-all"
                />
                <div class="absolute left-4 top-1/2 -translate-y-1/2 text-gold">
                  <Banknote class="w-6 h-6" />
                </div>
              </div>
            </div>

            <div
              v-if="transferFromAccount && transferToAccount && !currenciesMatch(transferFromAccount.currency, transferToAccount.currency)"
              class="space-y-2"
            >
              <label class="text-xs font-bold text-text-muted uppercase tracking-widest block px-1">
                سعر الصرف
                <span class="text-rose-400">*</span>
              </label>
              <div class="flex items-center gap-2">
                <span class="text-sm text-text-muted whitespace-nowrap">1 {{ transferFromAccount.currency }} =</span>
                <input
                  v-model.number="transferForm.exchange_rate"
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
                <span class="text-gold font-bold">{{ formatCurrency(transferConvertedAmount, transferToAccount.currency) }}</span>
              </p>
            </div>

            <div
              v-if="transferFromAccount && transferToAccount"
              class="rounded-2xl border border-white/10 bg-white/[0.03] p-4 space-y-2 text-sm"
            >
              <div class="flex justify-between gap-4">
                <span class="text-text-muted">من</span>
                <span class="font-bold text-text-main">{{ transferFromAccount.name }}</span>
              </div>
              <div class="flex justify-between gap-4">
                <span class="text-text-muted">إلى</span>
                <span class="font-bold text-text-main">{{ transferToAccount.name }}</span>
              </div>
              <div class="flex justify-between gap-4">
                <span class="text-text-muted">المبلغ المخصوم</span>
                <span class="font-bold text-gold">{{ formatCurrency(transferForm.amount, transferFromAccount.currency) }}</span>
              </div>
              <div
                v-if="!currenciesMatch(transferFromAccount.currency, transferToAccount.currency)"
                class="flex justify-between gap-4"
              >
                <span class="text-text-muted">المبلغ المضاف</span>
                <span class="font-bold text-emerald-400">{{ formatCurrency(transferConvertedAmount, transferToAccount.currency) }}</span>
              </div>
            </div>

            <p
              v-if="transferError"
              class="text-sm text-rose-400 bg-rose-500/10 border border-rose-500/20 rounded-xl px-4 py-3"
            >
              {{ transferError }}
            </p>

            <div class="space-y-2">
              <label class="text-xs font-bold text-text-muted uppercase tracking-widest block px-1">البيان / ملاحظات العملية</label>
              <textarea 
                v-model="transferForm.notes"
                rows="3" 
                class="flight-input w-full resize-none placeholder:text-text-muted/30"
                placeholder="اكتب تفاصيل عملية التحويل هنا لتسهيل عملية المراجعة لاحقاً..."
              ></textarea>
            </div>

            <div class="flex gap-4 pt-4">
              <button
                type="submit"
                :disabled="submitting || !canExecuteTransfer"
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
  </div>
</template>

<script setup>
import { ref, computed, onMounted, reactive, watch } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import axios from 'axios';
import { isRequestCanceled } from '@/utils/api';
import {
  buildTransferApiPayload,
  canExecuteCrossCurrencyTransfer,
  computeConvertedAmount,
  currenciesMatch,
  findTreasuryAccount,
} from '@/composables/useCrossCurrencyTransfer';
import {
  MODULE_GROUP_LABELS,
} from '@/composables/useTreasuryAccountGroups';
import { 
  RefreshCw, 
  ArrowRightLeft, 
  Banknote, 
  FileText,
  X,
  Upload,
  Download,
  Send,
  Globe,
  Ticket,
  IdCard,
  Monitor,
  Building2,
  ListTodo,
  History,
  ArrowRight,
  ChevronLeft,
  ChevronDown,
  PieChart,
  Briefcase,
  Landmark,
  Scale,
  Printer
} from 'lucide-vue-next';

const router = useRouter();
const route = useRoute();
const loading = ref(false);
const submitting = ref(false);

// State
const overview = ref({});
const recentTransfers = ref([]);
const trialBalance = ref(null);
const officeTrialBalance = ref(null);
const baseCapitalInput = ref(1000000.0);
const statsByCategory = ref({
  office: {
    total_liquidity: 0,
    total_banks: 0,
    total_cashbox: 0,
    total_wallets: 0,
    total_post: 0,
    total_treasury: 0,
    accounts_count: 0,
  },
  tourism: {
    total_liquidity: 0,
    total_banks: 0,
    total_cashbox: 0,
    total_wallets: 0,
    total_post: 0,
    total_treasury: 0,
    accounts_count: 0,
  },
});
const unifiedByCategory = ref({ office: [], tourism: [] });
const showTransferModal = ref(false);
const transferError = ref('');
const selectedCategory = ref('office');
const selectedTypeTab = ref('all');
const expandedUnifiedKeys = reactive(new Set());

const typeTabs = [
  { id: 'all', label: 'الكل' },
  { id: 'bank', label: 'البنوك' },
  { id: 'cashbox', label: 'النقدي' },
  { id: 'wallet', label: 'الكاش' },
  { id: 'post', label: 'البريد' },
  { id: 'treasury', label: 'خزائن عامة' },
];

const categories = [
  { id: 'trial_balance', label: 'ميزان حسابات قسم السياحة (جرد لحظي)', icon: Landmark },
  { id: 'office_trial_balance', label: 'ميزان حسابات قسم المكتب (جرد لحظي)', icon: Landmark },
  { id: 'office', label: 'المكتب العام', icon: Building2 },
  { id: 'tourism', label: 'السياحة والطيران', icon: Briefcase },
];

/** موديولات كل قسم — تُعرض في الشرح والكروت */
const CATEGORY_MODULE_KEYS = {
  tourism: ['flights', 'hajj_umra', 'visas', 'tourism'],
  office: ['bus', 'fawry', 'online', 'wallet_transfer', 'general'],
};

const transferForm = ref({
  from_account_id: '',
  to_account_id: '',
  amount: null,
  exchange_rate: 1,
  notes: '',
});

// Safe modules for template
const safeModules = computed(() => {
  const res = {};
  const raw = overview.value;
  if (raw && typeof raw === 'object') {
    Object.keys(raw).forEach(key => {
      const mod = raw[key];
      if (mod && typeof mod === 'object') {
        res[key] = {
          label: mod.label || key,
          category: mod.category || 'office',
          accounts: Array.isArray(mod.accounts) ? mod.accounts : []
        };
      }
    });
  }
  return res;
});

const treasuryAccountsFlat = computed(() => {
  const list = [];
  Object.values(safeModules.value).forEach((mod) => {
    (mod.accounts || []).forEach((acc) => list.push(acc));
  });
  categoryUnifiedAccounts.value.forEach((group) => {
    (group.modules || []).forEach((mod) => {
      (mod.accounts || []).forEach((acc) => list.push(acc));
    });
  });
  const byId = new Map();
  list.forEach((acc) => {
    if (acc?.id) byId.set(acc.id, acc);
  });
  return [...byId.values()];
});

const displayStats = computed(() => {
  const cat = statsByCategory.value[selectedCategory.value];
  return cat || statsByCategory.value.office;
});

const categoryModuleChips = computed(() => {
  const keys = CATEGORY_MODULE_KEYS[selectedCategory.value] || [];
  const modules = safeModules.value;

  return keys.map((key) => {
    const mod = modules[key];
    const accounts = mod?.category === selectedCategory.value ? (mod.accounts || []) : [];
    const balance = accounts.reduce((sum, acc) => sum + (Number(acc.balance_egp) || 0), 0);

    return {
      key,
      label: MODULE_GROUP_LABELS[key] || mod?.label || key,
      active: accounts.length > 0,
      balance,
      count: accounts.length,
    };
  });
});

const categoryModulesListText = computed(() => {
  const active = categoryModuleChips.value.filter((c) => c.active).map((c) => c.label);
  if (active.length) return active.join(' + ');
  return categoryModuleChips.value.map((c) => c.label).join(' + ');
});

const otherCategoryId = computed(() => (selectedCategory.value === 'office' ? 'tourism' : 'office'));

const otherCategoryLabel = computed(() =>
  categories.find((c) => c.id === otherCategoryId.value)?.label || ''
);

const otherCategoryHasAccounts = computed(() =>
  Number(statsByCategory.value[otherCategoryId.value]?.accounts_count || 0) > 0
);

const statsScopeLabel = computed(() => {
  const label = categories.find((c) => c.id === selectedCategory.value)?.label || '';
  return `الأرقام أعلاه لقسم «${label}» فقط — وتشمل موديولات: ${categoryModulesListText.value}`;
});

const unifiedHintText = computed(() => {
  const section = currentCategoryLabel.value;
  const modules = categoryModulesListText.value;
  if (selectedCategory.value === 'tourism') {
    return `الإجمالي الموحّد داخل «${section}» يجمع نفس البنك/الخزنة/المحفظة عبر: ${modules}. مثال: بنك مصر (طيران) + بنك مصر (حج) + بنك مصر (تأشيرات) = رصيد واحد`;
  }
  return `الإجمالي الموحّد داخل «${section}» يجمع نفس البنك/الخزنة/المحفظة عبر: ${modules}. مثال: بنك مصر (باص) + بنك مصر (فوري) + بنك مصر (أونلاين) = رصيد واحد`;
});

const categoryUnifiedAccounts = computed(() => {
  const bucket = unifiedByCategory.value[selectedCategory.value];
  return Array.isArray(bucket) ? bucket : [];
});

const filteredUnifiedAccounts = computed(() => {
  const list = categoryUnifiedAccounts.value;
  if (selectedTypeTab.value === 'all') return list;
  return list.filter((g) => g.type === selectedTypeTab.value);
});

const transferFromAccount = computed(() =>
  findTreasuryAccount(treasuryAccountsFlat.value, transferForm.value.from_account_id)
);

const transferToAccount = computed(() =>
  findTreasuryAccount(treasuryAccountsFlat.value, transferForm.value.to_account_id)
);

const transferConvertedAmount = computed(() => {
  if (!transferFromAccount.value || !transferToAccount.value) return 0;
  return computeConvertedAmount(
    transferForm.value.amount,
    transferForm.value.exchange_rate,
    transferFromAccount.value.currency,
    transferToAccount.value.currency
  );
});

const canExecuteTransfer = computed(() =>
  canExecuteCrossCurrencyTransfer({
    fromAccountId: transferForm.value.from_account_id,
    toAccountId: transferForm.value.to_account_id,
    fromAccount: transferFromAccount.value,
    toAccount: transferToAccount.value,
    amount: transferForm.value.amount,
    exchangeRate: transferForm.value.exchange_rate,
  })
);

const currentCategoryLabel = computed(() => {
  return categories.find(c => c.id === selectedCategory.value)?.label || '';
});

const filteredModules = computed(() => {
  const res = {};
  const modules = safeModules.value;
  Object.keys(modules).forEach((key) => {
    const mod = modules[key];
    const cat = mod.category === 'tourism' || mod.category === 'office'
      ? mod.category
      : (mod.category === 'flights' ? 'tourism' : 'office');
    if (cat === selectedCategory.value) {
      res[key] = mod;
    }
  });
  return res;
});

function categoryHasAccounts(categoryId) {
  return Number(statsByCategory.value[categoryId]?.accounts_count || 0) > 0
    || Object.values(safeModules.value).some(
      (mod) => mod.category === categoryId && (mod.accounts?.length || 0) > 0
    );
}

function autoSelectCategoryWithData() {
  if (['trial_balance', 'office_trial_balance'].includes(selectedCategory.value)) return;
  if (categoryHasAccounts(selectedCategory.value)) return;
  if (categoryHasAccounts('tourism')) {
    selectedCategory.value = 'tourism';
    return;
  }
  if (categoryHasAccounts('office')) {
    selectedCategory.value = 'office';
  }
}

const categoryLiquidityTotal = computed(() => {
  return Number(displayStats.value.total_liquidity) || 1;
});

function normalizeCategoryStats(raw) {
  const src = raw && typeof raw === 'object' ? raw : {};
  return {
    total_liquidity: Number(src.total_liquidity) || 0,
    total_banks: Number(src.total_banks) || 0,
    total_cashbox: Number(src.total_cashbox) || 0,
    total_wallets: Number(src.total_wallets) || 0,
    total_post: Number(src.total_post) || 0,
    total_treasury: Number(src.total_treasury) || 0,
    accounts_count: Number(src.accounts_count) || 0,
  };
}

function unifiedTypeCount(typeId) {
  const list = categoryUnifiedAccounts.value;
  if (typeId === 'all') return list.length;
  return list.filter((g) => g.type === typeId).length;
}

function toggleUnifiedGroup(key) {
  if (expandedUnifiedKeys.has(key)) {
    expandedUnifiedKeys.delete(key);
  } else {
    expandedUnifiedKeys.add(key);
  }
}

async function fetchOverview() {
  loading.value = true;
  try {
    const response = await axios.get('/api/v1/finance/treasuries/get-overview', {
      params: { _t: Date.now() },
    });
    const data = response.data?.data || {};
    overview.value = data.modules && typeof data.modules === 'object' ? data.modules : {};
    const unified = data.unified_by_category && typeof data.unified_by_category === 'object'
      ? data.unified_by_category
      : {};
    unifiedByCategory.value = {
      office: Array.isArray(unified.office) ? unified.office : [],
      tourism: Array.isArray(unified.tourism) ? unified.tourism : [],
    };
    recentTransfers.value = Array.isArray(data.recent_transfers) ? data.recent_transfers : [];
    const s = data.stats && typeof data.stats === 'object' ? data.stats : {};
    const byCat = s.by_category && typeof s.by_category === 'object' ? s.by_category : {};
    statsByCategory.value = {
      office: normalizeCategoryStats(byCat.office),
      tourism: normalizeCategoryStats(byCat.tourism),
    };

    if (data.trial_balance) {
      trialBalance.value = data.trial_balance;
      baseCapitalInput.value = data.trial_balance.base_capital;
    }
    if (data.office_trial_balance) {
      officeTrialBalance.value = data.office_trial_balance;
    }

    autoSelectCategoryWithData();
  } catch (err) {
    console.error('Failed to fetch treasury overview:', err);
    if (window.addToast) window.addToast('فشل في تحميل بيانات الخزينة', 'error');
  } finally {
    loading.value = false;
  }
}

async function updateBaseCapital() {
  submitting.value = true;
  try {
    const response = await axios.put('/api/v1/settings/print', {
      base_capital: baseCapitalInput.value,
    });
    if (window.addToast) {
      window.addToast('تم تحديث رأس المال الأساسي بنجاح', 'success');
    }
    await fetchOverview();
  } catch (err) {
    console.error('Failed to update base capital:', err);
    if (window.addToast) {
      window.addToast('فشل في تحديث رأس المال الأساسي', 'error');
    }
  } finally {
    submitting.value = false;
  }
}

const printTrialBalance = () => {
  window.print();
};

async function exportTrialBalanceExcel(division = 'tourism') {
  try {
    const response = await axios.get('/api/v1/finance/treasuries/export-trial-balance', {
      params: { division },
      responseType: 'blob',
    });
    const url = window.URL.createObjectURL(new Blob([response.data]));
    const link = document.createElement('a');
    link.href = url;
    link.setAttribute('download', division === 'office' ? 'ميزان_المكتب.xlsx' : 'ميزان_السياحة.xlsx');
    document.body.appendChild(link);
    link.click();
    link.remove();
    if (window.addToast) {
      window.addToast('تم تحميل كشف ميزان الحسابات بنجاح', 'success');
    }
  } catch (err) {
    if (isRequestCanceled(err)) return;
    console.error('Failed to export trial balance:', err);
    if (window.addToast) {
      window.addToast('فشل في تحميل كشف ميزان الحسابات', 'error');
    }
  }
}

function statusBorderColor(status) {
  if (!status) return 'border-white/10';
  if (status === 'متساوية') return 'border-emerald-500 bg-emerald-500/5';
  if (status === 'يوجد زيادة') return 'border-sky-500 bg-sky-500/5';
  return 'border-rose-500 bg-rose-500/5'; // يوجد عجز
}

function statusBadgeClass(status) {
  if (!status) return 'bg-white/5 text-text-muted border-white/10';
  if (status === 'متساوية') return 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20';
  if (status === 'يوجد زيادة') return 'bg-sky-500/10 text-sky-400 border-sky-500/20';
  return 'bg-rose-500/10 text-rose-400 border-rose-500/20'; // يوجد عجز
}

function statusDotColor(status) {
  if (!status) return 'bg-text-muted';
  if (status === 'متساوية') return 'bg-emerald-400';
  if (status === 'يوجد زيادة') return 'bg-sky-400';
  return 'bg-rose-400';
}

function getModuleTotal(accounts) {
  if (!Array.isArray(accounts)) return 0;
  return accounts.reduce((sum, acc) => sum + (Number(acc.balance_egp) || 0), 0);
}

function getModulePercentage(accounts) {
  const total = categoryLiquidityTotal.value || 1;
  const moduleTotal = getModuleTotal(accounts);
  return Math.round((moduleTotal / total) * 100);
}

function getModuleIcon(key) {
  const icons = {
    flights: Send,
    flight: Send,
    bus: Ticket,
    visa: IdCard,
    visas: IdCard,
    hajj_umra: Globe,
    online: Monitor,
    general: Building2,
    office: Building2,
    other: ListTodo,
    fawry: RefreshCw,
    wallet: Banknote,
    wallet_transfer: Banknote,
  };
  return icons[key] || Building2;
}

function getTypeColor(type) {
  const colors = {
    cashbox: 'bg-gold shadow-[0_0_8px_rgba(234,179,8,0.4)]',
    bank: 'bg-sky-500 shadow-[0_0_8px_rgba(14,165,233,0.4)]',
    wallet: 'bg-purple-500 shadow-[0_0_8px_rgba(168,85,247,0.4)]',
    post: 'bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.4)]',
    treasury: 'bg-amber-600 shadow-[0_0_8px_rgba(217,119,6,0.4)]',
  };
  return colors[type] || 'bg-white/20';
}

function openTransferModal() {
  showTransferModal.value = true;
}

function closeTransferModal() {
  showTransferModal.value = false;
  transferError.value = '';
  transferForm.value = {
    from_account_id: '',
    to_account_id: '',
    amount: null,
    exchange_rate: 1,
    notes: '',
  };
}

function quickTransfer(acc) {
  if (!acc) return;
  transferForm.value.from_account_id = acc.id;
  showTransferModal.value = true;
}

function viewStatement(id) {
  if (!id) return;
  router.push({ name: 'finance.accounts.statement.detail', params: { id } });
}

async function executeTransfer() {
  transferError.value = '';

  if (!canExecuteTransfer.value) {
    transferError.value = 'تحقق من الحسابات والمبلغ وسعر الصرف والرصيد المتاح';
    return;
  }

  submitting.value = true;
  try {
    const payload = buildTransferApiPayload({
      from_account_id: transferForm.value.from_account_id,
      to_account_id: transferForm.value.to_account_id,
      amount: transferForm.value.amount,
      notes: transferForm.value.notes,
      exchange_rate: transferForm.value.exchange_rate,
      fromAccount: transferFromAccount.value,
      toAccount: transferToAccount.value,
    });

    await axios.post('/api/v1/finance/transfers', payload);

    if (window.addToast) window.addToast('تم تنفيذ التحويل المالي بنجاح', 'success');
    closeTransferModal();
    await fetchOverview();
  } catch (err) {
    console.error('Transfer failed:', err);
    const msg = err.response?.data?.message || 'فشل في تنفيذ عملية التحويل';
    transferError.value = msg;
    if (window.addToast) window.addToast(msg, 'error');
  } finally {
    submitting.value = false;
  }
}

function formatCurrency(amount, currency = 'EGP') {
  return new Intl.NumberFormat('ar-EG', {
    style: 'currency',
    currency: currency,
    minimumFractionDigits: 0,
    maximumFractionDigits: 2
  }).format(Number(amount) || 0);
}

watch(selectedCategory, () => {
  expandedUnifiedKeys.clear();
  selectedTypeTab.value = 'all';
});

onMounted(() => {
  const queryCat = route.query.category || route.query.tab;
  if (queryCat && ['office', 'tourism', 'trial_balance', 'office_trial_balance'].includes(queryCat)) {
    selectedCategory.value = queryCat;
  }
  fetchOverview();
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
.btn-airline:active {
  transform: scale(0.98);
}
.btn-airline:disabled {
  filter: grayscale(1);
  cursor: not-allowed;
}

.btn-airline-ghost {
  background-color: rgba(255, 255, 255, 0.05);
  border: 1px solid rgba(255, 255, 255, 0.1);
  color: #f9fafb;
  transition: all 0.3s ease;
}
.btn-airline-ghost:hover {
  background-color: rgba(255, 255, 255, 0.1);
}

/* Custom Scrollbar */
::-webkit-scrollbar {
  width: 6px;
}
::-webkit-scrollbar-track {
  background: transparent;
}
::-webkit-scrollbar-thumb {
  background: rgba(255, 255, 255, 0.1);
  border-radius: 9999px;
}
::-webkit-scrollbar-thumb:hover {
  background: rgba(255, 255, 255, 0.2);
}
</style>

<style>
@media print {
  body, html, #app, .app-shell, .main-zone, .page-body {
    background: #ffffff !important;
    background-color: #ffffff !important;
    color: #000000 !important;
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

  .sidebar, .top-bar, .toast-rack, .backdrop, .no-print, .print-hidden {
    display: none !important;
  }

  * {
    print-color-adjust: exact !important;
    -webkit-print-color-adjust: exact !important;
    color-adjust: exact !important;
  }

  .flight-panel, 
  .bg-card-bg {
    background: #ffffff !important;
    background-color: #ffffff !important;
    border: 1px solid #e2e8f0 !important;
    color: #000000 !important;
    box-shadow: none !important;
    border-radius: 12px !important;
  }
  
  .flight-panel *, 
  .bg-card-bg * {
    color: #000000 !important;
  }

  .text-emerald-400 {
    color: #166534 !important;
  }
  .text-rose-400 {
    color: #991b1b !important;
  }
  .text-sky-400 {
    color: #1e3a8a !important;
  }
  .text-gold {
    color: #b45309 !important;
  }
}
</style>
