# مكتب سفارك إيلاينًا - التصميم التنفيذي (محدث)

## نظرة عامة

نظام محاسبي متكامل لمكاتب السياحة والحجوزات يعتمد على **Double Entry Accounting**.

---

## 1. قاعدة البيانات

### 1.1 المستخدمون

#### `users`
| الحقل | النوع | الوصف |
|-------|-------|-------|
| id | BIGSERIAL PK | معرف المستخدم |
| name | VARCHAR(100) | الاسم |
| email | VARCHAR(100) | البريد الإلكتروني |
| password | VARCHAR(255) | كلمة المرور |
| role | VARCHAR(20) | admin/employee |
| is_active | BOOLEAN | نشط |
| created_at | TIMESTAMP | تاريخ الإنشاء |

#### `employees`
| الحقل | النوع | الوصف |
|-------|-------|-------|
| id | BIGSERIAL PK | |
| user_id | BIGINT FK | مستخدم |
| salary | DECIMAL(10,2) | الراتب |
| status | VARCHAR(20) | active/inactive |
| created_at | TIMESTAMP | |

---

### 1.2 العملاء

#### `customers`
| الحقل | النوع | الوصف |
|-------|-------|-------|
| id | BIGSERIAL PK | |
| name | VARCHAR(100) | الاسم |
| phone | VARCHAR(20) | الهاتف |
| type | VARCHAR(20) | individual/company |
| balance | DECIMAL(12,2) | الرصيد |
| notes | TEXT | ملاحظات |
| created_at | TIMESTAMP | |

---

### 1.3 Finance Core (القلب المالي)

#### `accounts` - الحسابات الموحدة
| الحقل | النوع | الوصف |
|-------|-------|-------|
| id | BIGSERIAL PK | |
| name | VARCHAR(100) | اسم الحساب |
| type | VARCHAR(20) | cashbox/wallet/bank |
| balance | DECIMAL(12,2) | الرصيد |
| currency | VARCHAR(10) | العملة |
| is_active | BOOLEAN | نشط |
| created_at | TIMESTAMP | |

#### `transactions` - المعاملات المالية
| الحقل | النوع | الوصف |
|-------|-------|-------|
| id | BIGSERIAL PK | |
| type | VARCHAR(20) | income/expense/transfer/refund |
| amount | DECIMAL(12,2) | المبلغ |
| module | VARCHAR(20) | flight/bus/service/online |
| related_type | VARCHAR(50) | نوع المرجع |
| related_id | BIGINT | معرف المرجع |
| from_account_id | BIGINT FK | من حساب |
| to_account_id | BIGINT FK | إلى حساب |
| created_by | BIGINT FK | الموظف |
| notes | TEXT | ملاحظات |
| created_at | TIMESTAMP | |

#### `account_entries` - دفتر القيود (Double Entry)
| الحقل | النوع | الوصف |
|-------|-------|-------|
| id | BIGSERIAL PK | |
| account_id | BIGINT FK | الحساب |
| transaction_id | BIGINT FK | المعاملة |
| debit | DECIMAL(12,2) | مدين |
| credit | DECIMAL(12,2) | دائن |
| balance_after | DECIMAL(12,2) | الرصيد بعد |
| created_at | TIMESTAMP | |

#### `transfers` - التحويلات
| الحقل | النوع | الوصف |
|-------|-------|-------|
| id | BIGSERIAL PK | |
| from_account_id | BIGINT FK | من |
| to_account_id | BIGINT FK | إلى |
| amount | DECIMAL(12,2) | المبلغ |
| transaction_id | BIGINT FK | المعاملة |
| created_by | BIGINT FK | |
| created_at | TIMESTAMP | |

---

### 1.4 Flight Module

#### `flight_bookings`
| الحقل | النوع | الوصف |
|-------|-------|-------|
| id | BIGSERIAL PK | |
| customer_id | BIGINT FK | العميل |
| employee_id | BIGINT FK | الموظف |
| booking_number | VARCHAR(20) | رقم الحجز |
| airline_name | VARCHAR(50) | شركة الطيران |
| purchase_price | DECIMAL(10,2) | سعر الشراء |
| selling_price | DECIMAL(10,2) | سعر البيع |
| status | VARCHAR(20) | pending/confirmed/cancelled/refunded |
| account_id | BIGINT FK | الحساب |
| created_by | BIGINT FK | |
| created_at | TIMESTAMP | |

#### `flight_passengers`
| الحقل | النوع | الوصف |
|-------|-------|-------|
| id | BIGSERIAL PK | |
| booking_id | BIGINT FK | الحجز |
| name | VARCHAR(100) | الاسم |
| passport_number | VARCHAR(30) | رقم الجواز |
| nationality | VARCHAR(50) | الجنسية |

#### `flight_payments`
| الحقل | النوع | الوصف |
|-------|-------|-------|
| id | BIGSERIAL PK | |
| booking_id | BIGINT FK | الحجز |
| amount | DECIMAL(12,2) | المبلغ |
| method | VARCHAR(20) | cash/transfer/mixed |
| transaction_id | BIGINT FK | المعاملة |
| created_by | BIGINT FK | |
| created_at | TIMESTAMP | |

#### `flight_refunds`
| الحقل | النوع | الوصف |
|-------|-------|-------|
| id | BIGSERIAL PK | |
| booking_id | BIGINT FK | الحجز |
| airline_penalty | DECIMAL(10,2) | غرامة الشركة |
| office_penalty | DECIMAL(10,2) | غرامة المكتب |
| refund_amount | DECIMAL(10,2) | المبلغ المردود |
| transaction_id | BIGINT FK | المعاملة |
| status | VARCHAR(20) | pending/processed |

---

### 1.5 Bus Module

#### `bus_companies`
| الحقل | النوع | الوصف |
|-------|-------|-------|
| id | BIGSERIAL PK | |
| name | VARCHAR(100) | الاسم |
| phone | VARCHAR(20) | الهاتف |

#### `bus_inventories` - مخزون التذاكر
| الحقل | النوع | الوصف |
|-------|-------|-------|
| id | BIGSERIAL PK | |
| company_id | BIGINT FK | الشركة |
| route | VARCHAR(100) | الخط |
| travel_date | DATE | تاريخ السفر |
| total_tickets | INT | إجمالي التذاكر |
| used_tickets | INT | المستخدمة |
| cost_per_ticket | DECIMAL(10,2) | السعر |

#### `bus_bookings`
| الحقل | النوع | الوصف |
|-------|-------|-------|
| id | BIGSERIAL PK | |
| customer_id | BIGINT FK | |
| inventory_id | BIGINT FK | المخزون |
| employee_id | BIGINT FK | |
| quantity | INT | العدد |
| selling_price | DECIMAL(10,2) | السعر |
| status | VARCHAR(20) | pending/paid/cancelled |
| transaction_id | BIGINT FK | |

---

### 1.6 Services Module

#### `services`
| الحقل | النوع | الوصف |
|-------|-------|-------|
| id | BIGSERIAL PK | |
| name | VARCHAR(100) | الاسم |
| category | VARCHAR(20) | hajj/umrah/visa/passport/other |
| cost_price | DECIMAL(10,2) | التكلفة |
| selling_price | DECIMAL(10,2) | السعر |

#### `service_orders`
| الحقل | النوع | الوصف |
|-------|-------|-------|
| id | BIGSERIAL PK | |
| service_id | BIGINT FK | |
| customer_id | BIGINT FK | |
| employee_id | BIGINT FK | |
| selling_price | DECIMAL(10,2) | |
| status | VARCHAR(20) | pending/in_progress/completed/cancelled |
| created_by | BIGINT FK | |
| created_at | TIMESTAMP | |

---

### 1.7 Online Services Module

#### `online_service_types`
| الحقل | النوع | الوصف |
|-------|-------|-------|
| id | BIGSERIAL PK | |
| name | VARCHAR(100) | الاسم |
| fee_type | VARCHAR(20) | fixed/percentage |
| fee_value | DECIMAL(10,2) | القيمة |

#### `online_transactions`
| الحقل | النوع | الوصف |
|-------|-------|-------|
| id | BIGSERIAL PK | |
| type_id | BIGINT FK | نوع الخدمة |
| customer_id | BIGINT FK | |
| employee_id | BIGINT FK | |
| amount | DECIMAL(10,2) | المبلغ |
| fee | DECIMAL(10,2) | العمولة |
| net_amount | DECIMAL(10,2) | الصافي |
| status | VARCHAR(20) | pending/completed/failed |
| wallet_account_id | BIGINT FK | المحفظة |
| transaction_id | BIGINT FK | |

---

### 1.8 Employee System

#### `employee_bonuses`
| الحقل | النوع | الوصف |
|-------|-------|-------|
| id | BIGSERIAL PK | |
| employee_id | BIGINT FK | الموظف |
| amount | DECIMAL(10,2) | المبلغ |
| type | VARCHAR(20) | bonus/deduction |
| reason | TEXT | السبب |
| created_by | BIGINT FK | المدير |

---

## 2. العلاقات

```
users ──────► employees
                │
customers ─────┼─────► flight_bookings ──► flight_payments ──► transactions
                │          │                    │
                │          └────► flight_passengers    │
                │                                     │
                ├─────► bus_bookings ────► transactions
                │
                ├─────► service_orders ────► transactions
                │
                └─────► online_transactions ──► transactions
                                                     │
                                              ┌──────┴──────┐
                                              ▼             ▼
                                           accounts     account_entries
                                           (الفلوس)      (دفتر القيود)
                                              │
                                        transfers
```

---

## 3. القاعدة الذهبية

> **❌ ممنوع تسجيل فلوس مباشرة**
> 
> **✅ كل فلوس لازم تمر بـ transactions**

---

## 4. الصورة النهائية للتدفق

```
Customer (العميل)
       │
       ▼
Operation (Flight / Bus / Service / Online)
       │
       ▼
Payment (دفع)
       │
       ▼
Transaction 💣 (تسجيل مالي)
       │
       ▼
Account (Cashbox / Wallet / Bank)
       │
       ▼
Reports + Profit + Bonus
```

---

## 5. البداية (Phase 1)

1. **Users + Employees + Customers** - الأساس
2. **Accounts + Transactions** - Finance Core
3. **Flight Module** - أهم موديول

---

## 6. ملاحظات تقنية

- **Database**: PostgreSQL (موصى به)
- **API**: RESTful
- **Authentication**: JWT
- **Balance Updates**: Always use transactions