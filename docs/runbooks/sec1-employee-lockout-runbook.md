# Runbook: SEC-1 Employee Lockout Remediation (B-02)

**تاريخ الإصلاح:** 2026-08-21  
**الإصدار المتأثر:** كل نسخة تحتوي على تغيير `UserPermissions::effectiveFor()` (SEC-1)  
**الأولوية:** MANDATORY — يجب تنفيذ هذا الـ Seeder فور اكتمال الديبلوي

---

## المشكلة

بعد تطبيق إصلاح SEC-1، نظام الصلاحيات أصبح **deny-by-default**:

- قبل الإصلاح: الموظف بدون صلاحيات مخزنة يحصل تلقائياً على الصلاحيات الافتراضية لدوره.  
- بعد الإصلاح: الموظف بدون صلاحيات مخزنة (`permissions = null` أو `[]`) يحصل على **لا شيء** → يرفض أي طلب يحتاج `manage_treasury`.

**النتيجة:** أي موظف أو مدير موجود في قاعدة البيانات بدون `permissions` صريحة سيحصل على **403 Forbidden** على كل عمليات Wallet وFawry.

---

## الإجراء المطلوب بعد الديبلوي مباشرةً

### 1. التحقق قبل الديبلوي

```bash
# افحص كم موظف بدون صلاحيات
php artisan tinker --execute="
echo DB::table('users')
    ->whereIn('role', ['employee','manager'])
    ->where('is_active', true)
    ->whereRaw(\"(permissions IS NULL OR JSON_LENGTH(permissions) = 0)\")
    ->count();
"
```

إذا كانت النتيجة > 0، يجب تشغيل الـ Seeder.

### 2. تشغيل الـ Seeder على Staging أولاً

```bash
# على staging server
php artisan db:seed --class=GrantTreasuryPermissionSeeder
```

**ثم تحقق:**

```bash
# يجب أن تعود نتيجة 0 (لا يوجد موظف بدون صلاحيات)
php artisan tinker --execute="
echo DB::table('users')
    ->whereIn('role', ['employee','manager'])
    ->where('is_active', true)
    ->whereRaw(\"(permissions IS NULL OR JSON_LENGTH(permissions) = 0)\")
    ->count();
"
```

### 3. اختبار وظيفي على Staging

```bash
# 1. احصل على token لموظف (مش admin)
curl -X POST https://staging.safarakealayna.com/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email": "EMPLOYEE_EMAIL", "password": "EMPLOYEE_PASSWORD"}'

# 2. احفظ الـ token واختبر wallet create
curl -X POST https://staging.safarakealayna.com/api/v1/wallet/transactions \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"wallet_type_id":1,"wallet_account_id":1,"cash_account_id":2,"amount":100,"direction":"receive"}'

# المتوقع: 201 Created (مش 403)
```

### 4. تشغيل على Production

```bash
# فقط بعد نجاح staging
php artisan db:seed --class=GrantTreasuryPermissionSeeder
```

---

## ملاحظات مهمة

| | |
|---|---|
| **الأمان** | الـ Seeder يمنح نفس الصلاحيات القديمة — لا تصعيد في الصلاحيات |
| **الأيدمبوتنسي** | تشغيل الـ Seeder مرتين آمن — لن يعدّل من لديه صلاحيات مسبقة |
| **ما لا يغيره** | لا يمس بيانات المعاملات، الحسابات، أو الإعدادات |
| **موظفون جدد** | أي موظف **جديد** بعد SEC-1 يحتاج الأدمن يمنحه صلاحيات يدوياً من لوحة التحكم |

---

## إذا نسيت الـ Seeder وظهرت شكاوى 403

```bash
# تشغيل سريع في أي وقت
php artisan db:seed --class=GrantTreasuryPermissionSeeder

# أو منح موظف واحد بسرعة
php artisan tinker --execute="
\$user = \App\Models\User::where('email','EMPLOYEE_EMAIL')->firstOrFail();
\$user->permissions = \App\Support\UserPermissions::defaultEmployeeModules();
\$user->save();
echo 'Done: ' . json_encode(\$user->permissions);
"
```

---

## Rollback (إذا أردت التراجع عن SEC-1)

> ⚠️ **لا يُنصح بذلك** — SEC-1 هو إصلاح أمني مهم.

إذا كان لا بد من التراجع مؤقتاً:

```php
// في UserPermissions::effectiveFor() — سطر 165-167
// غيّر:
return $stored;
// إلى:
return $stored !== [] ? $stored : self::defaultEmployeeModules();
```

---

*هذا الـ Runbook جزء من تدقيق Office Division 2026-08-21. المرجع: B-02 في OFFICE_DIVISION_FULL_PRODUCTION_READINESS_AUDIT.md*
