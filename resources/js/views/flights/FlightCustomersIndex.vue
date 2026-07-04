<template>
  <div class="page-wrapper" dir="rtl">

    <!-- ============================================================ -->
    <!-- HEADER                                                        -->
    <!-- ============================================================ -->
    <div class="page-header">
      <div class="header-info">
        <div class="header-icon">
          <Plane class="w-6 h-6" />
        </div>
        <div>
          <h1 class="page-title">عملاء ومجموعات الطيران</h1>
          <p class="page-subtitle">إدارة عملاء الكوانتر، الشركات، والمجموعات — ومتابعة ديونهم</p>
        </div>
      </div>
      <button
        v-if="activeTab !== 'group'"
        @click="openCreateModal"
        class="btn-primary"
      >
        <Plus class="w-4 h-4" />
        <span>إضافة عميل جديد</span>
      </button>
    </div>

    <!-- ============================================================ -->
    <!-- STATS CARDS                                                   -->
    <!-- ============================================================ -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-icon stat-icon--blue">
          <Users class="w-5 h-5" />
        </div>
        <div class="stat-body">
          <span class="stat-label">عملاء الكوانتر</span>
          <span class="stat-value">{{ stats.counterCount }}</span>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon stat-icon--purple">
          <Building2 class="w-5 h-5" />
        </div>
        <div class="stat-body">
          <span class="stat-label">عملاء الشركات</span>
          <span class="stat-value">{{ stats.companiesCount }}</span>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon stat-icon--sky">
          <LayoutGrid class="w-5 h-5" />
        </div>
        <div class="stat-body">
          <span class="stat-label">المجموعات</span>
          <span class="stat-value">{{ stats.groupsCount }}</span>
        </div>
      </div>
      <div class="stat-card stat-card--alert">
        <div class="stat-icon stat-icon--red">
          <DollarSign class="w-5 h-5" />
        </div>
        <div class="stat-body">
          <span class="stat-label">إجمالي الديون المستحقة لنا</span>
          <span class="stat-value text-error">{{ formatCurrency(stats.totalDebt) }}</span>
        </div>
      </div>
    </div>

    <!-- ============================================================ -->
    <!-- TABS + CONTENT CARD                                          -->
    <!-- ============================================================ -->
    <div class="content-card">

      <!-- Tab Navigation -->
      <div class="tab-nav">
        <button @click="changeTab('regular')" :class="['tab-btn', activeTab === 'regular' ? 'tab-btn--active' : '']">
          <Users class="w-4 h-4" />
          <span>عملاء الكوانتر</span>
          <span v-if="activeTab === 'regular' && !loadingList" class="tab-count">{{ pagination.total }}</span>
        </button>
        <button @click="changeTab('counter')" :class="['tab-btn', activeTab === 'counter' ? 'tab-btn--active tab-btn--purple' : '']">
          <Building2 class="w-4 h-4" />
          <span>عملاء الشركات</span>
          <span v-if="activeTab === 'counter' && !loadingList" class="tab-count tab-count--purple">{{ pagination.total }}</span>
        </button>
        <button @click="changeTab('group')" :class="['tab-btn', activeTab === 'group' ? 'tab-btn--active tab-btn--sky' : '']">
          <LayoutGrid class="w-4 h-4" />
          <span>المجموعات</span>
          <span v-if="activeTab === 'group' && !loadingList" class="tab-count tab-count--sky">{{ customers.length }}</span>
        </button>
      </div>

      <!-- Search + Balance Filter -->
      <div class="search-row">
        <div class="search-box">
          <Search class="search-icon w-4 h-4" />
          <input
            v-model="searchQuery"
            type="text"
            :placeholder="searchPlaceholder"
            class="search-input"
            @input="onSearch"
          />
        </div>
        <div class="balance-filter-chips">
          <button
            v-for="chip in balanceFilterOptions"
            :key="chip.value"
            type="button"
            @click="setBalanceFilter(chip.value)"
            :class="['balance-filter-chip', balanceFilter === chip.value ? 'balance-filter-chip--active' : '']"
          >
            {{ chip.label }}
          </button>
        </div>
      </div>

      <!-- ========================= -->
      <!-- TAB 1: عملاء الكوانتر     -->
      <!-- ========================= -->
      <div v-if="activeTab === 'regular'">
        <div v-if="loadingList" class="loading-state">
          <div class="spinner"></div>
          <span>جاري تحميل البيانات...</span>
        </div>
        <div v-else-if="customers.length === 0" class="empty-state">
          <Users class="w-12 h-12 text-muted/20" />
          <p class="empty-title">{{ balanceFilter === 'outstanding' ? 'لا يوجد عملاء مديونين' : balanceFilter === 'settled' ? 'لا يوجد عملاء مسددين' : 'لا يوجد عملاء' }}</p>
          <p class="empty-sub">{{ balanceFilter !== 'all' ? 'جرّب تغيير الفلتر أو البحث' : 'لم يتم العثور على أي عملاء كوانتر' }}</p>
        </div>
        <div v-else class="table-wrapper">
          <table class="data-table">
            <thead>
              <tr>
                <th>العميل</th>
                <th>رقم الهاتف</th>
                <th>الرقم القومي</th>
                <th>المدينة / دولة السفر</th>
                <th>الجهة التابع لها</th>
                <th>رصيد الحساب</th>
                <th class="text-left">الإجراءات</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="customer in customers" :key="customer.id" class="data-row">
                <!-- Name -->
                <td>
                  <div class="customer-cell">
                    <div class="avatar avatar--blue">{{ getInitials(customer.full_name || customer.name) }}</div>
                    <div>
                      <div class="customer-name">{{ customer.full_name || customer.name }}</div>
                      <div v-if="customer.notes" class="customer-note" :title="customer.notes">{{ customer.notes }}</div>
                    </div>
                  </div>
                </td>
                <!-- Phone -->
                <td>
                  <div class="flex items-center gap-2">
                    <span class="mono-text">{{ customer.phone || '—' }}</span>
                    <a
                      v-if="customer.phone || customer.whatsapp_number"
                      :href="'https://wa.me/' + formatWhatsApp(customer.whatsapp_number || customer.phone)"
                      target="_blank"
                      class="whatsapp-btn"
                      title="تواصل واتساب"
                    >
                      <MessageSquare class="w-3.5 h-3.5" />
                    </a>
                  </div>
                </td>
                <!-- National ID -->
                <td><span class="mono-text muted">{{ customer.national_id || '—' }}</span></td>
                <!-- City / Country -->
                <td>
                  <div class="flex flex-col gap-0.5 text-xs">
                    <span v-if="customer.city" class="flex items-center gap-1 text-muted">
                      <MapPin class="w-3 h-3" />{{ customer.city }}
                    </span>
                    <span v-if="customer.travel_country" class="flex items-center gap-1 text-sky-400">
                      <Globe2 class="w-3 h-3" />{{ customer.travel_country }}
                    </span>
                    <span v-if="!customer.city && !customer.travel_country" class="text-muted/40">—</span>
                  </div>
                </td>
                <!-- Affiliation -->
                <td><span class="text-xs text-muted">{{ customer.affiliation || '—' }}</span></td>
                <!-- Balance -->
                <td @click="openPayDebtModal(customer)" class="balance-cell" title="اضغط لتسجيل سند قبض">
                  <div class="flex flex-col">
                    <span :class="['font-mono text-sm', formatBalance(customer.balance, 'customer').class]">
                      {{ formatBalance(customer.balance, 'customer').text }}
                      <span class="text-[11px] font-sans mr-1" v-if="formatBalance(customer.balance, 'customer').label">
                        {{ formatBalance(customer.balance, 'customer').label }}
                      </span>
                    </span>
                  </div>
                </td>
                <!-- Actions -->
                <td class="text-left">
                  <div class="action-btns">
                    <button @click="openPayDebtModal(customer)" class="action-btn action-btn--green" title="تسجيل سند قبض">
                      <DollarSign class="w-3.5 h-3.5" />
                    </button>
                    <button @click="viewCustomerStatement(customer)" class="action-btn action-btn--blue" title="كشف الحساب">
                      <Eye class="w-3.5 h-3.5" />
                    </button>
                    <button @click="editCustomer(customer)" class="action-btn action-btn--gold" title="تعديل">
                      <Pen class="w-3.5 h-3.5" />
                    </button>
                    <button @click="deleteCustomer(customer)" class="action-btn action-btn--red" title="حذف">
                      <Trash2 class="w-3.5 h-3.5" />
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <!-- Pagination -->
        <div v-if="!loadingList && pagination.lastPage > 1" class="pagination">
          <span class="pagination-info">عرض {{ customers.length }} من {{ pagination.total }} عميل</span>
          <div class="pagination-btns">
            <button :disabled="pagination.currentPage === 1" @click="changePage(pagination.currentPage - 1)" class="page-btn">السابق</button>
            <span class="page-current">الصفحة {{ pagination.currentPage }} من {{ pagination.lastPage }}</span>
            <button :disabled="pagination.currentPage === pagination.lastPage" @click="changePage(pagination.currentPage + 1)" class="page-btn">التالي</button>
          </div>
        </div>
      </div>

      <!-- ========================= -->
      <!-- TAB 2: عملاء الشركات     -->
      <!-- ========================= -->
      <div v-if="activeTab === 'counter'">
        <div v-if="loadingList" class="loading-state">
          <div class="spinner spinner--purple"></div>
          <span>جاري تحميل الشركات...</span>
        </div>
        <div v-else-if="customers.length === 0" class="empty-state">
          <Building2 class="w-12 h-12 text-muted/20" />
          <p class="empty-title">{{ balanceFilter === 'outstanding' ? 'لا يوجد شركات مديونة' : balanceFilter === 'settled' ? 'لا يوجد شركات مسددة' : 'لا يوجد عملاء شركات' }}</p>
          <p class="empty-sub">{{ balanceFilter !== 'all' ? 'جرّب تغيير الفلتر أو البحث' : 'أضف عملاء الشركات أو مكاتب الطيران هنا' }}</p>
        </div>
        <div v-else class="table-wrapper">
          <table class="data-table">
            <thead>
              <tr>
                <th>عميل الشركة</th>
                <th>رقم الهاتف</th>
                <th>المدينة</th>
                <th>رصيد الحساب</th>
                <th class="text-left">الإجراءات</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="customer in customers" :key="customer.id" class="data-row">
                <!-- Name -->
                <td>
                  <div class="customer-cell">
                    <div class="avatar avatar--purple">{{ getInitials(customer.full_name || customer.name) }}</div>
                    <div>
                      <div class="customer-name">{{ customer.full_name || customer.name }}</div>
                      <div v-if="customer.notes" class="customer-note" :title="customer.notes">{{ customer.notes }}</div>
                    </div>
                  </div>
                </td>
                <!-- Phone -->
                <td>
                  <div class="flex items-center gap-2">
                    <span class="mono-text">{{ customer.phone || '—' }}</span>
                    <a
                      v-if="customer.phone || customer.whatsapp_number"
                      :href="'https://wa.me/' + formatWhatsApp(customer.whatsapp_number || customer.phone)"
                      target="_blank"
                      class="whatsapp-btn"
                    >
                      <MessageSquare class="w-3.5 h-3.5" />
                    </a>
                  </div>
                </td>
                <!-- City -->
                <td>
                  <span v-if="customer.city" class="flex items-center gap-1 text-xs text-muted">
                    <MapPin class="w-3 h-3" />{{ customer.city }}
                  </span>
                  <span v-else class="text-muted/40">—</span>
                </td>
                <!-- Balance -->
                <td @click="openPayDebtModal(customer)" class="balance-cell" title="اضغط لتسجيل سند قبض">
                  <div class="flex flex-col">
                    <span :class="['font-mono text-sm', formatBalance(customer.balance, 'customer').class]">
                      {{ formatBalance(customer.balance, 'customer').text }}
                      <span class="text-[11px] font-sans mr-1" v-if="formatBalance(customer.balance, 'customer').label">
                        {{ formatBalance(customer.balance, 'customer').label }}
                      </span>
                    </span>
                  </div>
                </td>
                <!-- Actions -->
                <td class="text-left">
                  <div class="action-btns">
                    <button @click="openPayDebtModal(customer)" class="action-btn action-btn--green" title="تسجيل سند قبض">
                      <DollarSign class="w-3.5 h-3.5" />
                    </button>
                    <button @click="viewCustomerStatement(customer)" class="action-btn action-btn--blue" title="كشف الحساب">
                      <Eye class="w-3.5 h-3.5" />
                    </button>
                    <button @click="editCustomer(customer)" class="action-btn action-btn--gold" title="تعديل">
                      <Pen class="w-3.5 h-3.5" />
                    </button>
                    <button @click="deleteCustomer(customer)" class="action-btn action-btn--red" title="حذف">
                      <Trash2 class="w-3.5 h-3.5" />
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <!-- Pagination -->
        <div v-if="!loadingList && pagination.lastPage > 1" class="pagination">
          <span class="pagination-info">عرض {{ customers.length }} من {{ pagination.total }} عميل</span>
          <div class="pagination-btns">
            <button :disabled="pagination.currentPage === 1" @click="changePage(pagination.currentPage - 1)" class="page-btn">السابق</button>
            <span class="page-current">الصفحة {{ pagination.currentPage }} من {{ pagination.lastPage }}</span>
            <button :disabled="pagination.currentPage === pagination.lastPage" @click="changePage(pagination.currentPage + 1)" class="page-btn">التالي</button>
          </div>
        </div>
      </div>

      <!-- ========================= -->
      <!-- TAB 3: المجموعات          -->
      <!-- ========================= -->
      <div v-if="activeTab === 'group'">
        <div v-if="loadingList" class="loading-state">
          <div class="spinner spinner--sky"></div>
          <span>جاري تحميل المجموعات...</span>
        </div>
        <div v-else-if="customers.length === 0" class="empty-state">
          <LayoutGrid class="w-12 h-12 text-muted/20" />
          <p class="empty-title">{{ balanceFilter === 'outstanding' ? 'لا توجد مجموعات عليها مديونية' : balanceFilter === 'settled' ? 'لا توجد مجموعات مسددة' : 'لا توجد مجموعات' }}</p>
          <p class="empty-sub">{{ balanceFilter !== 'all' ? 'جرّب تغيير الفلتر أو البحث' : 'لم يتم العثور على مجموعات طيران مسجلة' }}</p>
        </div>
        <div v-else class="table-wrapper">
          <table class="data-table">
            <thead>
              <tr>
                <th>اسم المجموعة</th>
                <th>مسؤول التواصل</th>
                <th>شركة الطيران</th>
                <th>المديونية للمجموعة</th>
                <th class="text-left">الإجراءات</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="group in customers" :key="group.id" class="data-row">
                <!-- Name -->
                <td>
                  <div class="customer-cell">
                    <div class="avatar avatar--sky">{{ getInitials(group.name) }}</div>
                    <div>
                      <div class="customer-name">{{ group.name }}</div>
                      <div v-if="group.code" class="customer-note font-mono">كود: {{ group.code }}</div>
                    </div>
                  </div>
                </td>
                <!-- Contact -->
                <td>
                  <div class="flex flex-col gap-0.5">
                    <span class="text-sm text-white">{{ group.contact_person || '—' }}</span>
                    <span class="mono-text muted text-xs">{{ group.contact_phone || '' }}</span>
                  </div>
                </td>
                <!-- Carrier -->
                <td>
                  <div v-if="group.carrier" class="flex items-center gap-1.5 text-sm text-sky-400">
                    <Plane class="w-3.5 h-3.5" />
                    <span>{{ group.carrier.name }} <span class="mono-text text-xs">({{ group.carrier.code }})</span></span>
                  </div>
                  <span v-else class="text-muted/40">—</span>
                </td>
                <!-- Balance — للمجموعة = مديونية نحن ندين لهم -->
                <td @click="openPayDebtModal(group)" class="balance-cell" title="اضغط لتسجيل سند صرف (دفع للمجموعة)">
                  <div class="flex flex-col">
                    <span :class="['font-mono text-sm', formatBalance(group.balance, 'group', group.carrier?.currency).class]">
                      {{ formatBalance(group.balance, 'group', group.carrier?.currency).text }}
                      <span class="text-[11px] font-sans mr-1" v-if="formatBalance(group.balance, 'group', group.carrier?.currency).label">
                        {{ formatBalance(group.balance, 'group', group.carrier?.currency).label }}
                      </span>
                    </span>
                  </div>
                </td>
                <!-- Actions -->
                <td class="text-left">
                  <div class="action-btns">
                    <button @click="openPayDebtModal(group)" class="action-btn action-btn--green" title="تسجيل سند صرف — دفع للمجموعة">
                      <DollarSign class="w-3.5 h-3.5" />
                    </button>
                    <button @click="viewCustomerStatement(group)" class="action-btn action-btn--blue" title="كشف حساب المجموعة">
                      <Eye class="w-3.5 h-3.5" />
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <!-- Groups Total -->
        <div v-if="!loadingList && customers.length > 0" class="groups-total">
          <span class="text-sm text-muted font-bold">إجمالي المديونيات للمجموعات:</span>
          <span class="mono-text text-error font-black">
            {{ formatCurrency(customers.filter(g => Number(g.balance || 0) > 0).reduce((s, g) => s + Number(g.balance || 0), 0)) }}
          </span>
        </div>
      </div>
    </div>

    <!-- ============================================================ -->
    <!-- MODAL: Create / Edit Customer                                 -->
    <!-- ============================================================ -->
    <div v-if="showModal" class="modal-overlay" @click.self="closeModal">
      <div class="modal-box">
        <div class="modal-header">
          <h2 class="modal-title">
            {{ isEditMode ? 'تعديل بيانات العميل' : (activeTab === 'counter' ? 'إضافة عميل شركة جديد' : 'إضافة عميل كوانتر جديد') }}
          </h2>
          <button @click="closeModal" class="modal-close"><X class="w-5 h-5" /></button>
        </div>
        <div class="modal-body">
          <div class="form-grid">
            <div class="form-field">
              <label class="form-label">{{ activeTab === 'counter' ? 'اسم الشركة / العميل *' : 'الاسم بالكامل *' }}</label>
              <input v-model="form.full_name" type="text" class="form-input" :placeholder="activeTab === 'counter' ? 'مثال: شركة النور' : 'مثال: أحمد محمد علي'" />
            </div>
            <div class="form-field">
              <label class="form-label">رقم الهاتف *</label>
              <input
                v-model="form.phone"
                type="text"
                inputmode="numeric"
                maxlength="11"
                dir="ltr"
                placeholder="01xxxxxxxxx"
                class="form-input"
                :class="phoneError ? 'border-red-500/70 !border-red-500/70' : ''"
                @input="onPhoneInput"
                @blur="onPhoneBlur"
              />
              <p v-if="phoneError" class="mt-1 text-xs text-red-400">{{ phoneError }}</p>
            </div>
            <div v-if="activeTab === 'regular'" class="form-field">
              <label class="form-label">الرقم القومي</label>
              <input v-model="form.national_id" type="text" maxlength="14" class="form-input" dir="ltr" placeholder="290xxxxxxxxxxx" />
            </div>
            <div class="form-field">
              <label class="form-label">رقم الواتساب</label>
              <input
                v-model="form.whatsapp_number"
                type="text"
                inputmode="numeric"
                maxlength="11"
                dir="ltr"
                placeholder="01xxxxxxxxx"
                class="form-input"
                :class="whatsappError ? 'border-red-500/70 !border-red-500/70' : ''"
                @input="onWhatsappInput"
                @blur="onWhatsappBlur"
              />
              <p v-if="whatsappError" class="mt-1 text-xs text-red-400">{{ whatsappError }}</p>
            </div>
            <div class="form-field">
              <label class="form-label">المدينة</label>
              <input v-model="form.city" type="text" class="form-input" placeholder="القاهرة" />
            </div>
            <div class="form-field">
              <label class="form-label">دولة السفر</label>
              <input v-model="form.travel_country" type="text" class="form-input" placeholder="المملكة العربية السعودية" />
            </div>
            <div v-if="activeTab === 'regular'" class="form-field col-span-2">
              <label class="form-label">الجهة التابع لها</label>
              <input v-model="form.affiliation" type="text" class="form-input" placeholder="الشركة أو المؤسسة" />
            </div>
            <div class="form-field col-span-2">
              <label class="form-label">ملاحظات</label>
              <textarea v-model="form.notes" rows="3" class="form-input resize-none" placeholder="أي تفاصيل إضافية..."></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button @click="closeModal" class="btn-secondary">إلغاء</button>
          <button @click="saveCustomer" :disabled="saving" class="btn-primary">
            <Check class="w-4 h-4" v-if="!saving" />
            <div v-else class="btn-spinner"></div>
            {{ saving ? 'جاري الحفظ...' : 'حفظ العميل' }}
          </button>
        </div>
      </div>
    </div>

    <!-- ============================================================ -->
    <!-- MODAL: Customer Statement                                     -->
    <!-- ============================================================ -->
    <div v-if="showStatementModal" class="modal-overlay modal-overlay--lg" dir="rtl">
      <div class="modal-box modal-box--xl print-statement-area">
        <!-- Header -->
        <div class="modal-header print:hidden">
          <div class="flex items-center gap-3">
            <div class="stat-icon stat-icon--blue"><FileText class="w-5 h-5" /></div>
            <div>
              <h3 class="modal-title">
                {{ selectedCustomerForStatement?.is_group_flag ? 'كشف حساب المجموعة' : 'كشف حساب العميل والعمليات' }}
              </h3>
              <p class="text-xs text-muted mt-0.5 font-mono">
                {{ selectedCustomerForStatement?.name || selectedCustomerForStatement?.full_name }}
                <span class="mx-2 opacity-30">|</span>
                <span dir="ltr">{{ selectedCustomerForStatement?.phone || selectedCustomerForStatement?.contact_phone || '' }}</span>
              </p>
            </div>
          </div>
          <div class="flex items-center gap-2">
            <button @click="openPayDebtModal(selectedCustomerForStatement)" class="btn-sm btn-sm--green">
              <DollarSign class="w-3.5 h-3.5" />
              {{ selectedCustomerForStatement?.is_group_flag ? 'سند صرف' : 'سند قبض' }}
            </button>
            <button @click="printStatement" class="btn-sm btn-sm--gold">
              <Printer class="w-3.5 h-3.5" />
              طباعة الكشف
            </button>
            <button @click="closeStatementModal" class="modal-close"><X class="w-5 h-5" /></button>
          </div>
        </div>

        <div class="modal-scroll">
          <!-- Print Header -->
          <div class="print-header hidden print:flex">
            <div>
              <h1 class="text-2xl font-black">سفرك علينا للسياحة والسفر</h1>
              <p class="text-sm mt-1">كشف حساب | {{ selectedCustomerForStatement?.name || selectedCustomerForStatement?.full_name }}</p>
            </div>
            <div class="text-xs text-left" dir="ltr">
              <p>تاريخ الطباعة: {{ new Date().toLocaleDateString('ar-EG') }}</p>
            </div>
          </div>

          <!-- Customer Profile Card -->
          <div class="profile-card print:hidden">
            <div class="flex items-center gap-4">
              <div :class="['avatar avatar--lg', selectedCustomerForStatement?.is_group_flag ? 'avatar--sky' : (activeTab === 'counter' ? 'avatar--purple' : 'avatar--blue')]">
                {{ getInitials(selectedCustomerForStatement?.name || selectedCustomerForStatement?.full_name) }}
              </div>
              <div>
                <div class="flex items-center gap-2 flex-wrap">
                  <h2 class="text-xl font-black text-white">{{ selectedCustomerForStatement?.name || selectedCustomerForStatement?.full_name }}</h2>
                  <span :class="['badge', selectedCustomerForStatement?.is_group_flag ? 'badge--sky' : (activeTab === 'counter' ? 'badge--purple' : 'badge--blue')]">
                    {{ selectedCustomerForStatement?.is_group_flag ? 'مجموعة طيران' : (activeTab === 'counter' ? 'عميل شركة' : 'عميل كوانتر') }}
                  </span>
                </div>
                <div class="flex flex-wrap gap-4 mt-2 text-xs text-muted">
                  <span v-if="selectedCustomerForStatement?.phone || selectedCustomerForStatement?.contact_phone" class="flex items-center gap-1 font-mono">
                    <Phone class="w-3 h-3" />
                    <span dir="ltr">{{ selectedCustomerForStatement?.phone || selectedCustomerForStatement?.contact_phone }}</span>
                  </span>
                  <span v-if="selectedCustomerForStatement?.national_id" class="flex items-center gap-1">
                    <User class="w-3 h-3" />الرقم القومي: {{ selectedCustomerForStatement.national_id }}
                  </span>
                  <span v-if="selectedCustomerForStatement?.city" class="flex items-center gap-1">
                    <MapPin class="w-3 h-3" />{{ selectedCustomerForStatement.city }}
                  </span>
                </div>
              </div>
            </div>
          </div>

          <!-- Statement Stats -->
          <div class="stmt-stats">
            <div class="stmt-stat">
              <span class="stmt-stat-label">الرصيد الافتتاحي</span>
              <span class="stmt-stat-val">{{ formatCurrency(statementStats.opening_balance) }}</span>
            </div>
            <div class="stmt-stat stmt-stat--red">
              <span class="stmt-stat-label text-error">
                {{ selectedCustomerForStatement?.is_group_flag ? 'مشتريات بالأجل (مدين علينا)' : 'إجمالي الحجوزات والمسحوبات' }}
              </span>
              <span class="stmt-stat-val text-error">{{ formatCurrency(selectedCustomerForStatement?.is_group_flag ? statementStats.period_debit : statementStats.period_credit) }}</span>
            </div>
            <div class="stmt-stat stmt-stat--green">
              <span class="stmt-stat-label text-success">
                {{ selectedCustomerForStatement?.is_group_flag ? 'مدفوعاتنا لهم' : 'إجمالي المدفوعات المستلمة' }}
              </span>
              <span class="stmt-stat-val text-success">{{ formatCurrency(selectedCustomerForStatement?.is_group_flag ? statementStats.period_credit : statementStats.period_debit) }}</span>
            </div>
            <div class="stmt-stat stmt-stat--gold">
              <span class="stmt-stat-label text-gold">صافي الرصيد الحالي</span>
              <span class="stmt-stat-val" :class="statementStats.closing_balance > 0 ? 'text-error' : (statementStats.closing_balance < 0 ? 'text-success' : 'text-white')">
                {{ formatCurrency(Math.abs(statementStats.closing_balance)) }}
                <span class="text-xs font-sans mr-1">
                  <template v-if="selectedCustomerForStatement?.is_group_flag">{{ statementStats.closing_balance > 0 ? '(مستحق لهم)' : (statementStats.closing_balance < 0 ? '(مستحق لنا)' : '') }}</template>
                  <template v-else>{{ statementStats.closing_balance > 0 ? '(مدين — عليه)' : (statementStats.closing_balance < 0 ? '(دائن — له)' : '') }}</template>
                </span>
              </span>
            </div>
          </div>

          <!-- Statement Sub-Tabs (customers only) -->
          <div v-if="!selectedCustomerForStatement?.is_group_flag" class="sub-tab-nav print:hidden">
            <button @click="activeStatementTab = 'ledger'" :class="['sub-tab', activeStatementTab === 'ledger' ? 'sub-tab--active' : '']">
              سجل العمليات (كشف الحساب)
            </button>
            <button @click="activeStatementTab = 'bookings'" :class="['sub-tab', activeStatementTab === 'bookings' ? 'sub-tab--active' : '']">
              حجوزات العميل
              <span v-if="customerBookings.filter(b => parseFloat(b.remaining || 0) > 0).length > 0" class="badge-count">
                {{ customerBookings.filter(b => parseFloat(b.remaining || 0) > 0).length }}
              </span>
            </button>
            <button @click="activeStatementTab = 'payments'" :class="['sub-tab', activeStatementTab === 'payments' ? 'sub-tab--active' : '']">
              سندات القبض
            </button>
          </div>

          <!-- Date / Search Filters -->
          <div v-if="!selectedCustomerForStatement?.is_group_flag && activeStatementTab !== 'bookings'" class="filters-row print:hidden">
            <div class="search-box flex-1">
              <Search class="search-icon w-4 h-4" />
              <input v-model="statementFilters.search" type="text" placeholder="بحث في البيان أو المرجع..." class="search-input" />
            </div>
            <input v-model="statementFilters.from_date" type="date" class="date-input" title="من تاريخ" />
            <span class="text-muted text-sm">-</span>
            <input v-model="statementFilters.to_date" type="date" class="date-input" title="إلى تاريخ" />
          </div>

          <!-- Loading -->
          <div v-if="loadingStatement" class="loading-state">
            <div class="spinner spinner--blue"></div>
            <span>جاري تحميل كشف الحساب...</span>
          </div>

          <div v-else>
            <!-- LEDGER TAB (also for groups) -->
            <div v-if="selectedCustomerForStatement?.is_group_flag || activeStatementTab === 'ledger'">
              <div v-if="filteredStatementItems.length === 0" class="empty-state empty-state--sm">
                <Filter class="w-8 h-8 text-muted/20" />
                <p class="empty-title">لا توجد عمليات</p>
              </div>
              <div v-else class="table-wrapper">
                <table class="data-table data-table--sm">
                  <thead>
                    <tr>
                      <th>التاريخ</th>
                      <th>القسم</th>
                      <th>البيان</th>
                      <th>{{ selectedCustomerForStatement?.is_group_flag ? 'مدين (علينا)' : 'مدين (سحب)' }}</th>
                      <th>{{ selectedCustomerForStatement?.is_group_flag ? 'دائن (سدادنا)' : 'دائن (إيداع)' }}</th>
                      <th>الرصيد</th>
                      <th class="text-left print:hidden">إجراء</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="item in filteredStatementItems" :key="item.id" class="data-row">
                      <td class="mono-text text-xs">
                        <div class="text-white">{{ item.date_human?.split(' ')[0] }}</div>
                        <div class="text-muted text-[10px]">{{ item.date_human?.split(' ')[1] }}</div>
                      </td>
                      <td>
                        <span class="module-badge">
                          <component :is="getModuleIcon(item.module)" class="w-3 h-3 text-gold" />
                          {{ getModuleLabel(item.module) }}
                        </span>
                      </td>
                      <td class="max-w-xs">
                        <div class="text-sm text-white font-semibold">
                          <template v-if="selectedCustomerForStatement?.is_group_flag">
                            {{ item.credit > 0 ? 'سند صرف — دفعة مسددة للمجموعة' : item.description }}
                          </template>
                          <template v-else>
                            {{ item.debit > 0 ? 'سند قبض — تحصيل دفعة من العميل' : item.description }}
                          </template>
                        </div>
                        <div class="flex items-center gap-1.5 mt-0.5">
                          <span v-if="selectedCustomerForStatement?.is_group_flag ? item.debit > 0 : item.credit > 0" class="tag tag--red">
                            {{ selectedCustomerForStatement?.is_group_flag ? 'شراء تذاكر' : 'حجز تذاكر' }}
                          </span>
                          <span v-else class="tag tag--green">
                            {{ selectedCustomerForStatement?.is_group_flag ? 'سداد دفعة' : 'تم الدفع' }}
                          </span>
                          <span class="text-muted/60 text-[10px] font-mono">#{{ item.reference_id || item.transaction_id }}</span>
                        </div>
                        <!-- Booking nested info -->
                        <div v-if="item.booking_details && (selectedCustomerForStatement?.is_group_flag ? item.debit > 0 : item.credit > 0)" class="booking-detail-box">
                          <div class="flex justify-between text-[11px]">
                            <span>PNR: <strong class="text-white font-mono">{{ item.booking_details.pnr || '—' }}</strong></span>
                            <span>خط: <strong class="text-white">{{ item.booking_details.provider_name || '—' }}</strong></span>
                          </div>
                          <div class="flex justify-between text-[10px] mt-1 border-t border-white/5 pt-1">
                            <span>قيمة الحجز: <strong class="text-white font-mono">{{ formatCurrency(item.booking_details.selling_price) }}</strong></span>
                            <span>المسدد: <strong class="text-success font-mono">{{ formatCurrency(item.booking_details.total_paid) }}</strong></span>
                            <span>المتبقي: <strong class="text-error font-mono">{{ formatCurrency(item.booking_details.remaining) }}</strong></span>
                          </div>
                        </div>
                      </td>
                      <!-- Debit Column (مدين = ما على العميل — شراء تذاكر) -->
                      <td>
                        <span v-if="selectedCustomerForStatement?.is_group_flag ? item.debit > 0 : item.credit > 0" class="font-mono text-error font-bold">
                          {{ formatCurrency(selectedCustomerForStatement?.is_group_flag ? item.debit : item.credit) }}
                        </span>
                        <span v-else class="text-muted/30">—</span>
                      </td>
                      <!-- Credit Column (دائن = ما دفعه العميل — سداد) -->
                      <td>
                        <span v-if="selectedCustomerForStatement?.is_group_flag ? item.credit > 0 : item.debit > 0" class="font-mono text-success font-bold">
                          {{ formatCurrency(selectedCustomerForStatement?.is_group_flag ? item.credit : item.debit) }}
                        </span>
                        <span v-else class="text-muted/30">—</span>
                      </td>
                      <!-- Running Balance -->
                      <td>
                        <span v-if="item.balance_after === 0" class="balance-zero">
                          <Check class="w-3 h-3" /> خالص
                        </span>
                        <span v-else class="font-mono font-bold text-sm" :class="item.balance_after > 0 ? 'text-error' : 'text-success'">
                          {{ formatCurrency(Math.abs(item.balance_after)) }}
                          <span class="text-[10px] font-sans">
                            <template v-if="selectedCustomerForStatement?.is_group_flag">{{ item.balance_after > 0 ? '(لهم)' : '(لنا)' }}</template>
                            <template v-else>{{ item.balance_after > 0 ? '(عليه)' : '(له)' }}</template>
                          </span>
                        </span>
                      </td>
                      <!-- Actions -->
                      <td class="text-left print:hidden">
                        <div class="action-btns">
                          <button
                            v-if="item.booking_details && parseFloat(item.booking_details.remaining || 0) > 0"
                            @click="openPayDebtModal(selectedCustomerForStatement, item.booking_details)"
                            class="action-btn action-btn--green text-[10px] px-2"
                            :title="'سداد الحجز'"
                          >
                            <DollarSign class="w-3 h-3" /> سداد
                          </button>
                          <button @click="openReceipt(item)" class="action-btn action-btn--blue" title="عرض السند">
                            <FileText class="w-3.5 h-3.5" />
                          </button>
                          <button @click="printSingleVoucher(item)" class="action-btn action-btn--gold" title="طباعة السند">
                            <Printer class="w-3.5 h-3.5" />
                          </button>
                        </div>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <!-- BOOKINGS TAB -->
            <div v-if="!selectedCustomerForStatement?.is_group_flag && activeStatementTab === 'bookings'">
              <!-- Filter toggle -->
              <div class="flex items-center justify-between gap-4 mb-4 flex-wrap print:hidden">
                <div class="flex gap-2 p-1 bg-white/5 rounded-lg border border-white/5">
                  <button @click="bookingFilter = 'all'" :class="['px-3 py-1 text-xs font-bold rounded-md transition-all', bookingFilter === 'all' ? 'bg-gold text-black' : 'text-muted hover:text-white']">
                    كل الحجوزات ({{ customerBookings.length }})
                  </button>
                  <button @click="bookingFilter = 'unpaid'" :class="['px-3 py-1 text-xs font-bold rounded-md transition-all', bookingFilter === 'unpaid' ? 'bg-error text-white' : 'text-muted hover:text-white']">
                    غير مسددة فقط ({{ customerBookings.filter(b => parseFloat(b.remaining || 0) > 0).length }})
                  </button>
                </div>
              </div>

              <div v-if="loadingBookings" class="loading-state">
                <div class="spinner spinner--blue"></div>
                <span>جاري تحميل الحجوزات...</span>
              </div>
              <div v-else-if="filteredBookings.length === 0" class="empty-state empty-state--sm">
                <Check class="w-8 h-8 text-success/50" v-if="bookingFilter === 'unpaid'" />
                <Filter class="w-8 h-8 text-muted/20" v-else />
                <p class="empty-title" :class="bookingFilter === 'unpaid' ? 'text-success' : ''">
                  {{ bookingFilter === 'unpaid' ? 'جميع الحجوزات مسددة' : 'لا توجد حجوزات مسجلة للعميل' }}
                </p>
              </div>
              <div v-else class="space-y-4">
                <div v-for="booking in filteredBookings" :key="booking.id" class="booking-card bg-slate-900/40 border border-white/10 rounded-xl p-4 transition-all">
                  <!-- Header row -->
                  <div class="flex justify-between items-start gap-4 flex-wrap">
                    <div>
                      <div class="flex items-center gap-2">
                        <span class="booking-num text-xs font-bold font-mono px-2 py-0.5 rounded bg-gold/10 text-gold">حجز #{{ booking.booking_number }}</span>
                        <span v-if="booking.pnr" class="booking-pnr text-xs font-mono px-2 py-0.5 rounded bg-white/5 text-white">PNR: {{ booking.pnr }}</span>
                      </div>
                      <div class="text-xs text-muted mt-1.5 flex items-center gap-1.5 flex-wrap">
                        <Plane class="w-3.5 h-3.5 text-gold" />
                        <span class="font-bold text-white">{{ booking.airline_name || '—' }}</span>
                        <span class="opacity-30">|</span>
                        <span>{{ booking.route || '—' }}</span>
                        <span class="opacity-30">|</span>
                        <span>تاريخ الحجز: <strong class="text-white">{{ booking.created_at?.split(' ')[0] }}</strong></span>
                      </div>
                    </div>
                    <div class="flex items-center gap-2">
                      <span :class="['status-badge text-[10px] px-2 py-0.5 rounded-full font-bold', booking.status === 'confirmed' ? 'bg-success/20 text-success' : (booking.status === 'cancelled' ? 'bg-error/20 text-error' : 'bg-amber-500/20 text-amber-500')]">
                        {{ booking.status_label || booking.status }}
                      </span>
                      <span :class="['status-badge text-[10px] px-2 py-0.5 rounded-full font-bold', booking.payment_status === 'paid' ? 'bg-success/20 text-success' : (booking.payment_status === 'partial' ? 'bg-amber-500/20 text-amber-500' : 'bg-error/20 text-error')]">
                        {{ booking.payment_status_label }}
                      </span>
                    </div>
                  </div>

                  <!-- Amounts summary -->
                  <div class="booking-amounts grid grid-cols-3 gap-2 mt-4 bg-white/[0.02] border border-white/5 p-3 rounded-lg text-center text-xs">
                    <div>
                      <span class="text-muted block mb-0.5">سعر البيع</span>
                      <span class="mono-text font-bold text-white text-sm">{{ formatCurrency(booking.selling_price) }}</span>
                    </div>
                    <div>
                      <span class="text-success block mb-0.5">المسدد</span>
                      <span class="mono-text font-bold text-success text-sm">{{ formatCurrency(booking.total_paid) }}</span>
                    </div>
                    <div>
                      <span class="text-error block mb-0.5">المتبقي</span>
                      <span class="mono-text font-black text-error text-sm">{{ formatCurrency(booking.remaining) }}</span>
                    </div>
                  </div>

                  <!-- Toggle details button -->
                  <div class="flex gap-2 justify-between mt-3 pt-3 border-t border-white/5">
                    <button @click="toggleBookingDetails(booking.id)" class="text-xs font-bold text-sky-400 hover:text-sky-300 flex items-center gap-1 cursor-pointer">
                      <Eye class="w-3.5 h-3.5" />
                      {{ expandedBookings[booking.id] ? 'إخفاء التفاصيل' : 'عرض كامل بيانات التذكرة والرحلة' }}
                    </button>
                    <button v-if="parseFloat(booking.remaining || 0) > 0" @click="openPayDebtModal(selectedCustomerForStatement, booking)" class="btn-sm btn-sm--green text-[11px] px-3 py-1">
                      <DollarSign class="w-3.5 h-3.5" /> سداد الحجز
                    </button>
                  </div>

                  <!-- Expanded details area -->
                  <div v-if="expandedBookings[booking.id]" class="mt-4 pt-4 border-t border-white/5 space-y-4 text-xs">
                    <!-- Passengers & Tickets -->
                    <div>
                      <h4 class="font-black text-white text-xs mb-2 flex items-center gap-1.5">
                        <Users class="w-3.5 h-3.5 text-gold" /> بيانات الركاب والتذاكر
                      </h4>
                      <div class="table-wrapper">
                        <table class="data-table data-table--sm">
                          <thead>
                            <tr>
                              <th>اسم الراكب</th>
                              <th>النوع</th>
                              <th>رقم جواز السفر</th>
                              <th>الرقم القومي</th>
                              <th>تاريخ الميلاد</th>
                              <th>رقم التذكرة</th>
                            </tr>
                          </thead>
                          <tbody>
                            <tr v-for="p in booking.passengers" :key="p.id" class="data-row">
                              <td class="font-bold text-white">{{ p.first_name }} {{ p.last_name }}</td>
                              <td>
                                <span class="px-1.5 py-0.5 rounded text-[10px]" :class="p.type === 'adult' ? 'bg-blue-500/10 text-blue-400' : 'bg-orange-500/10 text-orange-400'">
                                  {{ p.type === 'adult' ? 'بالغ' : (p.type === 'child' ? 'طفل' : 'رضيع') }}
                                </span>
                              </td>
                              <td class="font-mono text-muted">{{ p.passport_number || '—' }}</td>
                              <td class="font-mono text-muted">{{ p.national_id || '—' }}</td>
                              <td class="font-mono text-muted">{{ p.date_of_birth || '—' }}</td>
                              <td class="font-mono font-bold text-gold">
                                {{ booking.tickets?.find(t => t.passenger_id === p.id)?.ticket_number || '—' }}
                              </td>
                            </tr>
                            <tr v-if="!booking.passengers || booking.passengers.length === 0">
                              <td colspan="6" class="text-center text-muted">لا يوجد ركاب مسجلين</td>
                            </tr>
                          </tbody>
                        </table>
                      </div>
                    </div>

                    <!-- Flight segments / itinerary -->
                    <div>
                      <h4 class="font-black text-white text-xs mb-2 flex items-center gap-1.5">
                        <Plane class="w-3.5 h-3.5 text-gold" /> مسار الرحلة والتواريخ
                      </h4>
                      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div v-for="s in booking.segments" :key="s.id" class="p-3 bg-white/[0.02] border border-white/5 rounded-lg space-y-2">
                          <div class="flex justify-between items-center text-xs font-bold text-white border-b border-white/5 pb-1.5">
                            <span>{{ s.airline || booking.airline_name }} <span class="font-mono text-gold">{{ s.flight_number }}</span></span>
                            <span class="px-1.5 py-0.5 rounded text-[10px] bg-sky-500/10 text-sky-400 font-mono">{{ s.flight_class }}</span>
                          </div>
                          <div class="flex items-center justify-between text-xs font-mono">
                            <div>
                              <span class="text-muted block text-[10px]">المغادرة ({{ s.from_airport }})</span>
                              <span class="font-bold text-white block">{{ s.departure_date }}</span>
                              <span class="text-muted block text-[10px]">{{ s.departure_time || '—' }}</span>
                            </div>
                            <span class="text-gold text-lg">✈</span>
                            <div class="text-left">
                              <span class="text-muted block text-[10px]">الوصول ({{ s.to_airport }})</span>
                              <span class="font-bold text-white block">{{ s.arrival_date || s.departure_date }}</span>
                              <span class="text-muted block text-[10px]">{{ s.arrival_time || '—' }}</span>
                            </div>
                          </div>
                          <div v-if="s.baggage" class="text-[10px] text-muted flex items-center gap-1">
                            <span>الوزن المسموح:</span>
                            <strong class="text-white">{{ s.baggage }}</strong>
                          </div>
                        </div>
                        <div v-if="!booking.segments || booking.segments.length === 0" class="col-span-2 text-center text-muted p-2 bg-white/[0.01] rounded">
                          لا توجد تفاصيل لخطوط سير الرحلة
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- PAYMENTS TAB -->
            <div v-if="!selectedCustomerForStatement?.is_group_flag && activeStatementTab === 'payments'">
              <div v-if="recordedPayments.length === 0" class="empty-state empty-state--sm">
                <Filter class="w-8 h-8 text-muted/20" />
                <p class="empty-title">لا توجد مدفوعات مسجلة</p>
              </div>
              <div v-else class="table-wrapper">
                <table class="data-table data-table--sm">
                  <thead>
                    <tr>
                      <th>التاريخ والوقت</th>
                      <th>رقم السند</th>
                      <th>المبلغ المستلم</th>
                      <th>البيان</th>
                      <th class="text-left">إجراء</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="item in recordedPayments" :key="item.id" class="data-row">
                      <td class="mono-text text-xs text-muted">{{ item.date_human }}</td>
                      <td class="mono-text">#{{ item.transaction_id }}</td>
                      <td class="mono-text text-success font-black text-base">
                        {{ formatCurrency(selectedCustomerForStatement?.is_group_flag ? item.credit : item.debit) }}
                      </td>
                      <td class="text-xs text-muted max-w-xs whitespace-normal">{{ item.description }}</td>
                      <td class="text-left">
                        <div class="action-btns">
                          <button @click="openReceipt(item)" class="action-btn action-btn--blue" title="عرض السند">
                            <FileText class="w-3.5 h-3.5" />
                          </button>
                          <button @click="printSingleVoucher(item)" class="action-btn action-btn--gold" title="طباعة السند">
                            <Printer class="w-3.5 h-3.5" />
                          </button>
                        </div>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <!-- Print Signatures -->
          <div class="hidden print:grid grid-cols-3 gap-6 mt-16 pt-8 text-center text-xs font-bold text-slate-800">
            <div>{{ selectedCustomerForStatement?.is_group_flag ? 'توقيع ممثل المجموعة' : 'توقيع العميل' }}<br><br><div class="border-b border-dashed border-slate-400 w-32 mx-auto"></div></div>
            <div>توقيع الموظف المسؤول<br><br><div class="border-b border-dashed border-slate-400 w-32 mx-auto"></div></div>
            <div>ختم الوكالة<br><br><div class="border-b border-dashed border-slate-400 w-32 mx-auto"></div></div>
          </div>
        </div>
      </div>
    </div>

    <!-- ============================================================ -->
    <!-- MODAL: Receipt / Voucher Detail                              -->
    <!-- ============================================================ -->
    <div v-if="selectedReceipt" class="modal-overlay modal-overlay--receipt" dir="rtl" @click.self="selectedReceipt = null">
      <div class="modal-box modal-box--sm print-voucher-area">
        <div class="modal-header print:hidden">
          <div class="flex items-center gap-2 text-gold">
            <Printer class="w-5 h-5" />
            <h3 class="font-black text-lg">سند مالي</h3>
          </div>
          <button @click="selectedReceipt = null" class="modal-close"><X class="w-5 h-5" /></button>
        </div>
        <div class="p-8 space-y-5 text-right overflow-y-auto max-h-[70vh] print:overflow-visible print:max-h-none">
          <div class="text-center pb-4">
            <h2 class="text-2xl font-black text-gold print:text-black">سفرك علينا للسياحة</h2>
            <p class="text-xs text-muted print:text-black/60 mt-1">سند معاملة مالية</p>
          </div>
          <div class="text-center py-5 border border-white/10 rounded-2xl print:border-black/20 print:border-2">
            <span class="text-[10px] font-black text-muted block mb-2 uppercase tracking-widest">قيمة الحركة</span>
            <p class="text-4xl font-black font-mono" :class="((selectedCustomerForStatement?.is_group_flag && selectedReceipt.credit > 0) || (!selectedCustomerForStatement?.is_group_flag && selectedReceipt.debit > 0)) ? 'text-success' : 'text-error'">
              {{ formatCurrency(selectedReceipt.credit > 0 ? selectedReceipt.credit : selectedReceipt.debit) }}
            </p>
            <span class="text-sm font-bold mt-2 block" :class="((selectedCustomerForStatement?.is_group_flag && selectedReceipt.credit > 0) || (!selectedCustomerForStatement?.is_group_flag && selectedReceipt.debit > 0)) ? 'text-success' : 'text-error'">
              <span v-if="selectedCustomerForStatement?.is_group_flag">{{ selectedReceipt.credit > 0 ? 'سند صرف — دفع للمجموعة' : 'مديونية شراء تذاكر بالأجل' }}</span>
              <span v-else>{{ selectedReceipt.debit > 0 ? 'سداد من العميل' : 'مديونية سحب' }}</span>
            </span>
          </div>
          <div class="space-y-3 text-sm bg-white/5 p-5 rounded-2xl print:bg-transparent print:border print:border-black/20">
            <div class="flex justify-between py-2 border-b border-white/5"><span class="text-muted text-xs font-bold">رقم المرجع</span><span class="mono-text font-bold">{{ selectedReceipt.reference_id || selectedReceipt.transaction_id }}</span></div>
            <div class="flex justify-between py-2 border-b border-white/5"><span class="text-muted text-xs font-bold">التاريخ</span><span class="mono-text text-xs">{{ selectedReceipt.date_human }}</span></div>
            <div class="flex justify-between py-2 border-b border-white/5"><span class="text-muted text-xs font-bold">{{ selectedCustomerForStatement?.is_group_flag ? 'المجموعة' : 'العميل' }}</span><span class="font-bold">{{ selectedCustomerForStatement?.name || selectedCustomerForStatement?.full_name }}</span></div>
            <div class="flex justify-between py-2"><span class="text-muted text-xs font-bold">القسم</span><span class="font-bold">{{ getModuleLabel(selectedReceipt.module) }}</span></div>
          </div>
          <div>
            <span class="text-[10px] font-black text-gold uppercase tracking-widest block mb-2">البيان</span>
            <p class="text-sm bg-white/[0.02] border border-white/5 p-4 rounded-xl leading-relaxed">{{ selectedReceipt.description }}</p>
          </div>
          <div class="grid grid-cols-2 gap-4 pt-12 text-center text-xs text-muted font-bold">
            <div>توقيع الموظف<br><br>.........................</div>
            <div>توقيع المستلم<br><br>.........................</div>
          </div>
        </div>
        <div class="p-4 bg-white/5 border-t border-white/10 print:hidden">
          <button @click="printCurrentVoucher" class="btn-primary w-full justify-center">
            <Printer class="w-4 h-4" /> طباعة السند
          </button>
        </div>
      </div>
    </div>

    <!-- ============================================================ -->
    <!-- MODAL: Pay Debt                                               -->
    <!-- ============================================================ -->
    <div v-if="showPayDebtModal" class="modal-overlay modal-overlay--pay print:hidden" dir="rtl">
      <div class="modal-box modal-box--sm">
        <div class="modal-header">
          <div class="flex items-center gap-3">
            <div class="stat-icon stat-icon--green"><DollarSign class="w-5 h-5" /></div>
            <div>
              <h3 class="modal-title">
                {{ selectedCustomerForPayment?.is_group_flag 
                  ? (payDebtForm.type === 'debt' ? 'تسجيل سند قبض — تحصيل من المجموعة' : 'تسجيل سند صرف — دفع للمجموعة') 
                  : (payDebtForm.type === 'payment' ? 'تسجيل سند صرف — دفع/مرتجع للعميل' : 'تسجيل سند قبض — تحصيل مديونية') }}
              </h3>
              <p class="text-xs text-muted mt-0.5">
                {{ selectedCustomerForPayment?.name || selectedCustomerForPayment?.full_name }}
              </p>
            </div>
          </div>
          <button @click="closePayDebtModal" class="modal-close"><X class="w-5 h-5" /></button>
        </div>
        <div class="modal-body space-y-4">
          <!-- Transaction Type Selection — Smart: show only relevant direction -->
          <div v-if="!payDebtForm.booking_id" class="form-field">
            <label class="form-label">نوع الحركة المالية (السند) *</label>

            <!-- Zero balance: show both options -->
            <template v-if="!isReceiptDisabled && !isPaymentDisabled">
              <div class="flex gap-2 p-1 bg-white/5 rounded-lg border border-white/5">
                <button
                  type="button"
                  @click="payDebtForm.type = (selectedCustomerForPayment?.is_group_flag ? 'debt' : 'receipt'); handleTypeChange();"
                  :class="['flex-1 py-2 text-xs font-bold rounded-md transition-all text-center cursor-pointer',
                    (payDebtForm.type === 'receipt' || payDebtForm.type === 'debt')
                      ? 'bg-success text-slate-950 font-black'
                      : 'text-muted hover:text-white']"
                >
                  {{ selectedCustomerForPayment?.is_group_flag ? 'سند قبض (تحصيل من المجموعة)' : 'سند قبض (استلام من العميل)' }}
                </button>
                <button
                  type="button"
                  @click="payDebtForm.type = 'payment'; handleTypeChange();"
                  :class="['flex-1 py-2 text-xs font-bold rounded-md transition-all text-center cursor-pointer',
                    payDebtForm.type === 'payment'
                      ? 'bg-error text-white font-black'
                      : 'text-muted hover:text-white']"
                >
                  {{ selectedCustomerForPayment?.is_group_flag ? 'سند صرف (دفع للمجموعة)' : 'سند صرف (صرف/مرتجع للعميل)' }}
                </button>
              </div>
            </template>

            <!-- Has debt (owed to us) → only receipt/collection -->
            <template v-else-if="!isReceiptDisabled && isPaymentDisabled">
              <div class="type-badge type-badge--receipt">
                <div class="type-badge__icon">↓</div>
                <div>
                  <div class="type-badge__title">{{ selectedCustomerForPayment?.is_group_flag ? 'سند قبض — تحصيل من المجموعة' : 'سند قبض — استلام مديونية من العميل' }}</div>
                  <div class="type-badge__sub">العميل عليه فلوس ← سيتم تسجيل استلام دفعة</div>
                </div>
              </div>
            </template>

            <!-- Has credit (we owe them) → only payment/refund -->
            <template v-else-if="isReceiptDisabled && !isPaymentDisabled">
              <div class="type-badge type-badge--payment">
                <div class="type-badge__icon">↑</div>
                <div>
                  <div class="type-badge__title">{{ selectedCustomerForPayment?.is_group_flag ? 'سند صرف — دفع للمجموعة' : 'سند صرف — صرف/مرتجع للعميل' }}</div>
                  <div class="type-badge__sub">العميل له فلوس ← سيتم تسجيل صرف مبلغ له</div>
                </div>
              </div>
            </template>
          </div>

          <!-- Current Balance Info -->
          <div class="info-row">
            <div>
              <span class="info-label">{{ selectedCustomerForPayment?.is_group_flag ? (parseFloat(selectedCustomerForPayment?.balance || 0) < 0 ? 'المديونية المستحقة لنا' : 'المديونية المستحقة لهم') : 'رصيد المديونية الحالية' }}</span>
              <span class="font-mono font-bold text-sm" :class="(selectedCustomerForPayment?.balance || 0) > 0 ? 'text-error' : ((selectedCustomerForPayment?.balance || 0) < 0 ? 'text-success' : 'text-white')">
                {{ formatCurrency(Math.abs(selectedCustomerForPayment?.balance || 0)) }}
                <span class="text-[11px] font-sans mr-1">
                  <template v-if="selectedCustomerForPayment?.is_group_flag">
                    {{ (parseFloat(selectedCustomerForPayment?.balance || 0) > 0) ? '(مستحق لهم)' : ((parseFloat(selectedCustomerForPayment?.balance || 0) < 0) ? '(مستحق لنا)' : '') }}
                  </template>
                  <template v-else>
                    {{ (parseFloat(selectedCustomerForPayment?.balance || 0) > 0) ? '(مدين — عليه)' : ((parseFloat(selectedCustomerForPayment?.balance || 0) < 0) ? '(دائن — له)' : '') }}
                  </template>
                </span>
              </span>
            </div>
            <div v-if="payDebtForm.booking_id" class="text-left">
              <span class="info-label">مديونية الحجز #{{ payDebtForm.booking_number }}</span>
              <span class="mono-text text-error font-bold">{{ formatCurrency(payDebtForm.booking_remaining || 0) }}</span>
            </div>
          </div>
          <!-- Amount -->
          <div class="form-field">
            <label class="form-label">المبلغ *</label>
            <div class="relative">
              <input v-model="payDebtForm.amount" type="number" step="0.01" min="0.01" required class="form-input font-mono font-bold text-xl" placeholder="0.00" />
              <span class="absolute left-4 top-1/2 -translate-y-1/2 text-xs font-bold text-muted bg-white/5 px-2 py-1 rounded">{{ selectedAccountCurrencySymbol }}</span>
            </div>
            <div v-if="payDebtForm.booking_id && payDebtForm.amount" class="flex justify-between text-xs font-bold mt-1">
              <span class="text-muted">المتبقي بعد السداد:</span>
              <span :class="(payDebtForm.booking_remaining - payDebtForm.amount) > 0 ? 'text-error' : 'text-success'">
                {{ formatCurrency(Math.max(0, payDebtForm.booking_remaining - payDebtForm.amount)) }}
              </span>
            </div>
          </div>
          <!-- Account -->
          <div class="form-field">
            <label class="form-label">
              {{ (payDebtForm.type === 'payment') ? 'الحساب الصادر منه الدفع *' : 'الحساب المستلم للدفعة *' }}
            </label>
            <select v-model="payDebtForm.account_id" required class="form-input">
              <option value="" disabled>اختر الحساب المالي...</option>
              <option v-for="account in activeAccounts" :key="account.id" :value="account.id">
                {{ account.name }} — الرصيد: {{ formatMoney(account.balance, account.currency) }}
              </option>
            </select>
          </div>
          <!-- Exchange Rate & Conversion Fields -->
          <div v-if="showConversionFields" class="grid grid-cols-2 gap-4 animate-in fade-in duration-200">
            <div class="form-field">
              <label class="form-label">سعر الصرف *</label>
              <input v-model="payDebtForm.exchange_rate" type="number" step="0.000001" min="0.000001" required class="form-input font-mono font-bold" placeholder="1.00" />
            </div>
            <div class="form-field">
              <label class="form-label">المعادل بالجنيه (EGP) *</label>
              <div class="relative">
                <input v-model="payDebtForm.converted_amount" type="number" step="0.01" min="0.01" required class="form-input font-mono font-bold" placeholder="0.00" />
                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-xs font-bold text-muted bg-white/5 px-2 py-1 rounded">ج.م</span>
              </div>
            </div>
          </div>
          <div v-if="showConversionFields && payDebtForm.amount && payDebtForm.exchange_rate" class="p-3 bg-white/5 rounded-xl border border-white/5 text-xs text-muted flex justify-between">
            <span>الحسبة التلقائية:</span>
            <span class="font-mono text-gold">{{ payDebtForm.amount }} {{ selectedAccount?.currency }} × {{ payDebtForm.exchange_rate }} = {{ payDebtForm.converted_amount }} EGP</span>
          </div>
          <!-- Notes -->
          <div class="form-field">
            <label class="form-label">البيان / ملاحظات</label>
            <textarea v-model="payDebtForm.notes" rows="3" class="form-input resize-none" placeholder="سند قبض — تسديد مديونية..."></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button @click="closePayDebtModal" class="btn-secondary">إلغاء</button>
          <button @click="submitPayDebt" :disabled="payDebtLoading" class="btn-success">
            <Check class="w-4 h-4" v-if="!payDebtLoading" />
            <div v-else class="btn-spinner"></div>
            {{ payDebtLoading ? 'جاري التسجيل...' : 'حفظ السند وتأكيد الحركة' }}
          </button>
        </div>
      </div>
    </div>

  </div>
</template>

<script setup>
import { ref, reactive, onMounted, computed, nextTick, watch } from 'vue';
import { useCustomerStore } from '@/stores/customerStore';
import {
  Search, Users, Plus, Pen, Trash2, Building2, MapPin, Globe2,
  Check, DollarSign, MessageSquare, Eye, X, FileText, Printer,
  Filter, Plane, Bus, Compass, LayoutGrid, Phone, User
} from 'lucide-vue-next';
import { useDebounceFn } from '@vueuse/core';
import axios from 'axios';
import { isRequestCanceled } from '@/utils/api';
import { fetchSettlementAccounts } from '@/composables/useTreasuryAccountGroups';
import { formatLedgerBalance } from '@/composables/useLedgerBalance';
import { enforcePhoneInput, validateEgyptianPhone } from '@/utils/phoneValidation';

const store = useCustomerStore();

// ——————————————————
// UI State
// ——————————————————
const activeTab = ref('regular'); // 'regular' | 'counter' | 'group'
const searchQuery = ref('');
const balanceFilter = ref('all'); // 'all' | 'outstanding' | 'settled'
const showModal = ref(false);
const isEditMode = ref(false);
const editingCustomerId = ref(null);
const saving = ref(false);
const loadingList = ref(false);

// Statement Modal
const showStatementModal = ref(false);
const loadingStatement = ref(false);
const selectedCustomerForStatement = ref(null);
const statementItems = ref([]);
const statementStats = ref({ opening_balance: 0, period_credit: 0, period_debit: 0, closing_balance: 0 });
const statementFilters = reactive({ search: '', from_date: '', to_date: '', module: '' });
const selectedReceipt = ref(null);
const activeStatementTab = ref('ledger');
const customerBookings = ref([]);
const loadingBookings = ref(false);
const bookingFilter = ref('all'); // 'all' | 'unpaid'
const expandedBookings = ref({});
const toggleBookingDetails = (bookingId) => {
  expandedBookings.value[bookingId] = !expandedBookings.value[bookingId];
};

// Pay Debt Modal
const showPayDebtModal = ref(false);
const payDebtLoading = ref(false);
const selectedCustomerForPayment = ref(null);
const activeAccounts = ref([]);
const payDebtCurrency = ref('EGP');
const payDebtForm = reactive({
  amount: '',
  account_id: '',
  notes: '',
  booking_id: null,
  booking_number: null,
  booking_remaining: null,
  type: 'receipt',
  exchange_rate: 1.0,
  converted_amount: ''
});

const currencyRates = ref({});
const fetchCurrencyRates = async () => {
  try {
    const { data } = await axios.get('/api/v1/settings/currencies');
    if (data && data.success && Array.isArray(data.data)) {
      const rates = {};
      data.data.forEach(c => {
        rates[c.code] = parseFloat(c.exchangeRate) || 1.0;
      });
      currencyRates.value = rates;
    }
  } catch (e) {
    console.error('Failed to load currency rates', e);
  }
};

const selectedAccount = computed(() => {
  return activeAccounts.value.find(acc => acc.id === payDebtForm.account_id);
});

const selectedAccountCurrencySymbol = computed(() => {
  const symbolMap = { EGP: 'ج.م', KWD: 'د.ك', SAR: 'ر.س', USD: '$', EUR: '€' };
  const currency = selectedAccount.value?.currency || 'EGP';
  return symbolMap[currency] || currency;
});

const showConversionFields = computed(() => {
  return selectedAccount.value && selectedAccount.value.currency !== 'EGP';
});

// Watchers for Currency Conversion
watch(() => payDebtForm.account_id, (newAccountId) => {
  const account = activeAccounts.value.find(acc => acc.id === newAccountId);
  if (account && account.currency !== 'EGP') {
    const defaultRate = currencyRates.value[account.currency] || 1.0;
    payDebtForm.exchange_rate = defaultRate;
    const amt = parseFloat(payDebtForm.amount) || 0;
    payDebtForm.converted_amount = (amt * defaultRate).toFixed(2);
  } else {
    payDebtForm.exchange_rate = 1.0;
    payDebtForm.converted_amount = '';
  }
});

watch(() => [payDebtForm.amount, payDebtForm.exchange_rate], ([newAmount, newRate]) => {
  if (showConversionFields.value) {
    const amt = parseFloat(newAmount) || 0;
    const rate = parseFloat(newRate) || 0;
    payDebtForm.converted_amount = (amt * rate).toFixed(2);
  }
});

// Data
const customers = ref([]);
const pagination = ref({ total: 0, currentPage: 1, lastPage: 1, perPage: 15 });
const stats = reactive({ totalDebt: 0, counterCount: 0, companiesCount: 0, groupsCount: 0 });

const form = ref({ full_name: '', phone: '', national_id: '', whatsapp_number: '', city: '', travel_country: '', affiliation: '', notes: '' });

// ——————————————————
// Phone Validation
// ——————————————————
const phoneError    = ref('');
const whatsappError = ref('');

const onPhoneInput = () => {
  form.value.phone = enforcePhoneInput(form.value.phone);
  phoneError.value = '';
};
const onPhoneBlur = () => {
  phoneError.value = validateEgyptianPhone(form.value.phone);
};
const onWhatsappInput = () => {
  form.value.whatsapp_number = enforcePhoneInput(form.value.whatsapp_number);
  whatsappError.value = '';
};
const onWhatsappBlur = () => {
  whatsappError.value = validateEgyptianPhone(form.value.whatsapp_number);
};

// ——————————————————
// Computed
// ——————————————————
const searchPlaceholder = computed(() => {
  if (activeTab.value === 'group') return 'ابحث باسم المجموعة أو الكود أو مسؤول التواصل...';
  if (activeTab.value === 'counter') return 'ابحث باسم الشركة أو رقم الهاتف...';
  return 'ابحث بالاسم أو رقم الهاتف أو الرقم القومي...';
});

const balanceFilterOptions = computed(() => {
  const outstandingLabel = activeTab.value === 'group' ? 'عليها مديونية' : 'مديونين';
  const settledLabel = activeTab.value === 'group' ? 'مسددة بالكامل' : 'مسددين';
  return [
    { value: 'all', label: 'الكل' },
    { value: 'outstanding', label: outstandingLabel },
    { value: 'settled', label: settledLabel },
  ];
});

const applyBalanceFilter = (items, isGroup = false) => {
  if (balanceFilter.value === 'settled') {
    return items.filter((row) => Math.abs(parseFloat(row.balance || 0)) < 0.00001);
  }
  if (balanceFilter.value === 'outstanding') {
    if (isGroup) {
      return items.filter((row) => Math.abs(parseFloat(row.balance || 0)) >= 0.00001);
    }
    return items.filter((row) => parseFloat(row.balance || 0) > 0);
  }
  return items;
};

const isReceiptDisabled = computed(() => {
  if (!selectedCustomerForPayment.value) return false;
  const bal = parseFloat(selectedCustomerForPayment.value.balance || 0);
  const isGroup = selectedCustomerForPayment.value.is_group_flag;
  // Customer bal>0 = owes us → collect (receipt). Group bal<0 = credit in our favor → collect (receipt).
  if (isGroup) {
    return bal > 0;
  }
  return bal < 0;
});

const isPaymentDisabled = computed(() => {
  if (!selectedCustomerForPayment.value) return false;
  const bal = parseFloat(selectedCustomerForPayment.value.balance || 0);
  const isGroup = selectedCustomerForPayment.value.is_group_flag;
  // Customer bal<0 = we owe them → pay. Group bal>0 = we owe group → pay.
  if (isGroup) {
    return bal < 0;
  }
  return bal > 0;
});

const outstandingBookings = computed(() => {
  const map = new Map();
  statementItems.value.forEach(item => {
    if (item.booking_details?.booking_id) {
      const bId = item.booking_details.booking_id;
      if (parseFloat(item.booking_details.remaining || 0) > 0) {
        if (!map.has(bId)) map.set(bId, item.booking_details);
      }
    }
  });
  return Array.from(map.values());
});

const recordedPayments = computed(() => {
  if (selectedCustomerForStatement.value?.is_group_flag) {
    return statementItems.value.filter(i => parseFloat(i.credit || 0) > 0);
  }
  return statementItems.value.filter(i => parseFloat(i.debit || 0) > 0);
});

const filteredBookings = computed(() => {
  if (bookingFilter.value === 'unpaid') {
    return customerBookings.value.filter(b => parseFloat(b.remaining || 0) > 0);
  }
  return customerBookings.value;
});

const filteredStatementItems = computed(() => {
  return statementItems.value.filter(item => {
    if (statementFilters.search) {
      const q = statementFilters.search.toLowerCase();
      const matchDesc = item.description?.toLowerCase().includes(q);
      const matchRef = item.reference_id?.toLowerCase().includes(q);
      const matchPnr = item.booking_details?.pnr?.toLowerCase().includes(q);
      if (!matchDesc && !matchRef && !matchPnr) return false;
    }
    if (statementFilters.from_date) {
      if (!item.created_at) return true;
      const itemDate = new Date(item.created_at.split(' ')[0]);
      if (isNaN(itemDate.getTime())) return true;
      if (itemDate < new Date(statementFilters.from_date)) return false;
    }
    if (statementFilters.to_date) {
      if (!item.created_at) return true;
      const itemDate = new Date(item.created_at.split(' ')[0]);
      if (isNaN(itemDate.getTime())) return true;
      if (itemDate > new Date(statementFilters.to_date)) return false;
    }
    return true;
  });
});

// ——————————————————
// Helpers
// ——————————————————
const getInitials = (name) => {
  if (!name) return '?';
  return name.trim().split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2);
};

const formatWhatsApp = (phone) => {
  if (!phone) return '';
  let clean = phone.replace(/\D/g, '');
  if (clean.startsWith('0') && clean.length === 11) clean = '2' + clean;
  return clean;
};

const formatMoney = (amount, currencyCode = 'EGP') => {
  const symbolMap = { EGP: 'جنيه', KWD: 'د.ك', SAR: 'ر.س', USD: '$' };
  const suffix = symbolMap[currencyCode] || currencyCode;
  const formatted = (parseFloat(amount) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  return `${formatted} ${suffix}`;
};

const formatCurrency = (val) => formatMoney(val, 'EGP');

const formatBalance = (balance, type, currencyCode = 'EGP') => {
  const formatted = formatLedgerBalance(balance, type);
  if (formatted.direction === 'zero') {
    return formatted;
  }

  return {
    ...formatted,
    text: formatMoney(Math.abs(parseFloat(balance) || 0), currencyCode),
  };
};

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
  const labels = { flight: 'طيران', bus: 'باصات', hajj_umra: 'حج وعمرة', visa: 'تأشيرات', online: 'أونلاين' };
  return labels[module] || 'عام';
};

// ——————————————————
// Data Fetching
// ——————————————————
const fetchCustomersList = async (page = 1) => {
  loadingList.value = true;
  try {
    if (activeTab.value === 'group') {
      const response = await axios.get('/api/v1/flight/groups');
      let raw = response.data?.data || [];
      if (searchQuery.value) {
        const q = searchQuery.value.toLowerCase();
        raw = raw.filter(g =>
          g.name?.toLowerCase().includes(q) ||
          g.code?.toLowerCase().includes(q) ||
          g.contact_person?.toLowerCase().includes(q) ||
          g.contact_phone?.toLowerCase().includes(q)
        );
      }
      raw = applyBalanceFilter(raw.map(g => ({
        id: g.id,
        name: g.name,
        code: g.code,
        contact_person: g.contact_person,
        contact_phone: g.contact_phone,
        carrier: g.carrier,
        balance: parseFloat(g.balance || 0),
        notes: g.notes,
        is_group_flag: true
      })), true);
      customers.value = raw;
      pagination.value = { total: customers.value.length, currentPage: 1, lastPage: 1, perPage: 1000 };
    } else {
      const params = { type: activeTab.value, search: searchQuery.value, page, per_page: 15 };
      if (balanceFilter.value === 'settled') {
        params.balance_status = 'settled';
      } else if (balanceFilter.value === 'outstanding') {
        params.balance_status = 'debtors';
      }
      await store.fetchCustomers(params);
      customers.value = store.customers;
      pagination.value = store.pagination;
    }
  } catch (error) {
    console.error('Failed to load list', error);
  } finally {
    loadingList.value = false;
  }
};

const fetchStats = async () => {
  try {
    // Counter customers
    const resRegular = await axios.get('/api/v1/customers', { params: { type: 'regular', per_page: 1, page: 1 } });
    stats.counterCount = resRegular.data?.data?.pagination?.total || resRegular.data?.data?.total || 0;

    // Company customers
    const resCounter = await axios.get('/api/v1/customers', { params: { type: 'counter', per_page: 1, page: 1 } });
    stats.companiesCount = resCounter.data?.data?.pagination?.total || resCounter.data?.data?.total || 0;

    // Groups
    const resGroups = await axios.get('/api/v1/flight/groups');
    const groups = resGroups.data?.data || [];
    stats.groupsCount = groups.length;

    // Total debt (from all customers with positive balance)
    const resAll = await axios.get('/api/v1/customers', { params: { per_page: 1000 } });
    const items = resAll.data?.data?.items || resAll.data?.data || [];
    let debtSum = 0;
    items.forEach(c => {
      const bal = parseFloat(c.balance || 0);
      if (bal > 0) debtSum += bal;
    });
    stats.totalDebt = debtSum;
  } catch (error) {
    if (isRequestCanceled(error)) return;
    console.error('Failed to load stats', error);
  }
};

// ——————————————————
// Tab & Pagination
// ——————————————————
const changeTab = (tab) => {
  activeTab.value = tab;
  searchQuery.value = '';
  balanceFilter.value = 'all';
  pagination.value.currentPage = 1;
  fetchCustomersList(1);
};

const changePage = (page) => fetchCustomersList(page);

const onSearch = useDebounceFn(() => fetchCustomersList(1), 350);

const setBalanceFilter = (value) => {
  if (balanceFilter.value === value) return;
  balanceFilter.value = value;
  fetchCustomersList(1);
};

// ——————————————————
// Create / Edit Modal
// ——————————————————
const openCreateModal = () => {
  isEditMode.value = false;
  editingCustomerId.value = null;
  form.value = { full_name: '', phone: '', national_id: '', whatsapp_number: '', city: '', travel_country: '', affiliation: '', notes: '' };
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

const closeModal = () => { showModal.value = false; editingCustomerId.value = null; phoneError.value = ''; whatsappError.value = ''; };

const saveCustomer = async () => {
  if (!form.value.full_name || !form.value.phone) {
    store.addToast('يرجى كتابة الاسم ورقم الهاتف', 'error');
    return;
  }
  // Validate phone format
  const phoneErr    = validateEgyptianPhone(form.value.phone);
  const whatsappErr = validateEgyptianPhone(form.value.whatsapp_number);
  phoneError.value    = phoneErr;
  whatsappError.value = whatsappErr;
  if (phoneErr) {
    store.addToast(phoneErr, 'error');
    return;
  }
  if (whatsappErr) {
    store.addToast(whatsappErr, 'error');
    return;
  }
  saving.value = true;
  try {
    const payload = { ...form.value, type: activeTab.value };
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

const deleteCustomer = async (customer) => {
  if (!confirm(`هل أنت متأكد من حذف: ${customer.name || customer.full_name}؟`)) return;
  try {
    await store.deleteCustomer(customer.id);
    fetchCustomersList(pagination.value.currentPage);
    fetchStats();
  } catch (error) {
    console.error('Failed to delete customer', error);
  }
};

// ——————————————————
// Statement Modal
// ——————————————————
const viewCustomerStatement = async (customer) => {
  selectedCustomerForStatement.value = { ...customer, is_group_flag: activeTab.value === 'group' || customer.is_group_flag };
  showStatementModal.value = true;
  loadingStatement.value = true;
  activeStatementTab.value = 'ledger';
  Object.assign(statementFilters, { search: '', from_date: '', to_date: '', module: '' });
  statementItems.value = [];

  try {
    if (activeTab.value === 'group' || customer.is_group_flag) {
      const response = await axios.get(`/api/v1/flight/groups/${customer.id}/statement`);
      const data = response.data?.data;
      if (data) {
        const mapped = (data.transactions || []).map(tx => {
          const isDebt = tx.type === 'debt';
          return {
            id: 'GR-' + tx.id,
            transaction_id: 'GR-' + tx.id,
            created_at: tx.created_at,
            date_human: tx.created_at ? new Date(tx.created_at).toLocaleString('en-CA', { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false }).replace(',', '') : '',
            user_name: tx.created_by?.name || 'النظام',
            reference_id: tx.booking?.booking_number || '—',
            description: tx.notes || (isDebt ? 'شراء تذكرة طيران بالأجل' : 'سداد دفعة للمجموعة'),
            module: 'flight',
            credit: isDebt ? 0 : parseFloat(tx.amount),
            debit: isDebt ? parseFloat(tx.amount) : 0,
            booking_details: tx.booking ? { pnr: tx.booking.pnr, provider_name: tx.booking.airline_name || '—', route: 'حجز مرتبط', selling_price: parseFloat(tx.amount), total_paid: isDebt ? 0 : parseFloat(tx.amount), remaining: isDebt ? parseFloat(tx.amount) : 0 } : null
          };
        });
        // Running balance
        const sorted = [...mapped].reverse();
        let running = 0;
        sorted.forEach(item => { running = running + item.debit - item.credit; item.balance_after = running; });
        statementItems.value = sorted.reverse();
        statementStats.value = {
          opening_balance: 0,
          period_debit: parseFloat(data.summary?.total_debt || 0),
          period_credit: parseFloat(data.summary?.total_payment || 0),
          closing_balance: parseFloat(data.summary?.balance || 0)
        };
      }
    } else {
      const response = await axios.get(`/api/v1/customers/${customer.id}/statement`);
      const data = response.data?.data;
      if (data) {
        statementItems.value = data.items || [];
        statementStats.value = data.stats || { opening_balance: 0, period_credit: 0, period_debit: 0, closing_balance: 0 };
      }
      await fetchCustomerBookings(customer.id);
    }
  } catch (error) {
    console.error('Failed to load statement', error);
    store.addToast('حدث خطأ أثناء تحميل كشف الحساب', 'error');
  } finally {
    loadingStatement.value = false;
  }
};

const fetchCustomerBookings = async (customerId) => {
  loadingBookings.value = true;
  customerBookings.value = [];
  expandedBookings.value = {};
  bookingFilter.value = 'all';
  try {
    const response = await axios.get('/api/v1/flight/bookings', {
      params: { customer_id: customerId, per_page: 100 }
    });
    let raw = response.data?.data;
    if (raw && typeof raw === 'object' && !Array.isArray(raw)) {
      if (Array.isArray(raw.items)) raw = raw.items;
      else if (Array.isArray(raw.items?.data)) raw = raw.items.data;
      else if (Array.isArray(raw.data)) raw = raw.data;
    }
    customerBookings.value = Array.isArray(raw) ? raw : [];
  } catch (error) {
    console.error('Failed to load customer bookings', error);
    store.addToast('حدث خطأ أثناء تحميل الحجوزات التفصيلية للعميل', 'error');
  } finally {
    loadingBookings.value = false;
  }
};

const closeStatementModal = () => { showStatementModal.value = false; selectedCustomerForStatement.value = null; };
const openReceipt = (item) => { selectedReceipt.value = item; };

const printCurrentVoucher = () => {
  document.body.classList.add('printing-voucher');
  setTimeout(() => {
    window.print();
    document.body.classList.remove('printing-voucher');
  }, 120);
};

const printSingleVoucher = async (item) => {
  selectedReceipt.value = item;
  await nextTick();
  printCurrentVoucher();
};

const printStatement = () => {
  document.body.classList.add('printing-statement');
  setTimeout(() => {
    window.print();
    document.body.classList.remove('printing-statement');
  }, 120);
};

// ——————————————————
// Pay Debt Modal
// ——————————————————
const fetchActiveAccounts = async () => {
  try {
    activeAccounts.value = await fetchSettlementAccounts(axios, { module: 'flight' });
  } catch (error) {
    console.error('Failed to load accounts', error);
    activeAccounts.value = [];
  }
};

const openPayDebtModal = (customerRef, booking = null) => {
  if (!customerRef) return;
  const customer = (customerRef && customerRef.value !== undefined) ? customerRef.value : customerRef;

  const isGroup = activeTab.value === 'group' || customer.is_group_flag;
  selectedCustomerForPayment.value = { ...customer, is_group_flag: isGroup };

  payDebtCurrency.value = isGroup 
    ? (customer.carrier?.currency || 'EGP')
    : (customer.ledger_account?.currency || customer.currency || 'EGP');

  let defaultType = 'receipt';
  if (isGroup) {
    // bal > 0 = مستحق لهم (علينا) → سند صرف
    // bal < 0 = مستحق لنا → سند قبض
    defaultType = parseFloat(customer.balance || 0) > 0 ? 'payment' : 'debt';
  } else {
    // bal > 0 means customer owes us → collect (receipt)
    // bal < 0 means we owe customer → refund (payment)
    defaultType = parseFloat(customer.balance || 0) > 0 ? 'receipt' : 'payment';
  }
  payDebtForm.type = defaultType;

  if (booking) {
    payDebtForm.booking_id = booking.booking_id;
    payDebtForm.booking_number = booking.booking_number;
    payDebtForm.booking_remaining = parseFloat(booking.remaining || 0);
    payDebtForm.amount = parseFloat(booking.remaining || 0);
    payDebtForm.notes = `سداد مديونية الحجز #${booking.booking_number} — العميل: ${customer.name || customer.full_name}`;
  } else {
    payDebtForm.booking_id = null;
    payDebtForm.booking_number = null;
    payDebtForm.booking_remaining = null;
    payDebtForm.amount = customer.balance !== 0 ? Math.abs(parseFloat(customer.balance)) : '';
    payDebtForm.notes = isGroup
      ? (defaultType === 'debt' ? `سند قبض — تحصيل من مجموعة طيران: ${customer.name}` : `سند صرف — دفع لمجموعة طيران: ${customer.name}`)
      : (defaultType === 'payment' ? `سند صرف — إرجاع متبقي للعميل: ${customer.name || customer.full_name}` : `سند قبض — تسديد مديونية: ${customer.name || customer.full_name}`);
  }
  payDebtForm.account_id = '';
  showPayDebtModal.value = true;
  fetchActiveAccounts();
  fetchCurrencyRates();
};

const handleTypeChange = () => {
  const isGroup = selectedCustomerForPayment.value?.is_group_flag;
  const name = selectedCustomerForPayment.value?.name || selectedCustomerForPayment.value?.full_name;
  
  if (isGroup) {
    payDebtForm.notes = payDebtForm.type === 'debt'
      ? `سند قبض — تحصيل من مجموعة طيران: ${name}`
      : `سند صرف — دفع لمجموعة طيران: ${name}`;
  } else {
    payDebtForm.notes = payDebtForm.type === 'payment'
      ? `سند صرف — إرجاع متبقي للعميل: ${name}`
      : `سند قبض — تسديد مديونية: ${name}`;
  }
};

const closePayDebtModal = () => {
  showPayDebtModal.value = false;
  selectedCustomerForPayment.value = null;
  Object.assign(payDebtForm, {
    amount: '',
    account_id: '',
    notes: '',
    type: 'receipt',
    booking_id: null,
    booking_number: null,
    booking_remaining: null,
    exchange_rate: 1.0,
    converted_amount: ''
  });
};

const submitPayDebt = async () => {
  if (!payDebtForm.amount || parseFloat(payDebtForm.amount) <= 0) {
    store.addToast('يرجى إدخال مبلغ صحيح أكبر من صفر', 'error');
    return;
  }
  if (!payDebtForm.account_id) {
    store.addToast('يرجى تحديد الحساب المالي', 'error');
    return;
  }
  payDebtLoading.value = true;
  try {
    const isGroup = selectedCustomerForPayment.value.is_group_flag;
    const id = selectedCustomerForPayment.value.id;
    let response;

    if (payDebtForm.booking_id) {
      const selectedAccount = activeAccounts.value.find(acc => acc.id === payDebtForm.account_id);
      let paymentMethod = 'cash';
      if (selectedAccount?.type === 'bank') paymentMethod = 'bank_transfer';
      else if (selectedAccount?.type === 'wallet') paymentMethod = 'cash_wallet';
      else if (selectedAccount?.type === 'post') paymentMethod = 'postal_transfer';
      response = await axios.post(`/api/v1/flight/bookings/${payDebtForm.booking_id}/payments`, {
        amount: parseFloat(payDebtForm.amount), payment_method: paymentMethod,
        account_id: payDebtForm.account_id, notes: payDebtForm.notes || ''
      });
    } else if (isGroup) {
      response = await axios.post(`/api/v1/flight/groups/${id}/pay-debt`, {
        amount: parseFloat(payDebtForm.amount), account_id: payDebtForm.account_id, notes: payDebtForm.notes,
        type: payDebtForm.type
      });
    } else {
      response = await axios.post(`/api/v1/customers/${id}/pay-debt`, {
        amount: parseFloat(payDebtForm.amount),
        account_id: payDebtForm.account_id,
        notes: payDebtForm.notes,
        type: payDebtForm.type,
        exchange_rate: showConversionFields.value ? parseFloat(payDebtForm.exchange_rate) : null,
        converted_amount: showConversionFields.value ? parseFloat(payDebtForm.converted_amount) : null
      });
    }

    if (response.data?.status === 'success' || response.data?.success || response.data?.message) {
      store.addToast(response.data?.message || 'تم تسجيل السند بنجاح', 'success');
      if (showStatementModal.value && selectedCustomerForStatement.value && String(selectedCustomerForStatement.value.id) === String(id)) {
        await viewCustomerStatement(selectedCustomerForStatement.value);
        if (selectedCustomerForStatement.value) {
          selectedCustomerForStatement.value.balance = statementStats.value.closing_balance;
        }
      }
      closePayDebtModal();
      await fetchCustomersList(pagination.value.currentPage);
      fetchStats();
    } else {
      store.addToast(response.data?.message || 'حدث خطأ أثناء العملية', 'error');
    }
  } catch (error) {
    console.error('Failed to submit pay debt', error);
    store.addToast(error.response?.data?.message || 'فشلت العملية، يرجى التحقق من المدخلات', 'error');
  } finally {
    payDebtLoading.value = false;
  }
};

// ——————————————————
// Init
// ——————————————————
onMounted(() => {
  fetchCustomersList(1);
  fetchStats();
});
</script>

<style scoped>
/* ===================================================
   CSS VARIABLES & BASE
   =================================================== */
.page-wrapper { direction: rtl; }

/* ===================================================
   HEADER
   =================================================== */
.page-header { display: flex; flex-direction: column; gap: 1rem; }
@media (min-width: 640px) { .page-header { flex-direction: row; align-items: center; justify-content: space-between; } }
.header-info { display: flex; align-items: center; gap: 1rem; }
.header-icon { width: 3rem; height: 3rem; border-radius: 1rem; display: flex; align-items: center; justify-content: center; color: var(--gold); flex-shrink: 0; background: rgba(212,168,67,0.1); }
.page-title { font-size: 1.5rem; font-weight: 800; color: white; }
.page-subtitle { font-size: 0.875rem; margin-top: 0.125rem; color: var(--text-muted); }

/* ===================================================
   BUTTONS
   =================================================== */
.btn-primary { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.625rem 1.25rem; font-weight: 700; border-radius: 0.75rem; font-size: 0.875rem; transition: all 0.2s; background: var(--gold); color: #000; cursor: pointer; border: none; }
.btn-primary:hover { filter: brightness(1.1); }
.btn-secondary { flex: 1; padding: 0.75rem; font-weight: 600; border-radius: 0.75rem; font-size: 0.875rem; transition: all 0.2s; background: rgba(255,255,255,0.05); color: white; cursor: pointer; border: none; }
.btn-secondary:hover { background: rgba(255,255,255,0.1); }
.btn-success { flex: 1; padding: 0.75rem; font-weight: 700; border-radius: 0.75rem; font-size: 0.875rem; transition: all 0.2s; display: flex; align-items: center; justify-content: center; gap: 0.5rem; background: var(--success); color: #000; cursor: pointer; border: none; }
.btn-success:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-success:hover:not(:disabled) { filter: brightness(1.1); }
.btn-sm { display: inline-flex; align-items: center; gap: 0.375rem; padding: 0.375rem 0.75rem; border-radius: 0.5rem; font-size: 0.75rem; font-weight: 700; transition: all 0.2s; cursor: pointer; border: none; }
.btn-sm--green { background: rgba(34,197,94,0.1); color: var(--success); border: 1px solid rgba(34,197,94,0.2) !important; }
.btn-sm--green:hover { background: var(--success); color: #000; }
.btn-sm--gold { background: rgba(212,168,67,0.1); color: var(--gold); border: 1px solid rgba(212,168,67,0.2) !important; }
.btn-sm--gold:hover { background: var(--gold); color: #000; }
.btn-spinner { width: 1rem; height: 1rem; border-radius: 50%; border: 2px solid #000; border-top-color: transparent; animation: spin 0.7s linear infinite; }
.btn-pay-booking { width: 100%; padding: 0.625rem; font-weight: 700; border-radius: 0.75rem; font-size: 0.875rem; display: flex; align-items: center; justify-content: center; gap: 0.5rem; margin-top: 0.75rem; transition: all 0.2s; background: var(--success); color: #000; cursor: pointer; border: none; }
.btn-pay-booking:hover { filter: brightness(1.1); }

/* ===================================================
   TYPE BADGE — Smart transaction direction indicator
   =================================================== */
.type-badge {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 0.875rem 1.125rem;
  border-radius: 0.875rem;
  border: 1px solid;
  font-family: inherit;
}
.type-badge--receipt {
  background: rgba(34,197,94,0.06);
  border-color: rgba(34,197,94,0.25);
  color: var(--success);
}
.type-badge--payment {
  background: rgba(239,68,68,0.06);
  border-color: rgba(239,68,68,0.25);
  color: var(--error);
}
.type-badge__icon {
  width: 2.25rem;
  height: 2.25rem;
  border-radius: 0.625rem;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.125rem;
  font-weight: 900;
  flex-shrink: 0;
  background: currentColor;
  color: #fff;
}
.type-badge--receipt .type-badge__icon { background: rgba(34,197,94,0.15); color: var(--success); }
.type-badge--payment .type-badge__icon { background: rgba(239,68,68,0.15); color: var(--error); }
.type-badge__title { font-size: 0.8125rem; font-weight: 800; }
.type-badge__sub   { font-size: 0.7rem; margin-top: 0.125rem; opacity: 0.7; }

/* ===================================================
   STATS
   =================================================== */
.stats-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; }
@media (min-width: 1024px) { .stats-grid { grid-template-columns: repeat(4, 1fr); } }
.stat-card { display: flex; align-items: center; gap: 1rem; padding: 1.25rem; border-radius: 1rem; border: 1px solid rgba(255,255,255,0.08); background: var(--card-bg); }
.stat-card--alert { border-color: rgba(239,68,68,0.15); background: rgba(239,68,68,0.03); }
.stat-icon { width: 2.5rem; height: 2.5rem; border-radius: 0.75rem; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.stat-icon--blue { background: rgba(59,130,246,0.1); color: #60a5fa; }
.stat-icon--purple { background: rgba(168,85,247,0.1); color: #c084fc; }
.stat-icon--sky { background: rgba(14,165,233,0.1); color: #38bdf8; }
.stat-icon--red { background: rgba(239,68,68,0.1); color: var(--error); }
.stat-icon--green { background: rgba(34,197,94,0.1); color: var(--success); }
.stat-body { display: flex; flex-direction: column; }
.stat-label { font-size: 0.75rem; font-weight: 700; color: var(--text-muted); }
.stat-value { font-size: 1.25rem; font-weight: 900; color: white; margin-top: 0.125rem; font-family: monospace; }

/* ===================================================
   CONTENT CARD & TABS
   =================================================== */
.content-card { border-radius: 1.5rem; border: 1px solid rgba(255,255,255,0.08); padding: 1.5rem; display: flex; flex-direction: column; gap: 1.25rem; background: var(--card-bg); }
.tab-nav { display: flex; gap: 0.25rem; padding: 0.25rem; border-radius: 1rem; background: rgba(255,255,255,0.04); width: fit-content; }
.tab-btn { display: flex; align-items: center; gap: 0.5rem; padding: 0.625rem 1.25rem; border-radius: 0.75rem; font-size: 0.875rem; font-weight: 700; transition: all 0.2s; color: var(--text-muted); cursor: pointer; border: none; background: transparent; }
.tab-btn:hover { color: white; }
.tab-btn--active { background: var(--gold); color: #000; }
.tab-btn--active.tab-btn--purple { background: #a855f7; color: white; }
.tab-btn--active.tab-btn--sky { background: #0ea5e9; color: white; }
.tab-count { font-size: 0.625rem; padding: 0.125rem 0.5rem; border-radius: 9999px; font-weight: 900; background: rgba(0,0,0,0.2); }
.tab-count--purple { background: rgba(168,85,247,0.2); color: #c084fc; }
.tab-count--sky { background: rgba(14,165,233,0.2); color: #38bdf8; }

/* ===================================================
   SEARCH
   =================================================== */
.search-row { display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center; }
.search-box { position: relative; display: flex; align-items: center; flex: 1; min-width: 220px; }
.balance-filter-chips { display: flex; flex-wrap: wrap; gap: 0.5rem; }
.balance-filter-chip { padding: 0.55rem 1rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 700; border: 1px solid rgba(255,255,255,0.08); background: rgba(255,255,255,0.03); color: var(--text-muted); transition: all 0.2s; cursor: pointer; white-space: nowrap; }
.balance-filter-chip:hover { color: white; border-color: rgba(255,255,255,0.15); background: rgba(255,255,255,0.06); }
.balance-filter-chip--active { background: rgba(212,175,55,0.12); border-color: var(--gold); color: var(--gold); }
.search-icon { position: absolute; right: 1rem; pointer-events: none; color: var(--text-muted); }
.search-input { width: 100%; padding: 0.75rem 2.75rem 0.75rem 1rem; border-radius: 1rem; font-size: 0.875rem; outline: none; transition: all 0.2s; text-align: right; background: var(--input-bg); border: 1px solid rgba(255,255,255,0.08); color: white; }
.search-input:focus { border-color: var(--gold); }
.date-input { padding: 0.75rem; border-radius: 0.75rem; font-size: 0.875rem; font-family: monospace; outline: none; transition: all 0.2s; background: var(--input-bg); border: 1px solid rgba(255,255,255,0.08); color: var(--text-muted); }
.date-input:focus { border-color: var(--gold); }

/* ===================================================
   TABLE
   =================================================== */
.table-wrapper { overflow-x: auto; border-radius: 1rem; border: 1px solid rgba(255,255,255,0.08); background: rgba(255,255,255,0.01); }
.data-table { width: 100%; text-align: right; border-collapse: collapse; }
.data-table th { padding: 0.75rem 1.25rem; font-size: 0.75rem; font-weight: 900; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid rgba(255,255,255,0.08); color: var(--text-muted); background: rgba(255,255,255,0.03); }
.data-table td { padding: 0.875rem 1.25rem; font-size: 0.875rem; border-bottom: 1px solid rgba(255,255,255,0.04); }
.data-row:hover { background: rgba(255,255,255,0.02); }
.data-table--sm th, .data-table--sm td { padding: 0.625rem 1rem; font-size: 0.75rem; }
.customer-cell { display: flex; align-items: center; gap: 0.75rem; }
.avatar { width: 2.25rem; height: 2.25rem; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.75rem; flex-shrink: 0; }
.avatar--blue { background: rgba(59,130,246,0.1); color: #60a5fa; }
.avatar--purple { background: rgba(168,85,247,0.1); color: #c084fc; }
.avatar--sky { background: rgba(14,165,233,0.1); color: #38bdf8; }
.avatar--lg { width: 3.5rem; height: 3.5rem; font-size: 1.125rem; }
.customer-name { font-weight: 600; color: white; font-size: 0.875rem; }
.customer-note { font-size: 0.75rem; margin-top: 0.125rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 200px; color: var(--text-muted); }
.mono-text { font-family: monospace; color: var(--text-muted); }
.balance-cell { cursor: pointer; transition: background 0.2s; }
.balance-cell:hover { background: rgba(255,255,255,0.05); }
.action-btns { display: flex; align-items: center; justify-content: flex-end; gap: 0.375rem; flex-wrap: wrap; }
.action-btn { padding: 0.5rem; border-radius: 0.5rem; transition: all 0.2s; display: flex; align-items: center; gap: 0.25rem; cursor: pointer; border: none; }
.action-btn--green { background: rgba(34,197,94,0.1); color: var(--success); }
.action-btn--green:hover { background: var(--success); color: #000; }
.action-btn--blue { background: rgba(59,130,246,0.1); color: #60a5fa; }
.action-btn--blue:hover { background: #3b82f6; color: white; }
.action-btn--gold { background: rgba(212,168,67,0.1); color: var(--gold); }
.action-btn--gold:hover { background: var(--gold); color: #000; }
.action-btn--red { background: rgba(239,68,68,0.1); color: var(--error); }
.action-btn--red:hover { background: var(--error); color: white; }
.whatsapp-btn { padding: 0.375rem; border-radius: 0.5rem; transition: all 0.2s; background: rgba(34,197,94,0.1); color: #4ade80; }
.whatsapp-btn:hover { background: rgba(34,197,94,0.3); }

/* ===================================================
   PAGINATION & GROUPS TOTAL
   =================================================== */
.pagination { display: flex; flex-direction: column; align-items: center; justify-content: space-between; gap: 0.75rem; padding-top: 1rem; border-top: 1px solid rgba(255,255,255,0.08); font-size: 0.875rem; }
@media (min-width: 640px) { .pagination { flex-direction: row; } }
.pagination-info { color: var(--text-muted); }
.pagination-btns { display: flex; align-items: center; gap: 0.5rem; }
.page-btn { padding: 0.5rem 1rem; border-radius: 0.5rem; font-size: 0.875rem; font-weight: 700; transition: all 0.2s; background: rgba(255,255,255,0.05); color: white; cursor: pointer; border: none; }
.page-btn:disabled { opacity: 0.4; cursor: not-allowed; }
.page-btn:hover:not(:disabled) { background: rgba(255,255,255,0.1); }
.page-current { padding: 0 0.75rem; font-family: monospace; font-weight: 700; color: white; }
.groups-total { display: flex; align-items: center; justify-content: space-between; padding: 1rem 0.5rem 0; border-top: 1px solid rgba(255,255,255,0.08); }

/* ===================================================
   STATES
   =================================================== */
.loading-state { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 4rem 0; gap: 0.75rem; color: var(--text-muted); }
.spinner { width: 2rem; height: 2rem; border-radius: 50%; border: 2px solid var(--gold); border-top-color: transparent; animation: spin 0.7s linear infinite; }
.spinner--purple { border-color: #a855f7; border-top-color: transparent; }
.spinner--sky { border-color: #0ea5e9; border-top-color: transparent; }
.spinner--blue { border-color: #3b82f6; border-top-color: transparent; }
.empty-state { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 4rem 0; gap: 0.75rem; color: var(--text-muted); }
.empty-state--sm { padding: 2.5rem 0; }
.empty-title { font-weight: 700; color: white; }
.empty-sub { font-size: 0.75rem; color: var(--text-muted); }
@keyframes spin { to { transform: rotate(360deg); } }

/* ===================================================
   MODALS
   =================================================== */
.modal-overlay { position: fixed; inset: 0; z-index: 100; display: flex; align-items: center; justify-content: center; padding: 1rem; background: rgba(0,0,0,0.85); backdrop-filter: blur(8px); }
.modal-overlay--lg { z-index: 110; }
.modal-overlay--pay { z-index: 115; }
.modal-overlay--receipt { z-index: 120; }
.modal-box { width: 100%; max-width: 680px; border-radius: 1rem; border: 1px solid rgba(255,255,255,0.1); display: flex; flex-direction: column; max-height: 90vh; background: var(--card-bg); }
.modal-box--xl { max-width: 900px; }
.modal-box--sm { max-width: 520px; }
.modal-header { display: flex; align-items: center; justify-content: space-between; padding: 1rem 1.5rem; border-bottom: 1px solid rgba(255,255,255,0.08); flex-shrink: 0; }
.modal-title { font-weight: 700; color: white; font-size: 1rem; }
.modal-close { padding: 0.5rem; border-radius: 0.75rem; transition: all 0.2s; color: var(--text-muted); cursor: pointer; border: none; background: transparent; }
.modal-close:hover { color: white; background: rgba(255,255,255,0.08); }
.modal-body { padding: 1.25rem 1.5rem; overflow-y: auto; flex: 1; display: flex; flex-direction: column; gap: 1rem; }
.modal-scroll { padding: 1.25rem 1.5rem; overflow-y: auto; flex: 1; display: flex; flex-direction: column; gap: 1.25rem; }
.modal-footer { display: flex; gap: 0.75rem; padding: 1rem 1.5rem; border-top: 1px solid rgba(255,255,255,0.08); flex-shrink: 0; }

/* ===================================================
   FORM
   =================================================== */
.form-grid { display: grid; grid-template-columns: 1fr; gap: 1rem; }
@media (min-width: 768px) { .form-grid { grid-template-columns: 1fr 1fr; } }
.form-field.col-span-2 { grid-column: span 2; }
.form-label { display: block; font-size: 0.75rem; font-weight: 700; margin-bottom: 0.375rem; color: var(--text-muted); }
.form-input { width: 100%; padding: 0.75rem; border-radius: 0.75rem; font-size: 0.875rem; outline: none; transition: all 0.2s; text-align: right; background: var(--input-bg); border: 1px solid rgba(255,255,255,0.08); color: white; box-sizing: border-box; }
.form-input:focus { border-color: var(--gold); }
select.form-input { appearance: none; cursor: pointer; }
.info-row { display: flex; align-items: center; justify-content: space-between; padding: 1rem; border-radius: 0.75rem; background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.06); }
.info-label { display: block; font-size: 0.625rem; font-weight: 700; margin-bottom: 0.125rem; color: var(--text-muted); }

/* ===================================================
   STATEMENT
   =================================================== */
.profile-card { padding: 1.25rem; border-radius: 1rem; border: 1px solid rgba(255,255,255,0.08); background: rgba(255,255,255,0.03); }
.stmt-stats { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.75rem; }
@media (min-width: 1024px) { .stmt-stats { grid-template-columns: repeat(4, 1fr); } }
.stmt-stat { padding: 1rem; border-radius: 1rem; border: 1px solid rgba(255,255,255,0.08); display: flex; flex-direction: column; gap: 0.25rem; background: rgba(255,255,255,0.03); }
.stmt-stat--red { background: rgba(239,68,68,0.04); border-color: rgba(239,68,68,0.1); }
.stmt-stat--green { background: rgba(34,197,94,0.04); border-color: rgba(34,197,94,0.1); }
.stmt-stat--gold { background: rgba(212,168,67,0.04); border-color: rgba(212,168,67,0.1); }
.stmt-stat-label { font-size: 0.625rem; font-weight: 900; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); }
.stmt-stat-val { font-family: monospace; font-weight: 900; font-size: 1.125rem; color: white; }
.sub-tab-nav { display: flex; gap: 0.25rem; padding: 0.25rem; border-radius: 0.75rem; width: fit-content; background: rgba(255,255,255,0.04); }
.sub-tab { display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem 1rem; border-radius: 0.5rem; font-size: 0.75rem; font-weight: 700; transition: all 0.2s; color: var(--text-muted); cursor: pointer; border: none; background: transparent; }
.sub-tab:hover { color: white; }
.sub-tab--active { background: var(--gold); color: #000; }
.badge-count { font-size: 0.625rem; padding: 0.125rem 0.5rem; border-radius: 9999px; font-weight: 900; background: var(--error); color: white; }
.filters-row { display: flex; flex-wrap: wrap; align-items: center; gap: 0.75rem; padding: 0.75rem; border-radius: 0.75rem; border: 1px solid rgba(255,255,255,0.06); background: rgba(255,255,255,0.02); }
.module-badge { display: inline-flex; align-items: center; gap: 0.375rem; padding: 0.25rem 0.5rem; border-radius: 0.375rem; border: 1px solid rgba(255,255,255,0.08); font-size: 0.625rem; background: rgba(255,255,255,0.04); color: var(--text-muted); }
.tag { padding: 0.125rem 0.5rem; border-radius: 9999px; font-size: 0.625rem; font-weight: 700; }
.tag--red { background: rgba(239,68,68,0.15); color: #f87171; }
.tag--green { background: rgba(34,197,94,0.15); color: #4ade80; }
.balance-zero { display: inline-flex; align-items: center; gap: 0.25rem; padding: 0.25rem 0.625rem; border-radius: 0.5rem; font-size: 0.75rem; font-weight: 700; background: rgba(34,197,94,0.1); color: var(--success); }
.badge { padding: 0.125rem 0.625rem; border-radius: 9999px; font-size: 0.625rem; font-weight: 700; border: 1px solid; }
.badge--blue { background: rgba(59,130,246,0.15); border-color: rgba(59,130,246,0.25); color: #93c5fd; }
.badge--purple { background: rgba(168,85,247,0.15); border-color: rgba(168,85,247,0.25); color: #d8b4fe; }
.badge--sky { background: rgba(14,165,233,0.15); border-color: rgba(14,165,233,0.25); color: #7dd3fc; }

/* ===================================================
   BOOKING CARDS
   =================================================== */
.booking-card { padding: 1.25rem; border-radius: 1rem; border: 1px solid rgba(255,255,255,0.08); display: flex; flex-direction: column; gap: 0.75rem; transition: all 0.2s; background: rgba(255,255,255,0.03); }
.booking-card:hover { border-color: rgba(212,168,67,0.25); }
.booking-num { padding: 0.25rem 0.625rem; border-radius: 0.5rem; font-size: 0.75rem; font-family: monospace; font-weight: 700; margin-left: 0.5rem; background: rgba(212,168,67,0.1); color: var(--gold); }
.booking-pnr { padding: 0.25rem 0.625rem; border-radius: 0.5rem; font-size: 0.75rem; font-family: monospace; background: rgba(255,255,255,0.08); color: white; }
.booking-amounts { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.5rem; text-align: center; font-size: 0.75rem; }
.booking-amount { padding: 0.5rem; border-radius: 0.75rem; border: 1px solid rgba(255,255,255,0.06); background: rgba(255,255,255,0.03); }
.booking-amount--green { background: rgba(34,197,94,0.05); border-color: rgba(34,197,94,0.1); }
.booking-amount--red { background: rgba(239,68,68,0.05); border-color: rgba(239,68,68,0.1); }
.status-badge { padding: 0.125rem 0.5rem; border-radius: 0.25rem; font-size: 0.625rem; font-weight: 700; }
.status-badge--amber { background: rgba(245,158,11,0.1); color: #fbbf24; }
.status-badge--red { background: rgba(239,68,68,0.1); color: #f87171; }

/* ===================================================
   PRINT
   =================================================== */
.print-header { display: none; }

@media print {
  body * { visibility: hidden; }
  body.printing-statement .print-statement-area,
  body.printing-statement .print-statement-area * { visibility: visible; }
  body.printing-voucher .print-voucher-area,
  body.printing-voucher .print-voucher-area * { visibility: visible; }
  .print-statement-area,
  .print-voucher-area { position: absolute; left: 0; top: 0; width: 100%; background: white !important; color: #1e293b !important; }
  .print\:hidden { display: none !important; }
  .data-table th, .data-table td { border: 1px solid #e2e8f0 !important; padding: 8px 12px !important; font-size: 11px !important; color: #334155 !important; }
  .data-table th { background: #f8fafc !important; }
  .text-error { color: #dc2626 !important; }
  .text-success { color: #16a34a !important; }
}

/* CSS vars fallback */
.text-muted { color: var(--text-muted, #94a3b8); }
.text-gold { color: var(--gold, #d4a843); }
.text-error { color: var(--error, #ef4444); }
.text-success { color: var(--success, #22c55e); }
.bg-card { background: var(--card-bg, #1a1f2e); }
.bg-input { background: var(--input-bg, #0f1420); }
</style>
