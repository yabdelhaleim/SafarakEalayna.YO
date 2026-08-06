<template>
  <div class="wallet-create-page mx-auto max-w-7xl space-y-6 pb-20" dir="rtl">

    <!-- ════════════════════════════════════════
         HEADER (Filament-style)
    ════════════════════════════════════════ -->
    <header class="relative overflow-hidden rounded-3xl border border-amber-500/20 bg-gradient-to-br from-[#1a1200] via-[#1c1500] to-[#0d0d0d] p-6 sm:p-8 shadow-2xl">
      <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,_rgba(245,158,11,0.14),_transparent_60%)]" />
      <div class="relative z-10 flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-4">
          <router-link
            to="/wallet"
            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-white/15 bg-white/5 text-amber-300 transition hover:border-amber-400/40 hover:bg-amber-400/10"
            title="العودة لقائمة المحافظ"
          >
            <ArrowRight class="h-5 w-5" />
          </router-link>
          <div>
            <p class="text-[11px] font-bold uppercase tracking-widest text-amber-400/80">
              المحافظ والتحويلات
            </p>
            <h1 class="mt-0.5 text-2xl font-black text-white">عملية محفظة جديدة</h1>
            <p class="mt-1 text-sm text-white/50">
              تسجيل عملية إرسال أو استقبال رصيد — اختر النوع والمحفظة، أدخل البيانات، واحفظ.
            </p>
          </div>
        </div>

        <!-- Compact progress -->
        <div class="flex items-center gap-3 self-start sm:self-auto">
          <div class="text-center">
            <div class="text-xs text-white/40">التقدم</div>
            <div class="text-lg font-black text-amber-400">
              {{ completedSteps }}<span class="text-sm text-white/30">/{{ totalSteps }}</span>
            </div>
          </div>
          <div class="flex h-12 w-12 items-center justify-center rounded-full border-2 border-amber-400/40 text-sm font-black text-amber-300">
            {{ Math.round((completedSteps / totalSteps) * 100) }}%
          </div>
        </div>
      </div>
    </header>

    <form @submit.prevent="submit" class="grid grid-cols-1 gap-6 lg:grid-cols-3">
      <!-- ════════════════════════════════════════
           MAIN FORM (2 cols)
      ════════════════════════════════════════ -->
      <div class="space-y-6 lg:col-span-2">

        <!-- STEP 1: Operation Type -->
        <section class="rounded-2xl border border-white/10 bg-[#111111] p-6">
          <div class="mb-5 flex items-center gap-3">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-500/15 text-amber-400">
              <ArrowLeftRight class="h-5 w-5" />
            </div>
            <div class="flex-1">
              <h2 class="text-base font-bold text-white">1. نوع العملية</h2>
              <p class="text-xs text-white/40">اختر اتجاه الرصيد</p>
            </div>
            <span v-if="form.type" class="text-xs font-bold text-emerald-400 flex items-center gap-1">
              <Check class="h-3.5 w-3.5" /> تم الاختيار
            </span>
          </div>

          <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <button
              type="button"
              @click="form.type = 'send'"
              :class="[
                'group relative flex items-start gap-3 rounded-xl border-2 p-4 text-right transition-all',
                form.type === 'send'
                  ? 'border-amber-500 bg-amber-500/10 shadow-lg shadow-amber-500/10'
                  : 'border-white/10 bg-white/[0.02] hover:border-amber-500/40 hover:bg-amber-500/5',
              ]"
            >
              <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-amber-500/15 text-amber-400">
                <ArrowUpCircle class="h-6 w-6" />
              </div>
              <div class="flex-1">
                <div class="flex items-center justify-between">
                  <span class="font-bold text-white">إرسال رصيد</span>
                  <CheckCircle2 v-if="form.type === 'send'" class="h-5 w-5 text-amber-400" />
                </div>
                <p class="mt-1 text-xs text-white/50 leading-relaxed">
                  نرسل رصيد على محفظة العميل ويدفع لنا نقدي + الخدمة
                </p>
              </div>
            </button>

            <button
              type="button"
              @click="form.type = 'receive'"
              :class="[
                'group relative flex items-start gap-3 rounded-xl border-2 p-4 text-right transition-all',
                form.type === 'receive'
                  ? 'border-emerald-500 bg-emerald-500/10 shadow-lg shadow-emerald-500/10'
                  : 'border-white/10 bg-white/[0.02] hover:border-emerald-500/40 hover:bg-emerald-500/5',
              ]"
            >
              <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-emerald-500/15 text-emerald-400">
                <ArrowDownCircle class="h-6 w-6" />
              </div>
              <div class="flex-1">
                <div class="flex items-center justify-between">
                  <span class="font-bold text-white">استقبال رصيد</span>
                  <CheckCircle2 v-if="form.type === 'receive'" class="h-5 w-5 text-emerald-400" />
                </div>
                <p class="mt-1 text-xs text-white/50 leading-relaxed">
                  العميل يرسل رصيد لمحفظتنا ونعطيه نقدي ناقص الخدمة
                </p>
              </div>
            </button>
          </div>
        </section>

        <!-- STEP 2: Wallet Type + Matching Wallets -->
        <section class="rounded-2xl border border-white/10 bg-[#111111] p-6">
          <div class="mb-5 flex items-center gap-3">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-sky-500/15 text-sky-400">
              <Wallet class="h-5 w-5" />
            </div>
            <div class="flex-1">
              <h2 class="text-base font-bold text-white">2. نوع المحفظة والحساب</h2>
              <p class="text-xs text-white/40">اختر النوع — ستظهر المحافظ المتاحة من هذا النوع تلقائياً</p>
            </div>
            <!-- اختصار لإدارة أنواع المحافظ (يفتح Filament في تبويب جديد) -->
            <a
              href="/admin/wallet-types"
              target="_blank"
              rel="noopener noreferrer"
              class="inline-flex items-center gap-1.5 rounded-lg border border-sky-500/30 bg-sky-500/10 px-3 py-1.5 text-xs font-bold text-sky-300 transition hover:border-sky-500/50 hover:bg-sky-500/20"
              title="افتح إدارة أنواع المحافظ في تبويب جديد"
            >
              <Settings2 class="h-3.5 w-3.5" />
              إدارة الأنواع
              <ExternalLink class="h-3 w-3 opacity-60" />
            </a>
            <span v-if="selectedWalletType" class="text-xs font-bold text-emerald-400 flex items-center gap-1">
              <Check class="h-3.5 w-3.5" /> {{ selectedWalletType.name }}
            </span>
          </div>

          <!-- Wallet Type chips -->
          <div v-if="activeWalletTypes.length === 0" class="rounded-xl border border-amber-400/30 bg-amber-400/5 p-4 text-sm text-amber-300 space-y-2 leading-relaxed">
            <p class="font-bold">⚠️ لا توجد أنواع محافظ مفعّلة في النظام.</p>
            <p class="text-white/50">
              لإضافة نوع محفظة جديد (مثل فودافون كاش، إنستاباي…)، اضغط الزر:
              <a
                href="/admin/wallet-types"
                target="_blank"
                class="inline-flex items-center gap-1 mx-1 rounded-md border border-amber-400/40 bg-amber-400/10 px-2 py-0.5 font-bold text-amber-200 hover:bg-amber-400/20"
              >
                <Settings2 class="h-3 w-3" />
                إدارة الأنواع
              </a>
              ، ثم أضِف النوع وفعّل خيار «نشط».
            </p>
          </div>
        <div v-else class="flex flex-wrap gap-2">
          <button
            v-for="wt in activeWalletTypes"
            :key="wt.id"
            type="button"
            :disabled="!wt.is_active"
            :title="!wt.is_active ? 'هذا النوع معطّل — فعّله من إدارة أنواع المحافظ' : null"
            @click="wt.is_active && selectWalletType(wt.id)"
            :class="[
              'group inline-flex items-center gap-2 rounded-xl border px-4 py-2.5 text-sm font-bold transition-all',
              !wt.is_active
                ? 'cursor-not-allowed border-white/5 bg-white/[0.02] text-white/30 opacity-50'
                : form.wallet_type_id === wt.id
                  ? 'border-amber-500 bg-amber-500/15 text-amber-300 shadow-md shadow-amber-500/10'
                  : 'border-white/10 bg-white/5 text-white/80 hover:border-amber-500/40 hover:bg-amber-500/5',
            ]"
          >
            <component :is="providerIcon(wt.code)" class="h-4 w-4" />
            <span>{{ wt.name }}</span>
            <span
              v-if="!wt.is_active"
              class="rounded-full bg-white/5 px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wider text-white/40"
            >
              معطّل
            </span>
            <code
              v-else-if="form.wallet_type_id === wt.id"
              class="rounded bg-black/30 px-1.5 py-0.5 font-mono text-[10px] text-amber-200/70"
            >{{ wt.code }}</code>
          </button>
        </div>
          <p v-if="errors.wallet_type_id" class="mt-3 text-xs text-rose-400">{{ errors.wallet_type_id }}</p>

          <!-- Matching Wallet Accounts -->
          <div v-if="form.wallet_type_id" class="mt-6 border-t border-white/5 pt-6">
            <div class="mb-3 flex items-center justify-between">
              <label class="text-xs font-bold text-white/60 uppercase tracking-wider">
                المحافظ المتاحة من نوع «{{ selectedWalletType?.name }}»
                <span class="text-rose-400">*</span>
              </label>
              <span class="text-[10px] text-white/40 font-mono">
                {{ visibleWalletAccounts.length }} محفظة
              </span>
            </div>

            <!-- ── فلتر تشغيلي: الكل / رسمية / قسم المكتب ── -->
            <div class="mb-4 flex flex-wrap items-center gap-2">
              <button
                v-for="opt in [
                  { key: 'all', label: 'الكل', count: groupedWalletAccounts.official.length + groupedWalletAccounts.officeWide.length, color: 'sky' },
                  { key: 'official', label: 'الرسمية للموديول', count: groupedWalletAccounts.official.length, color: 'amber' },
                  { key: 'office', label: 'قسم المكتب', count: groupedWalletAccounts.officeWide.length, color: 'emerald' },
                ]"
                :key="opt.key"
                type="button"
                @click="walletScopeFilter = opt.key"
                :class="[
                  'inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-bold transition',
                  walletScopeFilter === opt.key
                    ? opt.color === 'amber'
                      ? 'border-amber-500 bg-amber-500/15 text-amber-300'
                      : opt.color === 'emerald'
                        ? 'border-emerald-500 bg-emerald-500/15 text-emerald-300'
                        : 'border-sky-500 bg-sky-500/15 text-sky-300'
                    : 'border-white/10 bg-white/5 text-white/60 hover:border-white/20 hover:text-white',
                ]"
              >
                <span>{{ opt.label }}</span>
                <span class="rounded-full bg-white/10 px-1.5 py-0.5 font-mono text-[10px]">
                  {{ opt.count }}
                </span>
              </button>
            </div>

            <!-- Empty state — no wallets match -->
            <div
              v-if="visibleWalletAccounts.length === 0"
              class="rounded-xl border border-amber-400/30 bg-amber-400/5 p-4 text-sm text-amber-300 space-y-2 leading-relaxed"
            >
              <p class="font-bold flex items-center gap-2">
                <AlertTriangle class="h-4 w-4" />
                <span v-if="walletScopeFilter === 'official'">
                  لا توجد محافظ رسمية للموديول من نوع «{{ selectedWalletType?.name }}».
                </span>
                <span v-else-if="walletScopeFilter === 'office'">
                  لا توجد محافظ لقسم المكتب من نوع «{{ selectedWalletType?.name }}».
                </span>
                <span v-else>
                  لا توجد محافظ من نوع «{{ selectedWalletType?.name }}» مسجلة في النظام.
                </span>
              </p>
              <p v-if="walletAccounts.length > 0" class="text-white/60">
                يوجد {{ walletAccounts.length }} محفظة مسجلة فعلاً، لكن نوعها (<strong>{{ unmatchedWalletProviders.join('، ') }}</strong>) لا يطابق النوع المختار
                (<code class="font-mono text-amber-200">{{ selectedWalletType?.code }}</code>).
              </p>
              <p v-else class="text-white/60">
                يمكنك إضافة محفظة جديدة من صفحة
                <router-link to="/finance/accounts" class="text-amber-300 underline hover:text-amber-200 font-bold">إدارة الحسابات والخزائن</router-link>.
                اختر نوع الحساب «محفظة»، ثم حدّد نوع مقدم الخدمة (<strong>{{ selectedWalletType?.code }}</strong>) والرقم.
              </p>
              <!-- اختصار لإضافة محفظة قسم مكتب جديدة -->
              <p class="text-white/60 pt-2 border-t border-white/10">
                <a
                  href="/admin/accounts/create?type=wallet&module_type=office&wallet_provider={{ selectedWalletType?.code }}"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="inline-flex items-center gap-1 rounded-md border border-emerald-500/40 bg-emerald-500/10 px-2 py-1 font-bold text-emerald-300 hover:bg-emerald-500/20"
                >
                  + إضافة محفظة «قسم مكتب» جديدة
                </a>
                <span class="text-white/40 text-xs mr-1">— ستفتح في تبويب جديد</span>
              </p>
            </div>

            <!-- ── Wallet Cards (مرتبة بمجموعات إذا كان الفلتر = 'all') ── -->
            <template v-else>
              <!-- مجموعة المحفظة الرسمية (تظهر فقط لو الفلتر = all أو official والمجموعة مش فاضية) -->
              <template v-if="walletScopeFilter === 'all' || walletScopeFilter === 'official'">
                <div v-if="groupedWalletAccounts.official.length > 0" class="mb-5">
                  <div class="mb-2 flex items-center gap-2">
                    <span class="inline-flex h-5 w-5 items-center justify-center rounded-md bg-amber-500/20 text-amber-300">
                      <ShieldCheck class="h-3 w-3" />
                    </span>
                    <h4 class="text-xs font-bold text-amber-300 uppercase tracking-wider">
                      المحافظ الرسمية للموديول
                    </h4>
                    <span class="text-[10px] font-mono text-white/40">
                      ({{ groupedWalletAccounts.official.length }})
                    </span>
                    <span class="text-[10px] text-white/40">
                      — مخصصة لموديول المحافظ والتحويلات
                    </span>
                  </div>
                  <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <button
                      v-for="acc in groupedWalletAccounts.official"
                      :key="acc.id"
                      type="button"
                      @click="form.wallet_account_id = acc.id"
                      :class="[
                        'group relative flex flex-col gap-2 rounded-xl border-2 p-4 text-right transition-all',
                        form.wallet_account_id === acc.id
                          ? 'border-amber-500 bg-amber-500/10 shadow-lg shadow-amber-500/10'
                          : 'border-white/10 bg-white/[0.02] hover:border-amber-500/40 hover:bg-amber-500/5',
                      ]"
                    >
                      <div class="flex items-start justify-between gap-2">
                        <div class="flex items-center gap-2 min-w-0 flex-1">
                          <component :is="providerIcon(selectedWalletType?.code)" class="h-5 w-5 shrink-0 text-amber-400" />
                          <span class="font-bold text-white truncate">{{ acc.name }}</span>
                        </div>
                        <CheckCircle2
                          v-if="form.wallet_account_id === acc.id"
                          class="h-5 w-5 shrink-0 text-amber-400"
                        />
                      </div>
                      <div class="flex items-center justify-between gap-2 text-xs">
                        <span class="font-mono text-white/50 truncate">
                          {{ acc.wallet_number || '—' }}
                        </span>
                        <span
                          v-if="acc.is_module_vault"
                          class="shrink-0 rounded-full bg-amber-500/15 px-2 py-0.5 text-[10px] font-bold text-amber-300"
                        >
                          خزنة رسمية
                        </span>
                      </div>
                      <div class="flex items-center justify-between gap-2 border-t border-white/5 pt-2">
                        <span class="text-[10px] uppercase tracking-wider text-white/40">الرصيد</span>
                        <span class="font-mono text-base font-black text-emerald-400">
                          {{ formatCurrency(acc.balance) }}
                        </span>
                      </div>
                    </button>
                  </div>
                </div>
              </template>

              <!-- مجموعة محفظة قسم المكتب العامة -->
              <template v-if="walletScopeFilter === 'all' || walletScopeFilter === 'office'">
                <div v-if="groupedWalletAccounts.officeWide.length > 0">
                  <div class="mb-2 flex items-center gap-2">
                    <span class="inline-flex h-5 w-5 items-center justify-center rounded-md bg-emerald-500/20 text-emerald-300">
                      <Building2 class="h-3 w-3" />
                    </span>
                    <h4 class="text-xs font-bold text-emerald-300 uppercase tracking-wider">
                      محافظ قسم المكتب
                    </h4>
                    <span class="text-[10px] font-mono text-white/40">
                      ({{ groupedWalletAccounts.officeWide.length }})
                    </span>
                    <span class="text-[10px] text-white/40">
                      — محافظ مشتركة لكل الموديولات في قسم المكتب
                    </span>
                  </div>
                  <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <button
                      v-for="acc in groupedWalletAccounts.officeWide"
                      :key="acc.id"
                      type="button"
                      @click="form.wallet_account_id = acc.id"
                      :class="[
                        'group relative flex flex-col gap-2 rounded-xl border-2 p-4 text-right transition-all',
                        form.wallet_account_id === acc.id
                          ? 'border-emerald-500 bg-emerald-500/10 shadow-lg shadow-emerald-500/10'
                          : 'border-white/10 bg-white/[0.02] hover:border-emerald-500/40 hover:bg-emerald-500/5',
                      ]"
                    >
                      <div class="flex items-start justify-between gap-2">
                        <div class="flex items-center gap-2 min-w-0 flex-1">
                          <component :is="providerIcon(selectedWalletType?.code)" class="h-5 w-5 shrink-0 text-emerald-400" />
                          <span class="font-bold text-white truncate">{{ acc.name }}</span>
                        </div>
                        <CheckCircle2
                          v-if="form.wallet_account_id === acc.id"
                          class="h-5 w-5 shrink-0 text-emerald-400"
                        />
                      </div>
                      <div class="flex items-center justify-between gap-2 text-xs">
                        <span class="font-mono text-white/50 truncate">
                          {{ acc.wallet_number || '—' }}
                        </span>
                        <span class="shrink-0 rounded-full bg-emerald-500/15 px-2 py-0.5 text-[10px] font-bold text-emerald-300">
                          قسم المكتب
                        </span>
                      </div>
                      <div class="flex items-center justify-between gap-2 border-t border-white/5 pt-2">
                        <span class="text-[10px] uppercase tracking-wider text-white/40">الرصيد</span>
                        <span class="font-mono text-base font-black text-emerald-400">
                          {{ formatCurrency(acc.balance) }}
                        </span>
                      </div>
                    </button>
                  </div>
                </div>
              </template>
            </template>
            <p v-if="errors.wallet_account_id" class="mt-3 text-xs text-rose-400">{{ errors.wallet_account_id }}</p>
          </div>
        </section>

        <!-- STEP 3: Customer -->
        <section class="rounded-2xl border border-white/10 bg-[#111111] p-6">
          <div class="mb-5 flex items-center gap-3">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-purple-500/15 text-purple-400">
              <User class="h-5 w-5" />
            </div>
            <div class="flex-1">
              <h2 class="text-base font-bold text-white">3. بيانات العميل</h2>
              <p class="text-xs text-white/40">اختر العميل من السجل أو أدخل اسمه يدوياً</p>
            </div>
          </div>

          <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
              <label class="mb-2 block text-xs font-bold text-white/60 uppercase tracking-wider">
                العميل المسجّل
              </label>
              <select
                v-model="form.customer_id"
                class="w-full rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white outline-none transition focus:border-amber-500 focus:bg-white/10"
              >
                <option value="">— بدون اختيار (عميل جديد) —</option>
                <option v-for="customer in customers" :key="customer.id" :value="customer.id">
                  {{ customer.full_name }}
                </option>
              </select>
              <p class="mt-1.5 text-xs text-white/40">
                <router-link to="/customers" class="text-amber-400 hover:underline">إدارة العملاء</router-link>
              </p>
            </div>

            <div>
              <label class="mb-2 block text-xs font-bold text-white/60 uppercase tracking-wider">
                اسم العميل <span class="text-rose-400">*</span>
                <span v-if="form.customer_id" class="text-white/30 font-normal text-[10px] mr-1">(يُحدَّث تلقائياً)</span>
              </label>
              <input
                v-model="form.customer_name"
                type="text"
                :readonly="!!form.customer_id"
                :required="!form.customer_id"
                placeholder="اسم العميل كما سيظهر في السجل"
                class="w-full rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white outline-none transition focus:border-amber-500 focus:bg-white/10 read-only:opacity-70 read-only:cursor-not-allowed"
                :class="{ '!border-rose-500': errors.customer_name }"
              />
              <p v-if="errors.customer_name" class="mt-1.5 text-xs text-rose-400">{{ errors.customer_name }}</p>
            </div>

            <div class="md:col-span-2">
              <label class="mb-2 block text-xs font-bold text-white/60 uppercase tracking-wider">
                رقم محفظة العميل <span class="text-rose-400">*</span>
              </label>
              <input
                v-model="form.wallet_number"
                type="tel"
                placeholder="01012345678"
                class="w-full rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-sm font-mono text-white outline-none transition focus:border-amber-500 focus:bg-white/10 tabular-nums"
                :class="{ '!border-rose-500': errors.wallet_number }"
              />
              <p v-if="errors.wallet_number" class="mt-1.5 text-xs text-rose-400">{{ errors.wallet_number }}</p>
            </div>
          </div>
        </section>

        <!-- STEP 4: Amounts -->
        <section class="rounded-2xl border border-white/10 bg-[#111111] p-6">
          <div class="mb-5 flex items-center gap-3">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-500/15 text-emerald-400">
              <Banknote class="h-5 w-5" />
            </div>
            <div class="flex-1">
              <h2 class="text-base font-bold text-white">4. المبالغ</h2>
              <p class="text-xs text-white/40">أدخل المبلغ والعمولة والمبلغ المدفوع</p>
            </div>
          </div>

          <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <div>
              <label class="mb-2 block text-xs font-bold text-white/60 uppercase tracking-wider">
                المبلغ <span class="text-rose-400">*</span>
              </label>
              <div class="relative">
                <input
                  v-model.number="form.amount"
                  type="number"
                  min="0.01"
                  step="0.01"
                  placeholder="0.00"
                  class="w-full rounded-xl border border-white/10 bg-white/5 px-4 py-3 pl-12 text-sm font-mono text-white outline-none transition focus:border-amber-500 focus:bg-white/10 tabular-nums"
                  :class="{ '!border-rose-500': errors.amount }"
                />
                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-xs text-white/40">ج.م</span>
              </div>
              <p v-if="errors.amount" class="mt-1.5 text-xs text-rose-400">{{ errors.amount }}</p>
            </div>

            <div>
              <label class="mb-2 block text-xs font-bold text-white/60 uppercase tracking-wider">
                قيمة الخدمة (العمولة)
              </label>
              <div class="relative">
                <input
                  v-model.number="form.service_fee"
                  type="number"
                  min="0"
                  step="0.01"
                  placeholder="0.00"
                  class="w-full rounded-xl border border-white/10 bg-white/5 px-4 py-3 pl-12 text-sm font-mono text-white outline-none transition focus:border-amber-500 focus:bg-white/10 tabular-nums"
                />
                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-xs text-white/40">ج.م</span>
              </div>
            </div>

            <div>
              <label class="mb-2 block text-xs font-bold text-white/60 uppercase tracking-wider">
                المبلغ المدفوع <span class="text-rose-400">*</span>
              </label>
              <div class="relative">
                <input
                  v-model.number="form.amount_paid"
                  type="number"
                  step="0.01"
                  placeholder="0.00"
                  class="w-full rounded-xl border border-white/10 bg-white/5 px-4 py-3 pl-12 text-sm font-mono text-white outline-none transition focus:border-amber-500 focus:bg-white/10 tabular-nums"
                  :class="{ '!border-rose-500': errors.amount_paid }"
                />
                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-xs text-white/40">ج.م</span>
              </div>
              <div class="mt-2 flex flex-wrap gap-1.5">
                <button
                  v-for="pct in [25, 50, 75, 100]"
                  :key="pct"
                  type="button"
                  @click="setPaidPercent(pct)"
                  class="rounded-lg border border-white/10 bg-white/5 px-2.5 py-1 text-[11px] font-bold text-white/60 hover:border-amber-500/40 hover:text-amber-300 transition"
                >
                  {{ pct }}٪
                </button>
              </div>
            </div>
          </div>
        </section>

        <!-- STEP 5: Cash Account -->
        <section class="rounded-2xl border border-white/10 bg-[#111111] p-6">
          <div class="mb-5 flex items-center gap-3">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-500/15 text-blue-400">
              <Landmark class="h-5 w-5" />
            </div>
            <div class="flex-1">
              <h2 class="text-base font-bold text-white">5. الحساب النقدي</h2>
              <p class="text-xs text-white/40">الخزينة أو البنك الذي ستصرف منه / إليه نقدياً</p>
            </div>
          </div>

          <div>
            <label class="mb-2 block text-xs font-bold text-white/60 uppercase tracking-wider">
              الحساب النقدي <span class="text-rose-400">*</span>
            </label>
            <select
              v-model="form.cash_account_id"
              class="w-full rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white outline-none transition focus:border-amber-500 focus:bg-white/10"
              :class="{ '!border-rose-500': errors.cash_account_id }"
            >
              <option value="">— اختر الحساب —</option>
              <option v-for="acc in cashAccounts" :key="acc.id" :value="acc.id">
                {{ acc.name }} — {{ formatCurrency(acc.balance) }}
              </option>
            </select>
            <p v-if="cashAccounts.length === 0" class="mt-2 text-xs text-amber-300 leading-relaxed">
              لا توجد حسابات نقدية مفعّلة. أضف خزينة أو بنك من
              <router-link to="/finance/accounts" class="font-bold underline hover:text-amber-200">إدارة الحسابات والخزائن</router-link>.
            </p>
            <p v-if="errors.cash_account_id" class="mt-1.5 text-xs text-rose-400">{{ errors.cash_account_id }}</p>
          </div>

          <div class="mt-5">
            <label class="mb-2 block text-xs font-bold text-white/60 uppercase tracking-wider">
              ملاحظات <span class="text-white/30 font-normal">(اختياري)</span>
            </label>
            <textarea
              v-model="form.notes"
              rows="2"
              placeholder="أي ملاحظات إضافية..."
              class="w-full rounded-xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-white outline-none transition focus:border-amber-500 focus:bg-white/10 resize-none"
            />
          </div>
        </section>

        <!-- Error -->
        <div
          v-if="globalError"
          class="rounded-xl border border-rose-500/30 bg-rose-500/10 p-4 text-sm text-rose-300 flex items-start gap-2"
        >
          <AlertCircle class="h-5 w-5 shrink-0" />
          <span>{{ globalError }}</span>
        </div>

        <!-- Action buttons -->
        <div class="flex flex-wrap items-center gap-3">
          <button
            type="submit"
            :disabled="loading.create || !canSubmit"
            class="flex-1 min-w-[240px] inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-l from-amber-500 to-amber-600 px-6 py-3.5 text-base font-black text-black transition shadow-lg shadow-amber-500/20 hover:shadow-amber-500/40 hover:scale-[1.01] active:scale-[0.99] disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:scale-100"
          >
            <Loader2 v-if="loading.create" class="h-5 w-5 animate-spin" />
            <Save v-else class="h-5 w-5" />
            <span v-if="loading.create">جاري الحفظ...</span>
            <span v-else>
              {{ form.type === 'send' ? 'تسجيل إرسال الرصيد' : 'تسجيل استقبال الرصيد' }}
            </span>
          </button>
          <router-link
            to="/wallet"
            class="px-6 py-3.5 rounded-xl border border-white/10 bg-white/5 text-sm font-bold text-white/70 transition hover:border-white/20 hover:bg-white/10 hover:text-white"
          >
            إلغاء والعودة
          </router-link>
        </div>
      </div>

      <!-- ════════════════════════════════════════
           LIVE SUMMARY SIDEBAR
      ════════════════════════════════════════ -->
      <aside class="lg:col-span-1">
        <div class="sticky top-6 space-y-4">
          <!-- Summary card -->
          <div
            class="rounded-2xl border p-5 transition-colors"
            :class="form.type === 'send' ? 'border-amber-500/30 bg-amber-500/5' : form.type === 'receive' ? 'border-emerald-500/30 bg-emerald-500/5' : 'border-white/10 bg-[#111111]'"
          >
            <div class="flex items-center gap-2 mb-3">
              <Receipt class="h-4 w-4 text-amber-400" />
              <h3 class="text-sm font-black text-white uppercase tracking-wider">ملخص العملية</h3>
            </div>

            <div class="space-y-2 text-sm">
              <div class="flex items-center justify-between text-white/60">
                <span>نوع العملية</span>
                <span
                  class="font-bold"
                  :class="form.type === 'send' ? 'text-amber-300' : form.type === 'receive' ? 'text-emerald-300' : 'text-white/30'"
                >
                  {{ form.type === 'send' ? 'إرسال' : form.type === 'receive' ? 'استقبال' : '—' }}
                </span>
              </div>
              <div class="flex items-center justify-between text-white/60">
                <span>نوع المحفظة</span>
                <span class="font-bold text-white">{{ selectedWalletType?.name || '—' }}</span>
              </div>
              <div class="flex items-center justify-between text-white/60">
                <span>الحساب</span>
                <span class="font-mono text-xs text-white truncate max-w-[140px]" :title="selectedWalletAccount?.name">
                  {{ selectedWalletAccount?.name || '—' }}
                </span>
              </div>
              <div class="flex items-center justify-between text-white/60">
                <span>العميل</span>
                <span class="font-bold text-white truncate max-w-[140px]" :title="form.customer_name">
                  {{ form.customer_name || '—' }}
                </span>
              </div>

              <div class="my-2 h-px bg-white/10" />

              <div class="flex items-center justify-between text-white/60">
                <span>المبلغ</span>
                <span class="font-mono font-bold text-white">{{ formatCurrency(form.amount || 0) }}</span>
              </div>
              <div class="flex items-center justify-between text-white/60">
                <span>الخدمة</span>
                <span class="font-mono text-amber-300">{{ formatCurrency(form.service_fee || 0) }}</span>
              </div>
              <div class="flex items-center justify-between text-white/60">
                <span>الإجمالي</span>
                <span class="font-mono font-bold text-white">{{ formatCurrency(totalAmount) }}</span>
              </div>
              <div class="flex items-center justify-between text-white/60">
                <span>المدفوع</span>
                <span class="font-mono text-emerald-300">{{ formatCurrency(form.amount_paid || 0) }}</span>
              </div>
              <div class="flex items-center justify-between text-white/60">
                <span>{{ form.type === 'send' ? 'المتبقي آجل' : 'المتبقي دائن' }}</span>
                <span
                  class="font-mono font-bold"
                  :class="(totalAmount - (form.amount_paid || 0)) > 0 ? 'text-rose-400' : 'text-emerald-400'"
                >
                  {{ formatCurrency(Math.max(0, totalAmount - (form.amount_paid || 0))) }}
                </span>
              </div>

              <div class="my-2 h-px bg-white/10" />

              <div class="flex items-center justify-between">
                <span class="text-white/70 font-bold">ربح الوكالة</span>
                <span class="font-mono font-black text-emerald-400 text-base">
                  {{ formatCurrency(form.service_fee || 0) }}
                </span>
              </div>
            </div>
          </div>

          <!-- Step status -->
          <div class="rounded-2xl border border-white/10 bg-[#111111] p-5">
            <h3 class="mb-3 text-xs font-black text-white/70 uppercase tracking-wider">حالة الإكمال</h3>
            <ul class="space-y-2 text-xs">
              <li class="flex items-center gap-2">
                <component :is="form.type ? Check : Circle" class="h-4 w-4" :class="form.type ? 'text-emerald-400' : 'text-white/30'" />
                <span :class="form.type ? 'text-white' : 'text-white/40'">نوع العملية</span>
              </li>
              <li class="flex items-center gap-2">
                <component :is="form.wallet_type_id ? Check : Circle" class="h-4 w-4" :class="form.wallet_type_id ? 'text-emerald-400' : 'text-white/30'" />
                <span :class="form.wallet_type_id ? 'text-white' : 'text-white/40'">نوع المحفظة</span>
              </li>
              <li class="flex items-center gap-2">
                <component :is="form.wallet_account_id ? Check : Circle" class="h-4 w-4" :class="form.wallet_account_id ? 'text-emerald-400' : 'text-white/30'" />
                <span :class="form.wallet_account_id ? 'text-white' : 'text-white/40'">الحساب المختار</span>
              </li>
              <li class="flex items-center gap-2">
                <component :is="form.customer_name ? Check : Circle" class="h-4 w-4" :class="form.customer_name ? 'text-emerald-400' : 'text-white/30'" />
                <span :class="form.customer_name ? 'text-white' : 'text-white/40'">بيانات العميل</span>
              </li>
              <li class="flex items-center gap-2">
                <component :is="form.amount > 0 ? Check : Circle" class="h-4 w-4" :class="form.amount > 0 ? 'text-emerald-400' : 'text-white/30'" />
                <span :class="form.amount > 0 ? 'text-white' : 'text-white/40'">المبالغ</span>
              </li>
              <li class="flex items-center gap-2">
                <component :is="form.cash_account_id ? Check : Circle" class="h-4 w-4" :class="form.cash_account_id ? 'text-emerald-400' : 'text-white/30'" />
                <span :class="form.cash_account_id ? 'text-white' : 'text-white/40'">الحساب النقدي</span>
              </li>
            </ul>
          </div>
        </div>
      </aside>
    </form>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onActivated, watch, h } from 'vue';
import { useRouter } from 'vue-router';
import { storeToRefs } from 'pinia';
import axios from 'axios';
import { useWalletStore } from '@/stores/walletStore';
import { useCustomerStore } from '@/stores/customerStore';
import {
  fetchSettlementAccounts,
  accountMatchesWalletType,
  normalizeWalletProviderCode,
} from '@/composables/useTreasuryAccountGroups';
import {
  ArrowRight,
  ArrowUpCircle,
  ArrowDownCircle,
  ArrowLeftRight,
  CheckCircle2,
  Check,
  Circle,
  Wallet,
  User,
  Banknote,
  Landmark,
  Receipt,
  Loader2,
  Save,
  AlertCircle,
  AlertTriangle,
  Smartphone,
  CreditCard,
  Building2,
  Send,
  Settings2,
  ExternalLink,
  Plus,
  ShieldCheck,
} from 'lucide-vue-next';

const router = useRouter();
const store = useWalletStore();
const customerStore = useCustomerStore();
const { activeWalletTypes, loading } = storeToRefs(store);

function createDefaultForm() {
  return {
    type: 'send',
    wallet_type_id: '',
    customer_id: '',
    customer_name: '',
    wallet_number: '',
    amount: '',
    service_fee: '',
    amount_paid: 0,
    wallet_account_id: '',
    cash_account_id: '',
    notes: '',
  };
}

const form = ref(createDefaultForm());

function resetForm() {
  form.value = createDefaultForm();
  errors.value = {};
  globalError.value = '';
}

const errors = ref({});
const globalError = ref('');
const walletAccounts = ref([]);
const cashAccounts = ref([]);
const customers = ref([]);

/* ═══════ Computed totals ═══════ */
const totalAmount = computed(() => {
  const amt = parseFloat(form.value.amount) || 0;
  const fee = parseFloat(form.value.service_fee) || 0;
  return form.value.type === 'send' ? amt + fee : amt - fee;
});

const roundMoney = (n) => Math.round((Number(n) || 0) * 100) / 100;

const setPaidPercent = (pct) => {
  const tot = totalAmount.value;
  if (tot <= 0) {
    store.addToast('يرجى تحديد المبلغ والعمولة أولاً', 'error');
    return;
  }
  form.value.amount_paid = roundMoney((tot * pct) / 100);
};

watch(totalAmount, (newVal) => {
  if (!form.value.amount_paid || form.value.amount_paid === 0) {
    form.value.amount_paid = newVal;
  }
});

watch(
  () => form.value.customer_id,
  (id) => {
    if (!id) {
      form.value.customer_name = '';
      return;
    }
    const c = customers.value.find((x) => String(x.id) === String(id));
    if (c?.full_name) {
      form.value.customer_name = c.full_name;
    }
  }
);

/* ═══════ Wallet type / account filtering ═══════ */
const selectedWalletType = computed(() => {
  const id = form.value.wallet_type_id;
  if (!id) return null;
  return activeWalletTypes.value.find((w) => String(w.id) === String(id)) ?? null;
});

const filteredWalletAccounts = computed(() => {
  const type = selectedWalletType.value;
  // فلتر مزدوج:
  //   (1) محفظة liquidity نشطة من قسم المكتب (office) — مع توسيع النطاق
  //       عشان نضمن إن أي محفظة ظاهرة في /wallet/treasury هتظهر هنا.
  //   (2) provider يطابق نوع المحفظة المختار
  // الباك إند (TransferLiquidityAccount rule + TreasuryController) بيقبل النطاق ده.
  //
  // شروط القبول (متطابقة مع TransferTreasuryController::overview):
  //   ✓ module_type='wallet_transfer'  (المحفظة الرسمية للموديول)
  //   ✓ module_type='office'          (محفظة قسم المكتب — division marker)
  //   ✓ module='wallet_transfer'      (legacy alias)
  //   ✓ module_type IN (['office','wallet_transfer'])  (defensive — لو رجع مع module_type='office' بس module='bus')
  const baseList = walletAccounts.value.filter((a) => {
    // شرط 1: المحفظة الرسمية للموديول
    if (a.module === 'wallet_transfer' || a.module_type === 'wallet_transfer') {
      return true;
    }
    // شرط 2: محفظة قسم المكتب العامة (module_type='office' أو لا يوجد)
    if (a.module_type === 'office') {
      return true;
    }
    // شرط 3 (defensive): لو module_type ناقص/null بس module='wallet_transfer' أو type='wallet'
    // ده بيغطي edge cases لو الـ data فيها module_type=null أو قيم غير متوقعة
    if (
      (a.module === 'wallet_transfer' || !a.module)
      && (a.type === 'wallet' || a.type?.value === 'wallet' || a.type?.value === 'محفظة')
    ) {
      return true;
    }
    return false;
  });
  if (!type) {
    return baseList;
  }
  return baseList.filter((a) => accountMatchesWalletType(a, type));
});

/**
 * تقسيم المحافظ المتاحة لمجموعتين بصرياً:
 *   - official: المحفظة الرسمية للموديول (module='wallet_transfer' OR module_type='wallet_transfer')
 *   - officeWide: محافظ قسم المكتب العامة (كل اللي مش رسمي — أي محفظة liquidity تانية ظاهرة)
 */
const groupedWalletAccounts = computed(() => {
  const list = filteredWalletAccounts.value;
  const official = list.filter(
    (a) => a.module === 'wallet_transfer' || a.module_type === 'wallet_transfer'
  );
  // أي محفظة liquidity تانية (مكتب عام) — أي حاجة مش wallet_transfer رسمية
  const officeWide = list.filter(
    (a) => a.module !== 'wallet_transfer' && a.module_type !== 'wallet_transfer'
  );
  return { official, officeWide };
});

/**
 * فلتر تشغيلي للـ UI:
 *   'all'      = كل المحافظ (افتراضي)
 *   'official' = الرسمية للموديول فقط
 *   'office'   = قسم المكتب فقط
 */
const walletScopeFilter = ref('all');

const visibleWalletAccounts = computed(() => {
  const { official, officeWide } = groupedWalletAccounts.value;
  if (walletScopeFilter.value === 'official') return official;
  if (walletScopeFilter.value === 'office') return officeWide;
  return [...official, ...officeWide];
});

/**
 * Helper: يحدد نطاق محفظة معينة (للـ badge والـ empty states)
 */
function walletScopeOf(account) {
  if (!account) return null;
  if (account.module === 'wallet_transfer' || account.module_type === 'wallet_transfer') {
    return 'official';
  }
  if (account.module_type === 'office') {
    return 'office';
  }
  return 'other';
}

const selectedWalletAccount = computed(() => {
  const id = form.value.wallet_account_id;
  if (!id) return null;
  return walletAccounts.value.find((a) => String(a.id) === String(id)) ?? null;
});

const unmatchedWalletProviders = computed(() => {
  const type = selectedWalletType.value;
  if (!type || walletAccounts.value.length === 0) return [];
  return walletAccounts.value
    .filter((a) => !accountMatchesWalletType(a, type))
    .map((a) => normalizeWalletProviderCode(a.wallet_provider) || '(غير محدد)')
    .filter((v, i, arr) => arr.indexOf(v) === i);
});

/* When the user changes wallet type, auto-pick the wallet account if only one matches */
watch(visibleWalletAccounts, (newAccounts) => {
  if (newAccounts.length === 1) {
    form.value.wallet_account_id = newAccounts[0].id;
  } else if (!newAccounts.some((a) => a.id === form.value.wallet_account_id)) {
    form.value.wallet_account_id = '';
  }
});

/* ═══════ Progress ═══════ */
const totalSteps = 6;
const completedSteps = computed(() => {
  let n = 0;
  if (form.value.type) n++;
  if (form.value.wallet_type_id) n++;
  if (form.value.wallet_account_id) n++;
  if (form.value.customer_name) n++;
  if (form.value.amount > 0) n++;
  if (form.value.cash_account_id) n++;
  return n;
});

const canSubmit = computed(() => completedSteps.value >= totalSteps);

/* ═══════ Provider icon helper (visual feedback per wallet type) ═══════ */
const PROVIDER_ICONS = {
  vodafone_cash: Smartphone,
  instapay: Send,
  etisalat_cash: Smartphone,
  orange_cash: Smartphone,
  we_pay: Smartphone,
  paymob: CreditCard,
  cash_wallet: Wallet,
  postal: Building2,
  fawry: Building2,
};
function providerIcon(code) {
  if (!code) return Wallet;
  return PROVIDER_ICONS[normalizeWalletProviderCode(code)] || Wallet;
}

/* ═══════ Select wallet type — convenience handler ═══════ */
function selectWalletType(id) {
  form.value.wallet_type_id = id;
}

/* ═══════ Lifecycle ═══════ */
onMounted(async () => {
  resetForm();
  await store.fetchWalletTypes();
  await Promise.all([
    fetchAccounts(),
    fetchCustomers(),
  ]);
});

onActivated(() => {
  resetForm();
});

async function fetchCustomers() {
  try {
    await customerStore.fetchCustomers({ per_page: 200 });
    customers.value = customerStore.customers || [];
  } catch (error) {
    console.error('Failed to fetch customers:', error);
  }
}

async function fetchAccounts() {
  const typeOf = (a) => String(a?.type?.value ?? a?.type ?? '').toLowerCase();

  try {
    const overview = await store.fetchTransferTreasury();
    const treasuryWallets = Array.isArray(overview?.wallets) ? overview.wallets : [];
    const treasuryCash = [
      ...(Array.isArray(overview?.cashboxes) ? overview.cashboxes : []),
      ...(Array.isArray(overview?.banks) ? overview.banks : []),
    ];
    if (treasuryWallets.length > 0 || treasuryCash.length > 0) {
      walletAccounts.value = treasuryWallets;
      cashAccounts.value = treasuryCash;
      return;
    }
  } catch (e) {
    console.warn('Wallet treasury overview unavailable, falling back to finance accounts', e);
  }

  try {
    const all = await fetchSettlementAccounts(axios, { module: 'wallet' });
    walletAccounts.value = all.filter((a) => typeOf(a) === 'wallet');
    cashAccounts.value = all.filter((a) => ['cashbox', 'bank'].includes(typeOf(a)));
  } catch (e) {
    console.error('Failed to load accounts', e);
    walletAccounts.value = [];
    cashAccounts.value = [];
  }
}

/* ═══════ Formatters ═══════ */
function formatCurrency(amount) {
  return new Intl.NumberFormat('ar-EG', {
    style: 'currency',
    currency: 'EGP',
  }).format(Number(amount) || 0);
}

/* ═══════ Submit ═══════ */
async function submit() {
  errors.value = {};
  globalError.value = '';

  if (!form.value.type) errors.value.type = 'اختر نوع العملية';
  if (!form.value.wallet_type_id) errors.value.wallet_type_id = 'اختر نوع المحفظة';
  if (!form.value.customer_name) errors.value.customer_name = 'اسم العميل مطلوب';
  if (!form.value.wallet_number) errors.value.wallet_number = 'رقم المحفظة مطلوب';
  if (!form.value.amount || parseFloat(form.value.amount) <= 0) errors.value.amount = 'المبلغ مطلوب';
  if (!form.value.wallet_account_id) errors.value.wallet_account_id = 'اختر محفظة من القائمة';
  if (!form.value.cash_account_id) errors.value.cash_account_id = 'اختر الحساب النقدي';

  if (Object.keys(errors.value).length > 0) return;

  try {
    await store.createTransaction({
      ...form.value,
      service_fee: parseFloat(form.value.service_fee) || 0,
      amount_paid: parseFloat(form.value.amount_paid) || 0,
    });
    router.push('/wallet');
  } catch (e) {
    const serverErrors = e.response?.data?.errors;
    if (serverErrors) {
      errors.value = Object.fromEntries(
        Object.entries(serverErrors).map(([k, v]) => [k, Array.isArray(v) ? v[0] : v]),
      );
    } else {
      globalError.value = e.response?.data?.message || 'حدث خطأ، حاول مرة أخرى';
    }
  }
}
</script>

<style scoped>
/* ─── Native <select> dropdown options ─────────────────────────────
   المتصفح يرسم قائمة الـ <select> المنسدلة بألوان النظام الافتراضية،
   فيظهر النص بتباين ضعيف (رمادي فاتح على أبيض) لا يُقرأ على الثيم
   الداكن. نُجبر <option> على ألوان واضحة ومتسقة مع بقية الصفحة. */
.wallet-create-page select option {
  color: #0B2D4E;            /* كحلي غامق — نص أساسي واضح */
  background-color: #FFFFFF; /* خلفية بيضاء */
  font-weight: 600;
}

.wallet-create-page select option:checked,
.wallet-create-page select option[selected],
.wallet-create-page select option[aria-selected="true"] {
  background-color: #185FA5; /* الأزرق الأساسي للهوية */
  color: #FFFFFF;
  font-weight: 700;
}

.wallet-create-page select option:hover {
  background-color: #EDF4FC;
  color: #0B2D4E;
}

/* تعطيل العنصر إن كان معطّلًا (defensive) */
.wallet-create-page select option:disabled {
  color: #9CA3AF;
  background-color: #F4F8FD;
}
</style>
