<template>
  <div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
      <div>
        <h1 class="text-3xl font-display font-black text-transparent bg-clip-text bg-gradient-to-l from-rose-400 to-rose-600 flex items-center gap-3">
          <Banknote class="w-10 h-10 text-rose-500" />
          المصروفات
        </h1>
        <p class="text-sm text-text-muted mt-1">سجل تفصيلي بكافة المصروفات وتصنيفاتها لضمان الشفافية المالية.</p>
      </div>

      <button
        @click="openExpenseModal"
        class="bg-gradient-to-l from-rose-500 to-rose-600 hover:from-rose-400 hover:to-rose-500 text-white px-5 py-2.5 rounded-xl text-sm font-bold shadow-lg shadow-rose-500/20 transition-all flex items-center gap-2"
      >
        <Plus class="w-5 h-5" />
        تسجيل مصروف جديد
      </button>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
      <div class="bg-card-bg border border-white/5 p-6 rounded-2xl shadow-xl">
        <div class="flex items-center gap-4">
          <div class="w-14 h-14 bg-gradient-to-br from-rose-500/20 to-rose-600/10 rounded-2xl flex items-center justify-center shadow-inner shadow-rose-500/20 border border-rose-500/20">
            <Coins class="w-7 h-7 text-rose-400" />
          </div>
          <div>
            <p class="text-sm font-medium text-text-muted">إجمالي المصروفات (هذا الشهر)</p>
            <p class="text-2xl font-bold text-white mt-1">
              {{ formatCurrency(stats.thisMonth) }}
            </p>
          </div>
        </div>
      </div>
    </div>

    <!-- Expenses Table -->
    <div class="bg-card-bg border border-white/5 rounded-2xl shadow-xl overflow-hidden">
      <div class="p-6 border-b border-white/5 flex flex-col sm:flex-row justify-between items-center gap-4">
        <h2 class="text-lg font-bold text-white">سجل المصروفات</h2>
        
        <!-- Filters -->
        <div class="flex gap-3">
          <select
            id="expense-filter-account"
            name="expense_filter_account"
            v-model="filters.expenseAccount"
            class="bg-input-bg border border-white/10 text-white rounded-lg px-4 py-2 text-sm focus:outline-none focus:border-rose-500 transition-colors"
          >
            <option value="">جميع بنود المصروفات</option>
            <option v-for="acc in expenseAccounts" :key="acc.id" :value="acc.id">
              {{ acc.name }}
            </option>
          </select>
        </div>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-right">
          <thead>
            <tr class="bg-white/5 border-b border-white/5 text-sm text-text-muted">
              <th class="p-4 font-semibold">تاريخ التسجيل</th>
              <th class="p-4 font-semibold">بند المصروف (الحساب)</th>
              <th class="p-4 font-semibold">توجيه القسم</th>
              <th class="p-4 font-semibold">تم الدفع من (الخزينة)</th>
              <th class="p-4 font-semibold">المبلغ</th>
              <th class="p-4 font-semibold">البيان / الوصف</th>
              <th class="p-4 font-semibold">بواسطة</th>
              <th v-if="canManageExpenses" class="p-4 font-semibold text-center">الإجراءات</th>
            </tr>
          </thead>
          <tbody class="text-sm text-white divide-y divide-white/5">
            <template v-if="asyncState === 'loading'">
              <tr v-for="i in 8" :key="i" class="border-b border-white/5">
                <td :colspan="canManageExpenses ? 8 : 7" class="p-4">
                  <div class="h-4 bg-white/5 animate-pulse rounded w-full"></div>
                </td>
              </tr>
            </template>
            <tr v-else-if="asyncState === 'error'" class="text-center">
              <td :colspan="canManageExpenses ? 8 : 7" class="p-8 text-error">حدث خطأ أثناء تحميل المصروفات</td>
            </tr>
            <tr v-else-if="asyncState === 'empty'" class="text-center">
              <td :colspan="canManageExpenses ? 8 : 7" class="p-8 text-text-muted">لا توجد مصروفات مسجلة بعد.</td>
            </tr>
            <template v-else>
              <tr v-for="expense in filteredExpenses" :key="expense.id" class="hover:bg-white/5 transition-colors">
                <td class="p-4">
                  <div class="flex flex-col">
                    <span class="font-medium">{{ formatDate(expense.created_at) }}</span>
                    <span class="text-xs text-text-muted">{{ formatTime(expense.created_at) }}</span>
                  </div>
                </td>
                <td class="p-4">
                  <span class="bg-rose-500/20 text-rose-300 px-3 py-1 rounded-lg font-medium text-xs">
                    {{ expense.to_account_name || expense.to_account?.name || 'غير محدد' }}
                  </span>
                </td>
                <td class="p-4">
                  <span class="bg-indigo-500/20 text-indigo-300 px-3 py-1 rounded-lg font-medium text-xs">
                    {{ formatModule(expense.module) }}
                  </span>
                </td>
                <td class="p-4">
                  <span class="text-emerald-400 font-medium">
                    {{ expense.from_account_name || expense.from_account?.name || 'غير محدد' }}
                  </span>
                </td>
                <td class="p-4 font-bold text-white">
                  {{ formatCurrency(expense.amount, expense.from_account_currency || 'EGP') }}
                </td>
                <td class="p-4 text-text-muted max-w-xs truncate" :title="expense.notes">
                  {{ expense.notes || '---' }}
                </td>
                <td class="p-4 text-text-muted">
                  {{ expense.created_by_name || expense.created_by?.name || '---' }}
                </td>
                <td v-if="canManageExpenses" class="p-4 text-center">
                  <div class="flex items-center justify-center gap-2">
                    <button
                      type="button"
                      @click="openEditModal(expense)"
                      title="تعديل المصروف"
                      class="p-2 rounded-xl bg-amber-500/10 hover:bg-amber-500/20 text-amber-400 hover:text-amber-300 transition-colors border border-amber-500/20 cursor-pointer"
                    >
                      <Edit3 class="w-4 h-4" />
                    </button>
                    <button
                      type="button"
                      @click="openDeleteModal(expense)"
                      title="إلغاء وحذف المصروف"
                      class="p-2 rounded-xl bg-rose-500/10 hover:bg-rose-500/20 text-rose-400 hover:text-rose-300 transition-colors border border-rose-500/20 cursor-pointer"
                    >
                      <Trash2 class="w-4 h-4" />
                    </button>
                  </div>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div v-if="pagination.last_page > 1" class="px-6 py-4 bg-white/[0.02] border-t border-white/5 flex items-center justify-between">
        <p class="text-sm font-medium text-text-muted">
          إظهار {{ pagination.from }} - {{ pagination.to }} من إجمالي {{ pagination.total }} مصروف
        </p>
        <div class="flex gap-2">
          <button 
            type="button"
            @click="changePage(pagination.current_page - 1)"
            :disabled="pagination.current_page === 1"
            class="p-2 rounded-xl border border-white/5 bg-white/5 text-text-muted hover:text-rose-500 disabled:opacity-30 transition-all cursor-pointer"
          >
            <ChevronRight class="w-5 h-5" />
          </button>
          <div class="flex items-center gap-1 px-4 text-sm font-bold text-white">
            <span class="text-rose-400">{{ pagination.current_page }}</span>
            <span class="text-text-muted">/</span>
            <span>{{ pagination.last_page }}</span>
          </div>
          <button 
            type="button"
            @click="changePage(pagination.current_page + 1)"
            :disabled="pagination.current_page === pagination.last_page"
            class="p-2 rounded-xl border border-white/5 bg-white/5 text-text-muted hover:text-rose-500 disabled:opacity-30 transition-all cursor-pointer"
          >
            <ChevronLeft class="w-5 h-5" />
          </button>
        </div>
      </div>
    </div>

    <!-- New Expense Modal -->
    <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="closeExpenseModal"></div>
      
      <div class="bg-card-bg border border-white/10 w-full max-w-lg rounded-2xl shadow-2xl relative z-10 overflow-hidden">
        <div class="p-6 border-b border-white/10 bg-gradient-to-l from-rose-500/10 to-transparent">
          <h3 class="text-xl font-bold text-white flex items-center gap-2">
            <PlusCircle class="w-6 h-6 text-rose-400" />
            تسجيل مصروف جديد
          </h3>
          <p class="text-xs text-text-muted mt-1">يتم تسجيل قيد محاسبي مزدوج لضمان سلامة الخزينة</p>
        </div>
        
        <form @submit.prevent="submitExpense" class="p-6 space-y-5">
          <div v-if="globalError" class="bg-rose-500/10 border border-rose-500/20 text-rose-400 p-3 rounded-lg text-sm">
            {{ globalError }}
          </div>
          <div v-if="successMessage" class="bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 p-3 rounded-lg text-sm">
            {{ successMessage }}
          </div>
          <div>
            <label for="expense-category" class="block text-sm font-medium text-white mb-2">1. توجيه المصروف (القسم الرئيسي)</label>
            <select
              id="expense-category"
              name="expense_category"
              v-model="form.category"
              @change="updateModuleSelection"
              required
              class="w-full bg-input-bg border border-white/10 text-white rounded-xl px-4 py-3 focus:outline-none focus:border-rose-500 transition-colors shadow-inner"
            >
              <option value="general">مصروفات عامة (إدارية)</option>
              <option value="tourism">قسم السياحة</option>
              <option value="office">قسم المكتب</option>
            </select>
            <p class="text-xs text-text-muted mt-2 text-rose-300/70">أولاً، حدد القسم الذي تريد تحميل هذا المصروف عليه في تقرير الأرباح والخسائر.</p>
          </div>
        
          <div v-if="form.category !== 'general'">
            <label for="expense-module" class="block text-sm font-medium text-white mb-2">2. تحديد الموديول الفرعي</label>
            <select
              id="expense-module"
              name="expense_module"
              v-model="form.module"
              required
              class="w-full bg-input-bg border border-white/10 text-white rounded-xl px-4 py-3 focus:outline-none focus:border-rose-500 transition-colors shadow-inner"
            >
              <template v-if="form.category === 'tourism'">
                <option value="tourism">عام (سياحة بصفة عامة)</option>
                <option value="flight">قسم الطيران</option>
                <option value="hajj_umra">قسم الحج والعمرة</option>
                <option value="visa">قسم التأشيرات</option>
              </template>
              <template v-else-if="form.category === 'office'">
                <option value="office">عام (مكتب بصفة عامة)</option>
                <option value="bus">قسم الباص</option>
                <option value="fawry">قسم فوري</option>
                <option value="online">الخدمات الإلكترونية</option>
              </template>
            </select>
          </div>

          <div>
            <label for="expense-from-account" class="block text-sm font-medium text-white mb-2">{{ form.category === 'general' ? '2' : '3' }}. السحب من (الخزينة/البنك/المحفظة)</label>
            <select
              id="expense-from-account"
              name="expense_from_account"
              v-model="form.from_account_id"
              required
              class="w-full bg-input-bg border border-white/10 text-white rounded-xl px-4 py-3 focus:outline-none focus:border-rose-500 transition-colors"
            >
              <option value="" disabled>اختر الطريقة المالية لسحب المصروف...</option>
              <optgroup
                v-for="group in treasuryAccountGroups"
                :key="group.key"
                :label="group.label"
              >
                <option v-for="acc in group.accounts" :key="acc.id" :value="acc.id">
                  {{ acc.name }} — {{ formatAccountType(acc.type) }} ({{ formatCurrency(acc.balance, acc.currency) }})
                </option>
              </optgroup>
            </select>
            <p class="text-xs text-text-muted mt-2">
              <template v-if="form.category === 'tourism'">
                تظهر خزائن قسم السياحة فقط (الطيران، الحج والعمرة، التأشيرات، وغيرها).
              </template>
              <template v-else-if="form.category === 'office'">
                تظهر خزائن قسم المكتب فقط (الباص، فوري، الإلكتروني، وغيرها).
              </template>
              <template v-else>
                تظهر خزائن الإدارة العامة والمكتب.
              </template>
            </p>
          </div>

          <div>
            <div class="flex justify-between items-center mb-2">
              <label for="expense-account" class="text-sm font-medium text-white">{{ form.category === 'general' ? '3' : '4' }}. تصنيف المصروف المحاسبي (بند المصروف)</label>
              <button
                type="button"
                @click="toggleCustomExpenseAccount"
                class="text-xs text-rose-400 hover:text-rose-300 underline font-bold"
              >
                {{ form.is_custom ? '✍️ اختيار من القائمة' : '➕ كتابة بند جديد يدويًا' }}
              </button>
            </div>

            <div v-if="form.is_custom">
              <input
                id="expense-account-name"
                name="expense_account_name"
                type="text"
                v-model="form.expense_account_name"
                required
                class="w-full bg-input-bg border border-white/10 text-white rounded-xl px-4 py-3 focus:outline-none focus:border-rose-500 transition-colors"
                placeholder="اكتب نوع المصروف الجديد (مثال: إيجار، فاتورة كهرباء، صيانة...)"
              />
            </div>
            <div v-else>
              <select
                id="expense-account"
                name="expense_account"
                v-model="form.expense_account_id"
                required
                class="w-full bg-input-bg border border-white/10 text-white rounded-xl px-4 py-3 focus:outline-none focus:border-rose-500 transition-colors"
              >
                <option value="" disabled>اختر التصنيف (مثل: رواتب، إيجار، تسويق)</option>
                <option v-for="acc in selectableExpenseAccounts" :key="acc.id" :value="acc.id">
                  {{ acc.name }}
                </option>
              </select>
              <p v-if="selectableExpenseAccounts.length === 0" class="text-xs text-rose-400 mt-2 font-bold">
                ⚠️ لا توجد بنود مصروفات! أضفها من لوحة Filament أو اضغط على زر "كتابة بند جديد يدويًا" بالأعلى لكتابتها الآن.
              </p>
            </div>
          </div>

          <div>
            <label for="expense-amount" class="block text-sm font-medium text-white mb-2">{{ form.category === 'general' ? '4' : '5' }}. المبلغ المدفوع</label>
            <div class="relative">
              <input
                id="expense-amount"
                name="expense_amount"
                type="number"
                v-model="form.amount"
                required
                min="0.01"
                step="0.01"
                class="w-full bg-input-bg border border-white/10 text-white rounded-xl px-4 py-3 pr-12 focus:outline-none focus:border-rose-500 transition-colors"
                placeholder="أدخل المبلغ..."
              />
              <span class="absolute right-4 top-1/2 -translate-y-1/2 text-text-muted font-bold">{{ fromAccount?.currency || 'EGP' }}</span>
            </div>
          </div>

          <div v-if="fromAccount && toAccount && fromAccount.currency !== toAccount.currency">
            <label for="expense-exchange-rate" class="block text-sm font-medium text-white mb-2">سعر الصرف</label>
            <div class="flex items-center gap-2">
              <span class="text-sm text-text-muted">1 {{ fromAccount.currency }} =</span>
              <input
                id="expense-exchange-rate"
                name="expense_exchange_rate"
                v-model.number="form.exchange_rate"
                type="number"
                step="0.000001"
                min="0.000001"
                required
                class="flex-1 bg-input-bg border border-white/10 text-white rounded-xl px-4 py-3 focus:outline-none focus:border-rose-500 transition-colors"
              />
              <span class="text-sm text-text-muted">{{ toAccount.currency }}</span>
            </div>
            <p class="mt-2 text-xs text-rose-300/85">
              المبلغ المعادل في بند المصروف: {{ formatCurrency(convertedAmount, toAccount.currency) }}
            </p>
          </div>

          <div>
            <label for="expense-notes" class="block text-sm font-medium text-white mb-2">{{ form.category === 'general' ? '5' : '6' }}. البيان / الوصف</label>
            <textarea
              id="expense-notes"
              name="expense_notes"
              v-model="form.notes"
              rows="3"
              required
              class="w-full bg-input-bg border border-white/10 text-white rounded-xl px-4 py-3 focus:outline-none focus:border-rose-500 transition-colors"
              placeholder="اكتب تفاصيل أو سبب المصروف..."
            ></textarea>
          </div>

          <div class="flex gap-3 pt-4">
            <button
              type="button"
              @click="closeExpenseModal"
              class="flex-1 bg-white/5 hover:bg-white/10 text-white py-3 rounded-xl font-bold transition-colors"
            >
              إلغاء
            </button>
            <button
              type="submit"
              :disabled="isSubmitting"
              class="flex-1 bg-gradient-to-l from-rose-500 to-rose-600 hover:from-rose-400 hover:to-rose-500 text-white py-3 rounded-xl font-bold shadow-lg shadow-rose-500/20 transition-all flex justify-center items-center gap-2 disabled:opacity-50"
            >
              <span v-if="isSubmitting" class="w-5 h-5 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
              <span v-else>حفظ واعتماد</span>
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Edit Expense Modal -->
    <div v-if="isEditModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="closeEditModal"></div>
      
      <div class="bg-card-bg border border-white/10 w-full max-w-lg rounded-2xl shadow-2xl relative z-10 overflow-hidden">
        <div class="p-6 border-b border-white/10 bg-gradient-to-l from-amber-500/10 to-transparent">
          <div class="flex items-center justify-between">
            <h3 class="text-xl font-bold text-white flex items-center gap-2">
              <Edit3 class="w-6 h-6 text-amber-400" />
              تعديل المصروف
            </h3>
            <span class="text-xs bg-amber-500/20 text-amber-300 px-2.5 py-1 rounded-full font-mono">
              #{{ editForm.id }}
            </span>
          </div>
          <p class="text-xs text-text-muted mt-1">تعديل محاسبي آمن عبر إنشاء قيد عكسي وقيد جديد محدّث</p>
        </div>

        <div class="mx-6 mt-4 p-3 rounded-xl bg-amber-500/10 border border-amber-500/20 flex items-start gap-2 text-xs text-amber-200">
          <AlertTriangle class="w-4 h-4 text-amber-400 shrink-0 mt-0.5" />
          <span>
            <strong>تنبيه محاسبي:</strong> لن يتم التعديل اليدوي على القيود القديمة لحفظ تاريخ المعاملة. سيقوم النظام بعمل قيد عكسي وإعادة احتساب رصيد الخزينة بدقة.
          </span>
        </div>
        
        <form @submit.prevent="submitEditExpense" class="p-6 space-y-4">
          <div v-if="editError" class="bg-rose-500/10 border border-rose-500/20 text-rose-400 p-3 rounded-lg text-sm">
            {{ editError }}
          </div>

          <!-- المبلغ -->
          <div>
            <label for="edit-expense-amount" class="block text-sm font-medium text-white mb-2">المبلغ الجديد</label>
            <div class="relative">
              <input
                id="edit-expense-amount"
                name="edit_expense_amount"
                type="number"
                v-model.number="editForm.amount"
                required
                min="0.01"
                step="0.01"
                class="w-full bg-input-bg border border-white/10 text-white rounded-xl px-4 py-3 pr-16 focus:outline-none focus:border-amber-500 transition-colors"
                placeholder="أدخل المبلغ..."
              />
              <span class="absolute right-4 top-1/2 -translate-y-1/2 text-text-muted font-bold">{{ editForm.from_account_currency || 'EGP' }}</span>
            </div>
            <p v-if="editForm.original_amount !== undefined" class="text-xs text-text-muted mt-1 flex items-center justify-between">
              <span>المبلغ السابق: <strong class="text-white">{{ formatCurrency(editForm.original_amount, editForm.from_account_currency || 'EGP') }}</strong></span>
              <span v-if="editForm.amount && editForm.amount !== editForm.original_amount">
                الفرق: <strong :class="editForm.amount > editForm.original_amount ? 'text-rose-400' : 'text-emerald-400'" dir="ltr">
                  {{ (editForm.amount - editForm.original_amount > 0 ? '+' : '') + (editForm.amount - editForm.original_amount).toFixed(2) }}
                </strong>
              </span>
            </p>
          </div>

          <!-- الخزينة المسحوب منها -->
          <div>
            <label for="edit-expense-account-id" class="block text-sm font-medium text-white mb-2">الخزينة / طريقة الدفع</label>
            <select
              id="edit-expense-account-id"
              name="edit_expense_account_id"
              v-model="editForm.account_id"
              required
              class="w-full bg-input-bg border border-white/10 text-white rounded-xl px-4 py-3 focus:outline-none focus:border-amber-500 transition-colors"
            >
              <option v-for="acc in treasuryAccounts" :key="acc.id" :value="acc.id">
                {{ acc.name }} — ({{ formatCurrency(acc.balance, acc.currency) }})
              </option>
            </select>
          </div>

          <!-- بند المصروف -->
          <div>
            <label for="edit-expense-to-account-id" class="block text-sm font-medium text-white mb-2">بند المصروف (التصنيف)</label>
            <select
              id="edit-expense-to-account-id"
              name="edit_expense_to_account_id"
              v-model="editForm.to_account_id"
              class="w-full bg-input-bg border border-white/10 text-white rounded-xl px-4 py-3 focus:outline-none focus:border-amber-500 transition-colors"
            >
              <option value="" disabled>اختر التصنيف...</option>
              <option v-for="acc in expenseAccounts" :key="acc.id" :value="acc.id">
                {{ acc.name }}
              </option>
            </select>
          </div>

          <!-- التاريخ -->
          <div>
            <label for="edit-expense-date" class="block text-sm font-medium text-white mb-2">تاريخ المصروف</label>
            <input
              id="edit-expense-date"
              name="edit_expense_date"
              type="date"
              v-model="editForm.date"
              class="w-full bg-input-bg border border-white/10 text-white rounded-xl px-4 py-3 focus:outline-none focus:border-amber-500 transition-colors"
            />
          </div>

          <!-- البيان / الملاحظات -->
          <div>
            <label for="edit-expense-notes" class="block text-sm font-medium text-white mb-2">البيان / الوصف</label>
            <textarea
              id="edit-expense-notes"
              name="edit_expense_notes"
              v-model="editForm.notes"
              rows="3"
              class="w-full bg-input-bg border border-white/10 text-white rounded-xl px-4 py-3 focus:outline-none focus:border-amber-500 transition-colors"
              placeholder="اكتب تفاصيل أو سبب التعديل..."
            ></textarea>
          </div>

          <div class="flex gap-3 pt-4">
            <button
              type="button"
              @click="closeEditModal"
              class="flex-1 bg-white/5 hover:bg-white/10 text-white py-3 rounded-xl font-bold transition-colors"
            >
              إلغاء
            </button>
            <button
              type="submit"
              :disabled="isEditing"
              class="flex-1 bg-gradient-to-l from-amber-500 to-amber-600 hover:from-amber-400 hover:to-amber-500 text-white py-3 rounded-xl font-bold shadow-lg shadow-amber-500/20 transition-all flex justify-center items-center gap-2 disabled:opacity-50"
            >
              <span v-if="isEditing" class="w-5 h-5 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
              <span v-else>حفظ التعديلات</span>
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Delete Expense Confirmation Modal -->
    <div v-if="isDeleteModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4">
      <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="closeDeleteModal"></div>
      
      <div class="bg-card-bg border border-white/10 w-full max-w-md rounded-2xl shadow-2xl relative z-10 overflow-hidden">
        <div class="p-6 border-b border-white/10 bg-gradient-to-l from-rose-500/10 to-transparent">
          <div class="flex items-center gap-3">
            <div class="w-12 h-12 rounded-xl bg-rose-500/20 border border-rose-500/30 flex items-center justify-center text-rose-400 shrink-0">
              <Trash2 class="w-6 h-6" />
            </div>
            <div>
              <h3 class="text-lg font-bold text-white">إلغاء وحذف المصروف</h3>
              <p class="text-xs text-text-muted">استرجاع المبلغ للخزينة بقيد عكسي</p>
            </div>
          </div>
        </div>

        <div class="p-6 space-y-4">
          <div v-if="deleteError" class="bg-rose-500/10 border border-rose-500/20 text-rose-400 p-3 rounded-lg text-sm">
            {{ deleteError }}
          </div>

          <div v-if="expenseToDelete" class="bg-white/5 rounded-xl p-4 space-y-2 text-sm border border-white/5">
            <div class="flex justify-between items-center">
              <span class="text-text-muted">المبلغ:</span>
              <span class="font-bold text-rose-400 text-base">
                {{ formatCurrency(expenseToDelete.amount, expenseToDelete.from_account_currency || 'EGP') }}
              </span>
            </div>
            <div class="flex justify-between items-center">
              <span class="text-text-muted">بند المصروف:</span>
              <span class="text-white">{{ expenseToDelete.to_account_name || expenseToDelete.to_account?.name || '---' }}</span>
            </div>
            <div class="flex justify-between items-center">
              <span class="text-text-muted">الخزينة المدفوع منها:</span>
              <span class="text-emerald-400">{{ expenseToDelete.from_account_name || expenseToDelete.from_account?.name || '---' }}</span>
            </div>
            <div class="flex justify-between items-center">
              <span class="text-text-muted">تاريخ التسجيل:</span>
              <span class="text-white font-mono">{{ formatDate(expenseToDelete.created_at) }}</span>
            </div>
            <div v-if="expenseToDelete.notes" class="pt-2 border-t border-white/5 text-xs text-text-muted">
              البيان: {{ expenseToDelete.notes }}
            </div>
          </div>

          <p class="text-xs text-text-muted leading-relaxed">
            هل أنت متأكد من إلغاء هذا المصروف؟ سيقوم النظام بـ
            <strong class="text-emerald-400">رد كامل المبلغ تلقائياً إلى الخزينة</strong>
            بقيد محاسبي عكسي وإلغاء تأثيره من التقارير مع توثيق العملية في سجل التدقيق.
          </p>

          <div class="flex gap-3 pt-2">
            <button
              type="button"
              @click="closeDeleteModal"
              :disabled="isDeleting"
              class="flex-1 bg-white/5 hover:bg-white/10 text-white py-3 rounded-xl font-bold transition-colors"
            >
              تراجع
            </button>
            <button
              type="button"
              @click="confirmDeleteExpense"
              :disabled="isDeleting"
              class="flex-1 bg-gradient-to-l from-rose-500 to-rose-600 hover:from-rose-400 hover:to-rose-500 text-white py-3 rounded-xl font-bold shadow-lg shadow-rose-500/20 transition-all flex justify-center items-center gap-2 disabled:opacity-50"
            >
              <span v-if="isDeleting" class="w-5 h-5 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
              <span v-else>تأكيد الإلغاء ورد المبلغ</span>
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue';
import {
  Banknote,
  Plus,
  Coins,
  PlusCircle,
  ChevronRight,
  ChevronLeft,
  Edit3,
  Trash2,
  AlertTriangle
} from 'lucide-vue-next';
import { useAsyncState } from '@/composables/useAsyncState';
import { useAuthStore } from '@/stores/authStore';
import {
  formatAccountType,
  unwrapAccountItems,
  filterTreasuryAccountsByDivision,
  filterExpenseAccountsByDivision,
  TOURISM_MODULE_TYPES,
  OFFICE_MODULE_TYPES,
  useTreasuryAccountGroups,
} from '@/composables/useTreasuryAccountGroups';
import axios from 'axios';

const authStore = useAuthStore();
const canManageExpenses = computed(() => {
  if (authStore.isAdmin) return true;
  const perms = Array.isArray(authStore.user?.permissions) ? authStore.user.permissions : [];
  return perms.includes('manage_finance');
});

const globalError = ref('');
const successMessage = ref('');

// Edit Modal State
const isEditModalOpen = ref(false);
const isEditing = ref(false);
const editError = ref('');
const editForm = ref({
  id: null,
  amount: '',
  account_id: '',
  to_account_id: '',
  module: 'general',
  notes: '',
  date: '',
  original_amount: 0,
  from_account_name: '',
  from_account_currency: 'EGP'
});

// Delete Modal State
const isDeleteModalOpen = ref(false);
const isDeleting = ref(false);
const deleteError = ref('');
const expenseToDelete = ref(null);

const { state: asyncState, setLoading, setSuccess, setError } = useAsyncState();
const isModalOpen = ref(false);
const isSubmitting = ref(false);

const expenses = ref([]);
const expenseAccounts = ref([]);
const treasuryAccounts = ref([]);
const stats = ref({ thisMonth: 0 });

let fetchController = null;

const pagination = ref({
  current_page: 1,
  last_page: 1,
  total: 0,
  per_page: 20,
  from: 1,
  to: 1
});

const filters = ref({
  expenseAccount: ''
});

const form = ref({
  expense_account_id: '',
  from_account_id: '',
  amount: '',
  notes: '',
  category: 'general',
  module: 'general',
  exchange_rate: 1.0,
  is_custom: false,
  expense_account_name: ''
});

const fromAccount = computed(() => {
  return treasuryAccounts.value.find((acc) => acc.id === form.value.from_account_id) || null;
});

const toAccount = computed(() => {
  return expenseAccounts.value.find((acc) => acc.id === form.value.expense_account_id) || null;
});

const convertedAmount = computed(() => {
  if (form.value.is_custom) return form.value.amount;
  if (!fromAccount.value || !toAccount.value) return 0;
  if (fromAccount.value.currency === toAccount.value.currency) {
    return form.value.amount;
  }
  return form.value.amount * (form.value.exchange_rate || 1.0);
});

const preferredTreasuryModuleKeys = computed(() => {
  if (form.value.category === 'general') {
    return ['general', 'office'];
  }
  if (form.value.category === 'tourism') {
    return [...TOURISM_MODULE_TYPES];
  }
  if (form.value.category === 'office') {
    return [...OFFICE_MODULE_TYPES];
  }
  return [];
});

const scopedTreasuryAccounts = computed(() =>
  filterTreasuryAccountsByDivision(treasuryAccounts.value, form.value.category)
);

const treasuryAccountGroups = useTreasuryAccountGroups(
  scopedTreasuryAccounts,
  preferredTreasuryModuleKeys
);

const updateModuleSelection = () => {
  if (form.value.category === 'general') {
    form.value.module = 'general';
  } else if (form.value.category === 'tourism') {
    form.value.module = 'tourism';
  } else if (form.value.category === 'office') {
    form.value.module = 'office';
  }
  form.value.expense_account_id = '';
  form.value.from_account_id = '';
  form.value.exchange_rate = 1.0;
  form.value.is_custom = false;
  form.value.expense_account_name = '';
};

const selectableExpenseAccounts = computed(() =>
  filterExpenseAccountsByDivision(expenseAccounts.value, form.value.category)
);

const filteredExpenses = computed(() => {
  return expenses.value;
});

const toggleCustomExpenseAccount = () => {
  form.value.is_custom = !form.value.is_custom;
  if (form.value.is_custom) {
    form.value.expense_account_id = '';
  } else {
    form.value.expense_account_name = '';
  }
};

watch(
  () => form.value.module,
  () => {
    form.value.expense_account_id = '';
    form.value.exchange_rate = 1.0;
    form.value.is_custom = false;
    form.value.expense_account_name = '';

    const stillValidTreasury = scopedTreasuryAccounts.value.some(
      (acc) => acc.id === form.value.from_account_id
    );
    if (!stillValidTreasury) {
      form.value.from_account_id = '';
    }
  }
);

watch(
  [() => form.value.from_account_id, () => form.value.expense_account_id],
  () => {
    if (fromAccount.value && toAccount.value) {
      if (fromAccount.value.currency === toAccount.value.currency) {
        form.value.exchange_rate = 1.0;
      }
    }
  }
);

// No attachment needed anymore

const openExpenseModal = () => {
  form.value = {
    expense_account_id: '',
    from_account_id: '',
    amount: '',
    notes: '',
    category: 'general',
    module: 'general',
    exchange_rate: 1.0,
    is_custom: false,
    expense_account_name: ''
  };
  isModalOpen.value = true;
};

const closeExpenseModal = () => {
  isModalOpen.value = false;
};

// Formatting utilities
const formatCurrency = (val, currency = 'EGP') => {
  if (!val && val !== 0) return `0.00 ${currency}`;
  return parseFloat(val).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ` ${currency}`;
};

const formatDate = (dateStr) => {
  if (!dateStr) return '';
  return new Date(dateStr).toLocaleDateString('ar-EG');
};

const formatTime = (dateStr) => {
  if (!dateStr) return '';
  return new Date(dateStr).toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' });
};

const formatModule = (mod) => {
  const map = {
    'general': 'مصروفات عامة',
    'tourism': 'عام السياحة',
    'office': 'عام المكتب',
    'flight': 'قسم الطيران',
    'hajj_umra': 'قسم الحج والعمرة',
    'visa': 'التأشيرات',
    'bus': 'قسم الباص',
    'fawry': 'فوري',
    'online': 'الخدمات الإلكترونية'
  };
  return map[mod] || 'غير محدد';
};

const unwrapItems = unwrapAccountItems;

const fetchAccounts = async () => {
  try {
    const [expenseRes, treasuriesRes] = await Promise.all([
      axios.get('/api/v1/finance/accounts', { params: { type: 'expense', is_active: true, per_page: 100 } }),
      axios.get('/api/v1/finance/accounts', { params: { types: 'cashbox,bank,treasury,wallet,post', is_active: true, per_page: 100 } }),
    ]);
    
    expenseAccounts.value = unwrapItems(expenseRes.data?.data);
    treasuryAccounts.value = unwrapItems(treasuriesRes.data?.data);
  } catch (error) {
    console.error('Error fetching accounts data', error);
  }
};

const fetchTransactions = async (page = 1) => {
  if (fetchController) {
    fetchController.abort();
  }
  fetchController = new AbortController();
  const { signal } = fetchController;

  try {
    setLoading();

    const params = {
      expenses_only: 1,
      per_page: pagination.value.per_page,
      page,
      _t: Date.now(),
    };

    if (filters.value.expenseAccount) {
      params.account_id = filters.value.expenseAccount;
    }

    const now = new Date();
    const fromDate = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-01`;

    const [transactionsRes, summaryRes] = await Promise.all([
      axios.get('/api/v1/reports/transactions', { params, signal }),
      axios.get('/api/v1/reports/financial/summary', {
        params: {
          from_date: fromDate,
          expense_scope: 'operating',
          _t: Date.now(),
        },
        signal,
      }),
    ]);

    const resData = transactionsRes.data?.data;

    expenses.value = unwrapItems(resData);

    if (resData?.pagination) {
      pagination.value = {
        current_page: resData.pagination.current_page || 1,
        last_page: resData.pagination.last_page || 1,
        total: resData.pagination.total || 0,
        per_page: resData.pagination.per_page || 20,
        from: (resData.pagination.current_page - 1) * resData.pagination.per_page + 1,
        to: Math.min(resData.pagination.current_page * resData.pagination.per_page, resData.pagination.total),
      };
    } else {
      pagination.value = {
        current_page: 1,
        last_page: 1,
        total: expenses.value.length,
        per_page: 20,
        from: 1,
        to: expenses.value.length,
      };
    }

    stats.value.thisMonth = parseFloat(summaryRes.data?.data?.total_expense || 0);

    setSuccess(expenses.value.length === 0);
  } catch (error) {
    if (axios.isCancel?.(error) || error?.code === 'ERR_CANCELED') {
      return;
    }
    console.error('Error fetching transactions history', error);
    setError(error);
  }
};

const changePage = (page) => {
  if (page < 1 || page > pagination.value.last_page) return;
  fetchTransactions(page);
};

// Watch category filter change
watch(
  () => filters.value.expenseAccount,
  () => {
    fetchTransactions(1);
  }
);

const submitExpense = async () => {
  if (isSubmitting.value) return;
  isSubmitting.value = true;

  try {
    const payload = {
      from_account_id: form.value.from_account_id,
      amount: form.value.amount,
      notes: form.value.notes,
      type: 'expense',
      module: form.value.module,
    };

    if (form.value.is_custom) {
      payload.to_account_name = form.value.expense_account_name;
    } else {
      payload.to_account_id = form.value.expense_account_id;
    }

    if (!form.value.is_custom && fromAccount.value && toAccount.value && fromAccount.value.currency !== toAccount.value.currency) {
      payload.exchange_rate = form.value.exchange_rate;
      payload.converted_amount = convertedAmount.value;
    }

    await axios.post('/api/v1/finance/transfers', payload);

    successMessage.value = 'تم تسجيل المصروف والخصم من الخزينة بنجاح';
    globalError.value = '';
    closeExpenseModal();
    await fetchAccounts();
    fetchTransactions(1);
  } catch (error) {
    const msg = error.response?.data?.message || 'حدث خطأ أثناء التسجيل';
    globalError.value = msg;
  } finally {
    isSubmitting.value = false;
  }
};

const notify = (message, type = 'success') => {
  if (typeof window !== 'undefined') {
    if (typeof window.addToast === 'function') {
      window.addToast(message, type);
    } else {
      window.dispatchEvent(new CustomEvent('show-toast', { detail: { message, type } }));
    }
  }
};

const openEditModal = (expense) => {
  editError.value = '';
  editForm.value = {
    id: expense.id,
    amount: parseFloat(expense.amount) || '',
    account_id: expense.from_account_id,
    to_account_id: expense.to_account_id || '',
    module: expense.module || 'general',
    notes: expense.notes || '',
    date: expense.created_at ? expense.created_at.substring(0, 10) : '',
    original_amount: parseFloat(expense.amount) || 0,
    from_account_name: expense.from_account_name || expense.from_account?.name || '',
    from_account_currency: expense.from_account_currency || 'EGP'
  };
  isEditModalOpen.value = true;
};

const closeEditModal = () => {
  isEditModalOpen.value = false;
  editError.value = '';
};

const submitEditExpense = async () => {
  if (isEditing.value) return;
  isEditing.value = true;
  editError.value = '';

  try {
    const payload = {
      amount: editForm.value.amount,
      account_id: editForm.value.account_id,
      to_account_id: editForm.value.to_account_id || undefined,
      notes: editForm.value.notes,
      module: editForm.value.module,
      date: editForm.value.date || undefined,
    };

    const res = await axios.put(`/api/v1/finance/transactions/${editForm.value.id}`, payload);

    notify(res.data?.message || 'تم تحديث المصروف وإعادة احتساب الأرصدة بنجاح', 'success');
    closeEditModal();
    await fetchAccounts();
    fetchTransactions(pagination.value.current_page);
  } catch (error) {
    const msg = error.response?.data?.message || 'حدث خطأ أثناء تعديل المصروف';
    editError.value = msg;
    notify(msg, 'error');
  } finally {
    isEditing.value = false;
  }
};

const openDeleteModal = (expense) => {
  expenseToDelete.value = expense;
  deleteError.value = '';
  isDeleteModalOpen.value = true;
};

const closeDeleteModal = () => {
  isDeleteModalOpen.value = false;
  expenseToDelete.value = null;
  deleteError.value = '';
};

const confirmDeleteExpense = async () => {
  if (!expenseToDelete.value || isDeleting.value) return;
  isDeleting.value = true;
  deleteError.value = '';

  try {
    const res = await axios.delete(`/api/v1/finance/transactions/${expenseToDelete.value.id}`);
    notify(res.data?.message || 'تم حذف المصروف وإعادة المبلغ للخزينة بنجاح', 'success');
    closeDeleteModal();
    await fetchAccounts();
    fetchTransactions(pagination.value.current_page);
  } catch (error) {
    const msg = error.response?.data?.message || 'حدث خطأ أثناء حذف المصروف';
    deleteError.value = msg;
    notify(msg, 'error');
  } finally {
    isDeleting.value = false;
  }
};

onMounted(async () => {
  await fetchAccounts();
  await fetchTransactions(1);
});

onBeforeUnmount(() => {
  if (fetchController) {
    fetchController.abort();
  }
});
</script>
