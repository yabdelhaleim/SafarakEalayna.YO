# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

SafarakEalayna (سفارك إليّنا) is a travel agency management system built with Laravel 13 and PHP 8.3+. The system manages multiple travel product types (flights, buses, services) with a centralized double-entry accounting system.

## Common Commands

### Development
- `composer dev` - Start development server with queue, logs, and Vite (runs 4 processes concurrently)
- `php artisan serve` - Start development server only
- `php artisan queue:listen --tries=1 --timeout=0` - Process queued jobs
- `php artisan pail --timeout=0` - Monitor logs in real-time
- `npm run dev` - Start Vite dev server for frontend assets

### Setup
- `composer setup` - Full setup: installs dependencies, creates .env, generates key, runs migrations, builds assets

### Testing
- `composer test` - Run full test suite (equivalent to `php artisan test`)
- `php artisan test --filter TestName` - Run specific test
- Tests use SQLite in-memory database by default (see phpunit.xml)

### Database
- `php artisan migrate` - Run migrations
- `php artisan migrate:fresh --seed` - Fresh migration with seeds
- `php artisan db:seed` - Run seeders

## Architecture

### Service Layer Pattern
Controllers are thin. All business logic lives in `app/Services/` organized by domain module:
- `app/Services/Finance/` - Double-entry accounting logic
- `app/Services/Bus/` - Bus companies, inventory, bookings
- `app/Services/Flight/` - Flight bookings and payments
- `app/Services/Service/` - Service catalog and orders
- `app/Services/Online/` - Online service types and transactions
- `app/Services/Employee/` - Employee bonuses and deductions
- `app/Services/Reports/` - Reporting and analytics

When adding new features, create service classes in the appropriate module directory. Controllers should only validate requests and delegate to services.

### Double-Entry Accounting System
All financial operations flow through a centralized accounting system:
- `Transaction` model - Records all financial transactions (income, expense, transfer, refund)
- `Account` model - Financial accounts (bank, cash, customer, supplier)
- `AccountEntry` model - Individual debit/credit entries for each account
- `Transfer` model - Money transfers between accounts

Every financial operation MUST:
1. Use database transactions (`DB::transaction()`)
2. Create Transaction records with proper `type` and `module` enums
3. Create corresponding AccountEntry records (debit and credit)
4. Update account balances atomically
5. Log the operation with context

See `app/Services/Finance/AccountService.php` for examples of credit/debit operations.

### Module Organization
Models are organized by business domain in subdirectories:
- `app/Models/Bus/` - BusCompany, BusInventory, BusBooking, BusCompanyPayment
- `app/Models/Flight/` - FlightBooking, FlightPassenger, FlightPayment, FlightRefund
- `app/Models/Service/` - Service, ServiceOrder, ServicePayment
- `app/Models/Online/` - OnlineServiceType, OnlineTransaction
- `app/Models/Employee/` - Employee, EmployeeBonus
- `app/Models/` - Account, Transaction, Customer, User (shared models)

### Enums
The project uses PHP 8.3+ enums extensively for type safety. All enums are in `app/Enums/`:
- `TransactionType` - Income, Expense, Transfer, Refund
- `TransactionModule` - Flight, Bus, Service, Online, General
- `AccountType` - Bank, Cash, Customer, Supplier
- `BusBookingStatus`, `ServiceOrderStatus`, `OnlineTransactionStatus`
- `PaymentMethod`, `FinanceStatus`, and more

When working with status or type fields, use the corresponding enum rather than raw strings.

### API Responses
All API responses use the standardized `ApiResponse` helper (`app/Helpers/ApiResponse.php`):
- `ApiResponse::success($message, $data)` - Success responses
- `ApiResponse::error($message, $errors)` - Error responses
- `ApiResponse::paginated($message, $data, $paginator)` - Paginated responses

The response structure is consistent:
```json
{
  "status": true/false,
  "message": "string",
  "data": {...},
  "errors": null
}
```

### Authentication & Authorization
- Laravel Sanctum for API authentication
- Custom middleware: `active` (checks user is active), `admin` (checks admin role)
- Route groups in `routes/api.php` use `auth:sanctum`, `active`, and `admin` middleware
- Finance routes are in separate `routes/finance.php` file

### Testing
Tests are organized in standard Laravel structure:
- `tests/Feature/` - Feature tests
- `tests/Unit/` - Unit tests
- Uses SQLite in-memory database for fast tests
- All financial operations should be tested to ensure accounting integrity

### Important Patterns
- All service methods that modify data should be wrapped in `DB::transaction()`
- Use `Log::info()` to record financial operations with context
- Pagination max is 100 items per page (enforced via `min($filters['per_page'] ?? 15, 100)`)
- DateTime filters use `from_date` and `to_date` parameters
- Search filters use `search` parameter for LIKE queries
- Active/Inactive filters use `is_active` boolean parameter

### Adding New Modules
When adding a new business module (e.g., "Hotel"):
1. Create models in `app/Models/Hotel/`
2. Create service in `app/Services/Hotel/`
3. Create controller in `app/Http/Controllers/Api/V1/Hotel/`
4. Add routes to `routes/api.php` under the v1 group
5. Add enum values to `TransactionModule` if financial
6. Create migrations in `database/migrations/`
7. Add reporting endpoints if needed in `app/Services/Reports/`
