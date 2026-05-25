import re

with open("resources/js/views/finance/AccountStatement.vue", "r", encoding="utf-8") as f:
    content = f.read()

replacements = [
    (r'<label class="text-\[10px\] font-black text-text-muted uppercase tracking-widest px-1 mb-1 block">القسم الرئيسي \(سياحة / مكتب\)</label>\s*<select v-model="accountModuleTypeFilter"', 
     r'<label for="accountModuleTypeFilter" class="text-[10px] font-black text-text-muted uppercase tracking-widest px-1 mb-1 block">القسم الرئيسي (سياحة / مكتب)</label>\n              <select id="accountModuleTypeFilter" name="accountModuleTypeFilter" v-model="accountModuleTypeFilter"'),
     
    (r'<label class="text-\[10px\] font-black text-text-muted uppercase tracking-widest px-1 mb-1 block">الموديول / القسم الفرعي</label>\s*<select v-model="accountModuleFilter"',
     r'<label for="accountModuleFilter" class="text-[10px] font-black text-text-muted uppercase tracking-widest px-1 mb-1 block">الموديول / القسم الفرعي</label>\n              <select id="accountModuleFilter" name="accountModuleFilter" v-model="accountModuleFilter"'),
     
    (r'<label class="text-\[10px\] font-black text-text-muted uppercase tracking-widest px-1 mb-1 block">اختر الحساب المالي \(المحفظة\)</label>\s*<select \s*v-model="selectedAccountId"',
     r'<label for="selectedAccountId" class="text-[10px] font-black text-text-muted uppercase tracking-widest px-1 mb-1 block">اختر الحساب المالي (المحفظة)</label>\n              <select id="selectedAccountId" name="selectedAccountId" \n                v-model="selectedAccountId"'),
     
    (r'<label class="text-\[10px\] font-black text-text-muted uppercase tracking-widest px-1 mb-1 block">فئة العميل</label>\s*<select v-model="selectedCustomerType"',
     r'<label for="selectedCustomerType" class="text-[10px] font-black text-text-muted uppercase tracking-widest px-1 mb-1 block">فئة العميل</label>\n              <select id="selectedCustomerType" name="selectedCustomerType" v-model="selectedCustomerType"'),
     
    (r'<label class="text-\[10px\] font-black text-text-muted uppercase tracking-widest px-1 mb-1 block">اختر العميل / الشركة</label>\s*<select \s*v-model="targetCustomerId"',
     r'<label for="targetCustomerId" class="text-[10px] font-black text-text-muted uppercase tracking-widest px-1 mb-1 block">اختر العميل / الشركة</label>\n              <select id="targetCustomerId" name="targetCustomerId" \n                v-model="targetCustomerId"'),
     
    (r'<input \s*v-model="filters.search"\s*type="text"',
     r'<input id="filtersSearch" name="filtersSearch"\n            v-model="filters.search"\n            type="text"'),
     
    (r'<label class="text-\[10px\] font-black text-text-muted uppercase tracking-widest px-1">الموديول / القسم</label>\s*<select v-model="filters.module"',
     r'<label for="filtersModule" class="text-[10px] font-black text-text-muted uppercase tracking-widest px-1">الموديول / القسم</label>\n          <select id="filtersModule" name="filtersModule" v-model="filters.module"'),
     
    (r'<label class="text-\[10px\] font-black text-text-muted uppercase tracking-widest px-1">نوع الحركة</label>\s*<select v-model="filters.type"',
     r'<label for="filtersType" class="text-[10px] font-black text-text-muted uppercase tracking-widest px-1">نوع الحركة</label>\n          <select id="filtersType" name="filtersType" v-model="filters.type"'),
     
    (r'<label class="text-\[10px\] font-black text-text-muted uppercase tracking-widest px-1">من تاريخ</label>\s*<input v-model="filters.from_date"',
     r'<label for="filtersFromDate" class="text-[10px] font-black text-text-muted uppercase tracking-widest px-1">من تاريخ</label>\n            <input id="filtersFromDate" name="filtersFromDate" v-model="filters.from_date"'),
     
    (r'<label class="text-\[10px\] font-black text-text-muted uppercase tracking-widest px-1">إلى تاريخ</label>\s*<input v-model="filters.to_date"',
     r'<label for="filtersToDate" class="text-[10px] font-black text-text-muted uppercase tracking-widest px-1">إلى تاريخ</label>\n            <input id="filtersToDate" name="filtersToDate" v-model="filters.to_date"'),
     
    (r'<label class="text-\[10px\] font-black text-text-muted uppercase tracking-widest px-1">الحساب المستهدف</label>\s*<div class="relative group">\s*<select \s*v-model="transfer.to_account_id"',
     r'<label for="transferToAccountId" class="text-[10px] font-black text-text-muted uppercase tracking-widest px-1">الحساب المستهدف</label>\n                <div class="relative group">\n                  <select id="transferToAccountId" name="transferToAccountId"\n                    v-model="transfer.to_account_id"'),
     
    (r'<label class="text-\[10px\] font-black text-text-muted uppercase tracking-widest px-1">المبلغ</label>\s*<div class="relative group">\s*<input \s*v-model.number="transfer.amount"',
     r'<label for="transferAmount" class="text-[10px] font-black text-text-muted uppercase tracking-widest px-1">المبلغ</label>\n                  <div class="relative group">\n                    <input id="transferAmount" name="transferAmount" \n                      v-model.number="transfer.amount"'),
     
    (r'<label class="text-\[10px\] font-black text-text-muted uppercase tracking-widest px-1">ملاحظات العملية</label>\s*<textarea \s*v-model="transfer.notes"',
     r'<label for="transferNotes" class="text-[10px] font-black text-text-muted uppercase tracking-widest px-1">ملاحظات العملية</label>\n                <textarea id="transferNotes" name="transferNotes" \n                  v-model="transfer.notes"'),
     
    (r'<label class="text-\[10px\] font-black text-text-muted uppercase tracking-widest px-1">المبلغ \*</label>\s*<div class="relative group">\s*<input \s*v-model.number="quickTransaction.amount"',
     r'<label for="quickTransactionAmount" class="text-[10px] font-black text-text-muted uppercase tracking-widest px-1">المبلغ *</label>\n                  <div class="relative group">\n                    <input id="quickTransactionAmount" name="quickTransactionAmount"\n                      v-model.number="quickTransaction.amount"'),
     
    (r'<label class="text-\[10px\] font-black text-text-muted uppercase tracking-widest px-1">تاريخ المعاملة</label>\s*<input \s*v-model="quickTransaction.date"',
     r'<label for="quickTransactionDate" class="text-[10px] font-black text-text-muted uppercase tracking-widest px-1">تاريخ المعاملة</label>\n                  <input id="quickTransactionDate" name="quickTransactionDate"\n                    v-model="quickTransaction.date"'),
     
    (r'<label class="text-\[10px\] font-black text-text-muted uppercase tracking-widest px-1">الوصف والبيان \*</label>\s*<input \s*v-model="quickTransaction.description"',
     r'<label for="quickTransactionDescription" class="text-[10px] font-black text-text-muted uppercase tracking-widest px-1">الوصف والبيان *</label>\n                  <input id="quickTransactionDescription" name="quickTransactionDescription"\n                    v-model="quickTransaction.description"'),
     
    (r'<label class="text-\[10px\] font-black text-text-muted uppercase tracking-widest px-1">رقم المرجع</label>\s*<input \s*v-model="quickTransaction.reference"',
     r'<label for="quickTransactionReference" class="text-[10px] font-black text-text-muted uppercase tracking-widest px-1">رقم المرجع</label>\n                  <input id="quickTransactionReference" name="quickTransactionReference"\n                    v-model="quickTransaction.reference"'),
     
    (r'<label class="text-\[10px\] font-black text-text-muted uppercase tracking-widest px-1">ملاحظات إضافية</label>\s*<textarea \s*v-model="quickTransaction.notes"',
     r'<label for="quickTransactionNotes" class="text-[10px] font-black text-text-muted uppercase tracking-widest px-1">ملاحظات إضافية</label>\n                <textarea id="quickTransactionNotes" name="quickTransactionNotes"\n                  v-model="quickTransaction.notes"')
]

new_content = content
for pattern, repl in replacements:
    new_content = re.sub(pattern, repl, new_content, count=1)

with open("resources/js/views/finance/AccountStatement.vue", "w", encoding="utf-8") as f:
    f.write(new_content)

print("Applied replacements")
