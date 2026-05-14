-- =============================================
-- SAFARAK ELAYNA - DATABASE SCHEMA (Updated)
-- Double Entry Accounting System
-- =============================================

-- =============================================
-- SECTION 1: USERS & EMPLOYEES
-- =============================================

CREATE TABLE users (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL CHECK (role IN ('admin', 'employee')),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE employees (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    salary DECIMAL(10,2) DEFAULT 0,
    status VARCHAR(20) DEFAULT 'active' CHECK (status IN ('active', 'inactive')),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =============================================
-- SECTION 2: CUSTOMERS
-- =============================================

CREATE TABLE customers (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    type VARCHAR(20) DEFAULT 'individual' CHECK (type IN ('individual', 'company')),
    balance DECIMAL(12,2) DEFAULT 0,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =============================================
-- SECTION 3: FINANCE CORE (Accounts & Transactions)
-- =============================================

CREATE TABLE accounts (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    type VARCHAR(20) NOT NULL CHECK (type IN ('cashbox', 'wallet', 'bank')),
    balance DECIMAL(12,2) DEFAULT 0,
    currency VARCHAR(10) DEFAULT 'EGP',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE transactions (
    id BIGSERIAL PRIMARY KEY,
    type VARCHAR(20) NOT NULL CHECK (type IN ('income', 'expense', 'transfer', 'refund')),
    amount DECIMAL(12,2) NOT NULL,
    module VARCHAR(20) CHECK (module IN ('flight', 'bus', 'service', 'online', 'other')),
    related_type VARCHAR(50),
    related_id BIGINT,
    from_account_id BIGINT REFERENCES accounts(id),
    to_account_id BIGINT REFERENCES accounts(id),
    created_by BIGINT REFERENCES users(id),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE account_entries (
    id BIGSERIAL PRIMARY KEY,
    account_id BIGINT NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    transaction_id BIGINT NOT NULL REFERENCES transactions(id) ON DELETE CASCADE,
    debit DECIMAL(12,2) DEFAULT 0,
    credit DECIMAL(12,2) DEFAULT 0,
    balance_after DECIMAL(12,2),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE transfers (
    id BIGSERIAL PRIMARY KEY,
    from_account_id BIGINT NOT NULL REFERENCES accounts(id),
    to_account_id BIGINT NOT NULL REFERENCES accounts(id),
    amount DECIMAL(12,2) NOT NULL,
    transaction_id BIGINT REFERENCES transactions(id),
    created_by BIGINT REFERENCES users(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT different_accounts CHECK (from_account_id != to_account_id)
);

-- =============================================
-- SECTION 4: FLIGHT MODULE
-- =============================================

CREATE TABLE flight_bookings (
    id BIGSERIAL PRIMARY KEY,
    customer_id BIGINT NOT NULL REFERENCES customers(id),
    employee_id BIGINT REFERENCES users(id),
    booking_number VARCHAR(20) UNIQUE,
    airline_name VARCHAR(50),
    purchase_price DECIMAL(10,2) NOT NULL,
    selling_price DECIMAL(10,2) NOT NULL,
    status VARCHAR(20) DEFAULT 'pending' CHECK (status IN ('pending', 'confirmed', 'cancelled', 'refunded')),
    account_id BIGINT REFERENCES accounts(id),
    created_by BIGINT REFERENCES users(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE flight_passengers (
    id BIGSERIAL PRIMARY KEY,
    booking_id BIGINT NOT NULL REFERENCES flight_bookings(id) ON DELETE CASCADE,
    name VARCHAR(100) NOT NULL,
    passport_number VARCHAR(30),
    nationality VARCHAR(50)
);

CREATE TABLE flight_payments (
    id BIGSERIAL PRIMARY KEY,
    booking_id BIGINT NOT NULL REFERENCES flight_bookings(id),
    amount DECIMAL(12,2) NOT NULL,
    method VARCHAR(20) CHECK (method IN ('cash', 'transfer', 'mixed')),
    transaction_id BIGINT REFERENCES transactions(id),
    created_by BIGINT REFERENCES users(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE flight_refunds (
    id BIGSERIAL PRIMARY KEY,
    booking_id BIGINT NOT NULL REFERENCES flight_bookings(id),
    airline_penalty DECIMAL(10,2) DEFAULT 0,
    office_penalty DECIMAL(10,2) DEFAULT 0,
    refund_amount DECIMAL(10,2) DEFAULT 0,
    transaction_id BIGINT REFERENCES transactions(id),
    status VARCHAR(20) DEFAULT 'pending' CHECK (status IN ('pending', 'processed'))
);

-- =============================================
-- SECTION 5: BUS MODULE
-- =============================================

CREATE TABLE bus_companies (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE bus_inventories (
    id BIGSERIAL PRIMARY KEY,
    company_id BIGINT NOT NULL REFERENCES bus_companies(id),
    route VARCHAR(100),
    travel_date DATE NOT NULL,
    total_tickets INT NOT NULL,
    used_tickets INT DEFAULT 0,
    cost_per_ticket DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE bus_bookings (
    id BIGSERIAL PRIMARY KEY,
    customer_id BIGINT REFERENCES customers(id),
    inventory_id BIGINT NOT NULL REFERENCES bus_inventories(id),
    employee_id BIGINT REFERENCES users(id),
    quantity INT DEFAULT 1,
    selling_price DECIMAL(10,2) NOT NULL,
    status VARCHAR(20) DEFAULT 'pending' CHECK (status IN ('pending', 'paid', 'cancelled')),
    transaction_id BIGINT REFERENCES transactions(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =============================================
-- SECTION 6: SERVICES MODULE
-- =============================================

CREATE TABLE services (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    category VARCHAR(20) CHECK (category IN ('hajj', 'umrah', 'visa', 'passport', 'other')),
    cost_price DECIMAL(10,2),
    selling_price DECIMAL(10,2),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE service_orders (
    id BIGSERIAL PRIMARY KEY,
    service_id BIGINT NOT NULL REFERENCES services(id),
    customer_id BIGINT NOT NULL REFERENCES customers(id),
    employee_id BIGINT REFERENCES users(id),
    selling_price DECIMAL(10,2) NOT NULL,
    status VARCHAR(20) DEFAULT 'pending' CHECK (status IN ('pending', 'in_progress', 'completed', 'cancelled')),
    created_by BIGINT REFERENCES users(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =============================================
-- SECTION 7: ONLINE SERVICES MODULE
-- =============================================

CREATE TABLE online_service_types (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    fee_type VARCHAR(20) CHECK (fee_type IN ('fixed', 'percentage')),
    fee_value DECIMAL(10,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE online_transactions (
    id BIGSERIAL PRIMARY KEY,
    type_id BIGINT NOT NULL REFERENCES online_service_types(id),
    customer_id BIGINT REFERENCES customers(id),
    employee_id BIGINT REFERENCES users(id),
    amount DECIMAL(10,2) NOT NULL,
    fee DECIMAL(10,2) DEFAULT 0,
    net_amount DECIMAL(10,2),
    status VARCHAR(20) DEFAULT 'pending' CHECK (status IN ('pending', 'completed', 'failed')),
    wallet_account_id BIGINT REFERENCES accounts(id),
    transaction_id BIGINT REFERENCES transactions(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =============================================
-- SECTION 8: EMPLOYEE SYSTEM
-- =============================================

CREATE TABLE employee_bonuses (
    id BIGSERIAL PRIMARY KEY,
    employee_id BIGINT NOT NULL REFERENCES users(id),
    amount DECIMAL(10,2) NOT NULL,
    type VARCHAR(20) CHECK (type IN ('bonus', 'deduction')),
    reason TEXT,
    created_by BIGINT REFERENCES users(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =============================================
-- INDEXES
-- =============================================

CREATE INDEX idx_transactions_module ON transactions(module, related_id);
CREATE INDEX idx_transactions_date ON transactions(created_at);
CREATE INDEX idx_transactions_accounts ON transactions(from_account_id, to_account_id);
CREATE INDEX idx_account_entries_account ON account_entries(account_id);
CREATE INDEX idx_flight_bookings_customer ON flight_bookings(customer_id);
CREATE INDEX idx_flight_bookings_status ON flight_bookings(status);
CREATE INDEX idx_bus_inventories_date ON bus_inventories(travel_date);
CREATE INDEX idx_customers_type ON customers(type);

-- =============================================
-- FUNCTIONS & TRIGGERS
-- =============================================

-- Function to update account balance
CREATE OR REPLACE FUNCTION update_account_balance()
RETURNS TRIGGER AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        UPDATE accounts 
        SET balance = balance + COALESCE(NEW.credit, 0) - COALESCE(NEW.debit, 0)
        WHERE id = NEW.account_id;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Trigger for account entries
CREATE TRIGGER trg_account_entries_balance
    AFTER INSERT ON account_entries
    FOR EACH ROW
    EXECUTE FUNCTION update_account_balance();

-- Function to generate booking number
CREATE OR REPLACE FUNCTION generate_booking_number()
RETURNS TRIGGER AS $$
DECLARE
    prefix VARCHAR(10);
    next_num INT;
BEGIN
    prefix := 'FL' || TO_CHAR(NOW(), 'YYMMDD');
    SELECT COALESCE(MAX(CAST(SUBSTRING(booking_number FROM 11 FOR 4) AS INT)), 0) + 1
    INTO next_num
    FROM flight_bookings
    WHERE booking_number LIKE prefix || '%';
    
    NEW.booking_number := prefix || LPAD(next_num::TEXT, 4, '0');
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Trigger for booking number
CREATE TRIGGER trg_generate_booking_number
    BEFORE INSERT ON flight_bookings
    FOR EACH ROW
    WHEN (NEW.booking_number IS NULL)
    EXECUTE FUNCTION generate_booking_number();

-- =============================================
-- VIEWS
-- =============================================

-- Daily Profit View
CREATE OR REPLACE VIEW v_daily_profit AS
SELECT
    DATE(created_at) as date,
    SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as total_income,
    SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as total_expense,
    SUM(CASE WHEN type = 'income' THEN amount ELSE -amount END) as profit
FROM transactions
WHERE type IN ('income', 'expense')
GROUP BY DATE(created_at);

-- Account Balance View
CREATE OR REPLACE VIEW v_account_balances AS
SELECT
    id,
    name,
    type,
    balance,
    currency,
    is_active
FROM accounts
WHERE is_active = TRUE
ORDER BY type, name;

-- Employee Performance View
CREATE OR REPLACE VIEW v_employee_performance AS
SELECT
    u.id,
    u.name as employee_name,
    COUNT(fb.id) as total_bookings,
    COALESCE(SUM(fb.selling_price), 0) as total_sales,
    COALESCE(SUM(fb.selling_price - fb.purchase_price), 0) as total_profit
FROM users u
LEFT JOIN flight_bookings fb ON fb.employee_id = u.id AND fb.status = 'confirmed'
WHERE u.role = 'employee' AND u.is_active = TRUE
GROUP BY u.id, u.name;

-- Flight Booking Summary
CREATE OR REPLACE VIEW v_flight_summary AS
SELECT
    fb.booking_number,
    fb.airline_name,
    fb.purchase_price,
    fb.selling_price,
    fb.selling_price - fb.purchase_price as profit,
    fb.status,
    c.name as customer_name,
    u.name as employee_name,
    fb.created_at
FROM flight_bookings fb
LEFT JOIN customers c ON c.id = fb.customer_id
LEFT JOIN users u ON u.id = fb.employee_id;