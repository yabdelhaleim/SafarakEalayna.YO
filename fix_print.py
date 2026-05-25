import re

with open("resources/js/views/finance/AccountStatement.vue", "r", encoding="utf-8") as f:
    content = f.read()

# 1. Hide the modal in print mode and remove all print:* classes from it
content = re.sub(
    r'class="fixed inset-0 z-\[500\] flex items-center justify-center bg-black/90 p-4 sm:p-6 backdrop-blur-xl animate-in fade-in duration-300 print:[^"]+"',
    'class="fixed inset-0 z-[500] flex items-center justify-center bg-black/90 p-4 sm:p-6 backdrop-blur-xl animate-in fade-in duration-300 print:hidden"',
    content
)

# 2. Append the print-only div before the final </template>
print_layout = """
      <!-- Dedicated Print Layout -->
      <div v-if="selectedEntryDetails" class="hidden print:block print:w-full print:bg-white print:text-black print:font-sans" dir="rtl">
        <div class="max-w-4xl mx-auto py-8 px-6">
          <!-- Logo & Header -->
          <div class="text-center mb-10 border-b-2 border-gray-800 pb-6">
            <h1 class="text-3xl font-black text-black mb-3">سفري علينا للسياحة</h1>
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
</template>
"""

content = content.replace("</template>", print_layout)

with open("resources/js/views/finance/AccountStatement.vue", "w", encoding="utf-8") as f:
    f.write(content)

print("Applied print layout")
