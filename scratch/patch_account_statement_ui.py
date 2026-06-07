from pathlib import Path

p = Path('resources/js/views/finance/AccountStatement.vue')
text = p.read_text(encoding='utf-8')

pairs = [
    (
        '    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 print:px-0">',
        '    <div class="statement-page mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8 print:px-0">',
    ),
    (
        '              <p class="text-[11px] font-black uppercase tracking-[0.2em] text-gold/90">سجل المعاملات المالية</p>',
        '              <p class="text-sm font-black uppercase tracking-[0.15em] text-gold/90">سجل المعاملات المالية</p>',
    ),
    (
        '            <h1 class="font-display text-4xl font-black tracking-tight text-text-main mt-1">كشف الحساب التفصيلي</h1>',
        "            <h1 class=\"font-display text-3xl font-black tracking-tight text-text-main mt-1 sm:text-4xl\">كشف الحساب التفصيلي</h1>\n            <p v-if=\"statementTargetType === 'account' && account\" class=\"mt-2 text-lg font-black text-gold\">{{ account.name }} · {{ account.currency }}</p>",
    ),
    (
        '          class="px-6 py-3.5 rounded-2xl font-black text-sm transition-all duration-500 relative overflow-hidden flex items-center gap-2.5"',
        '          class="px-6 py-3.5 rounded-2xl font-black text-base transition-all duration-500 relative overflow-hidden flex items-center gap-2.5"',
    ),
    (
        '                <span class="text-xs font-black uppercase tracking-widest">رصيد أول المدة</span>',
        '                <span class="text-sm font-black">رصيد أول المدة</span>',
    ),
    (
        '              <p class="font-mono text-2xl font-black text-text-main">{{ formatCurrency(stats.opening_balance, account.currency) }}</p>',
        '              <p class="font-mono text-3xl font-black text-text-main">{{ formatCurrency(stats.opening_balance, account.currency) }}</p>',
    ),
    (
        '                <span class="text-xs font-black uppercase tracking-widest">إجمالي الإيداعات</span>',
        '                <span class="text-sm font-black">إجمالي الإيداعات</span>',
    ),
    (
        '              <p class="font-mono text-2xl font-black text-text-main">{{ formatCurrency(stats.period_credit, account.currency) }}</p>',
        '              <p class="font-mono text-3xl font-black text-success">{{ formatCurrency(stats.period_credit, account.currency) }}</p>',
    ),
    (
        '                <span class="text-xs font-black uppercase tracking-widest">إجمالي المسحوبات</span>',
        '                <span class="text-sm font-black">إجمالي المسحوبات</span>',
    ),
    (
        '              <p class="font-mono text-2xl font-black text-text-main">{{ formatCurrency(stats.period_debit, account.currency) }}</p>',
        '              <p class="font-mono text-3xl font-black text-error">{{ formatCurrency(stats.period_debit, account.currency) }}</p>',
    ),
    (
        '                <span class="text-xs font-black uppercase tracking-widest">رصيد آخر المدة</span>',
        '                <span class="text-sm font-black">رصيد آخر المدة</span>',
    ),
    (
        "              <p class=\"font-mono text-3xl font-black\" :class=\"stats.closing_balance >= 0 ? 'text-success' : 'text-error'\">",
        "              <p class=\"font-mono text-4xl font-black\" :class=\"stats.closing_balance >= 0 ? 'text-success' : 'text-error'\">",
    ),
    (
        '      <!-- Filters & Search -->\n      <div class="mt-8 grid grid-cols-1 md:grid-cols-12 gap-4 items-end print:hidden">',
        '      <!-- Filters & Search -->\n      <div class="flight-panel mt-2 print:hidden">\n        <h3 class="mb-4 text-lg font-black text-text-main">تصفية كشف الحساب</h3>\n        <div class="grid grid-cols-1 md:grid-cols-12 gap-4 items-end">',
    ),
    (
        '            class="flight-input w-full pr-12 font-bold"',
        '            class="stmt-filter-input flight-input w-full pr-12 font-bold"',
    ),
    (
        '          <label for="filtersModule" class="text-[10px] font-black text-text-muted uppercase tracking-widest px-1">الموديول / القسم</label>\n          <select id="filtersModule" name="filtersModule" v-model="filters.module" class="flight-select w-full !py-2.5 text-xs font-bold" @change="fetchStatement">',
        '          <label for="filtersModule" class="stmt-filter-label">المودiول / القسم</label>\n          <select id="filtersModule" name="filtersModule" v-model="filters.module" class="stmt-filter-select flight-select w-full" @change="fetchStatement">',
    ),
    (
        '          <label for="filtersType" class="text-[10px] font-black text-text-muted uppercase tracking-widest px-1">نوع الحركة</label>\n          <select id="filtersType" name="filtersType" v-model="filters.type" class="flight-select w-full !py-2.5 text-xs font-bold" @change="fetchStatement">',
        '          <label for="filtersType" class="stmt-filter-label">نوع الحركة</label>\n          <select id="filtersType" name="filtersType" v-model="filters.type" class="stmt-filter-select flight-select w-full" @change="fetchStatement">',
    ),
    (
        '            <label for="filtersFromDate" class="text-[10px] font-black text-text-muted uppercase tracking-widest px-1">من تاريخ</label>\n            <input id="filtersFromDate" name="filtersFromDate" v-model="filters.from_date" type="date" class="flight-input !py-2.5 text-[11px] font-bold" @change="fetchStatement" />',
        '            <label for="filtersFromDate" class="stmt-filter-label">من تاريخ</label>\n            <input id="filtersFromDate" name="filtersFromDate" v-model="filters.from_date" type="date" class="stmt-filter-input flight-input w-full" @change="fetchStatement" />',
    ),
    (
        '            <label for="filtersToDate" class="text-[10px] font-black text-text-muted uppercase tracking-widest px-1">إلى تاريخ</label>\n            <input id="filtersToDate" name="filtersToDate" v-model="filters.to_date" type="date" class="flight-input !py-2.5 text-[11px] font-bold" @change="fetchStatement" />',
        '            <label for="filtersToDate" class="stmt-filter-label">إلى تاريخ</label>\n            <input id="filtersToDate" name="filtersToDate" v-model="filters.to_date" type="date" class="stmt-filter-input flight-input w-full" @change="fetchStatement" />',
    ),
    (
        '        </div>\n      </div>\n\n      <!-- Main Statement Table -->',
        '        </div>\n      </div>\n\n      <!-- Main Statement Table -->',
    ),
    (
        '          <table class="w-full text-right border-collapse">',
        '          <table class="stmt-table w-full text-right border-collapse">',
    ),
    (
        '              <tr class="bg-white/[0.03] text-[10px] font-black text-text-muted uppercase tracking-[0.2em] border-b border-white/5">',
        '              <tr class="bg-white/[0.03] border-b border-white/5">',
    ),
    (
        '            <p class="text-xs font-black text-gold uppercase tracking-[0.2em]">جاري تحديث السجل...</p>',
        '            <p class="text-base font-black text-gold">جاري تحديث السجل...</p>',
    ),
    (
        '                    <p class="text-sm font-bold">لا توجد حركات مالية مسجلة لهذا الحساب في الفترة المحددة</p>',
        '                    <p class="text-lg font-black">لا توجد حركات مالية مسجلة لهذا الحساب في الفترة المحددة</p>',
    ),
]

for old, new in pairs:
    if old not in text:
        print('MISSING:', repr(old[:70]))
    else:
        text = text.replace(old, new, 1)
        print('OK')

table_patches = [
    ('<td class="px-6 py-4 text-xs font-bold text-text-main whitespace-nowrap">', '<td class="px-6 py-5 text-base font-black text-text-main whitespace-nowrap">'),
    ('<span class="text-xs font-black text-text-main flex items-center gap-1.5">', '<span class="text-base font-black text-text-main flex items-center gap-2">'),
    ('<span class="text-[10px] text-text-muted font-bold">{{ entry.user_name', '<span class="text-sm font-bold text-text-muted">{{ entry.user_name'),
    ('<td class="px-6 py-4 font-mono text-xs text-sky-400 font-black whitespace-nowrap">', '<td class="px-6 py-5 font-mono text-base text-sky-300 font-black whitespace-nowrap">'),
    ('<p class="text-xs font-bold text-text-main">{{ entry.description', '<p class="text-base font-black text-text-main leading-snug">{{ entry.description'),
    ('class="text-[9px] px-2 py-0.5 rounded font-black uppercase"', 'class="text-sm px-2.5 py-1 rounded-lg font-black"'),
    ('class="text-[9px] px-2 py-0.5 rounded font-black bg-gold/10 text-gold"', 'class="text-sm px-2.5 py-1 rounded-lg font-black bg-gold/10 text-gold"'),
    ('<span v-if="entry.debit > 0" class="text-sm font-black text-error">', '<span v-if="entry.debit > 0" class="text-lg font-black text-error">'),
    ('<span v-if="entry.credit > 0" class="text-sm font-black text-success">', '<span v-if="entry.credit > 0" class="text-lg font-black text-success">'),
    ('<td class="px-6 py-4 font-mono text-sm font-black text-left whitespace-nowrap"', '<td class="px-6 py-5 font-mono text-lg font-black text-left whitespace-nowrap"'),
    ("<span v-if=\"statementTargetType === 'customer'\" class=\"text-[9px] block\">", "<span v-if=\"statementTargetType === 'customer'\" class=\"text-sm block font-bold\">"),
]

for old, new in table_patches:
    text = text.replace(old, new)

css_addition = """
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

"""

marker = '.flight-panel {\n  background-color:'
if '.statement-page :deep(.stmt-filter-label)' not in text:
    text = text.replace(marker, css_addition + marker)

p.write_text(text, encoding='utf-8')
print('done')
