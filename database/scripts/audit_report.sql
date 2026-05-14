/*
 * ================================================================
 * PRODUCTION READINESS AUDIT - SQL VALIDATION SCRIPTS
 * ================================================================
 * Generated: 2026-04-27
 * Purpose: Full System Audit for Hajj/Umra + Visa Modules
 * ================================================================
 */

-- =========================================
-- PHASE 1 — MODULE STRUCTURE VALIDATION
-- =========================================

-- 1.1 Customers Table Validation
SELECT 'customers' as table_name,
       COUNT(*) as total_records,
       COUNT(DISTINCT id) as unique_ids,
       COUNT(DISTINCT phone) as unique_phones,
       COUNT(DISTINCT passport_number) as unique_passports,
       SUM(CASE WHEN full_name IS NULL OR full_name = '' THEN 1 ELSE 0 END) as missing_names,
       SUM(CASE WHEN phone IS NULL OR phone = '' THEN 1 ELSE 0 END) as missing_phones,
       SUM(CASE WHEN passport_number IS NOT NULL AND LENGTH(passport_number) < 6 THEN 1 ELSE 0 END) as invalid_passports
FROM customers
WHERE deleted_at IS NULL;

-- 1.2 Programs Table Validation
SELECT 'programs' as table_name,
       COUNT(*) as total_records,
       COUNT(DISTINCT id) as unique_ids,
       SUM(CASE WHEN program_name IS NULL OR program_name = '' THEN 1 ELSE 0 END) as missing_names,
       SUM(CASE WHEN program_type NOT IN ('UMRA','HAJJ') THEN 1 ELSE 0 END) as invalid_types,
       SUM(CASE WHEN accommodation_type NOT IN ('SINGLE','DOUBLE','TRIPLE','QUAD') THEN 1 ELSE 0 END) as invalid_accommodation,
       SUM(CASE WHEN mecca_hotel_name IS NULL OR mecca_hotel_name = '' THEN 1 ELSE 0 END) as missing_mecca,
       SUM(CASE WHEN total_nights != mecca_nights + COALESCE(medina_nights, 0) THEN 1 ELSE 0 END) as nights_mismatch,
       SUM(CASE WHEN program_type = 'HAJJ' AND (medina_hotel_name IS NULL OR medina_nights IS NULL) THEN 1 ELSE 0 END) as hajj_missing_medina,
       SUM(CASE WHEN program_type = 'HAJJ' AND total_nights < 14 THEN 1 ELSE 0 END) as hajj_short_nights,
       SUM(CASE WHEN program_type = 'HAJJ' AND (trip_supervisor IS NULL OR trip_supervisor = '') THEN 1 ELSE 0 END) as hajj_missing_supervisor,
       SUM(CASE WHEN departure_date >= return_date THEN 1 ELSE 0 END) as invalid_dates,
       SUM(CASE WHEN booking_status NOT IN ('PENDING','CONFIRMED','WAITLIST','CANCELLED') THEN 1 ELSE 0 END) as invalid_status
FROM programs
WHERE deleted_at IS NULL;

-- 1.3 Hajj/Umra Bookings Table Validation
SELECT 'hajj_umra_bookings' as table_name,
       COUNT(*) as total_records,
       COUNT(DISTINCT id) as unique_ids,
       SUM(CASE WHEN customer_id IS NULL THEN 1 ELSE 0 END) as missing_customer,
       SUM(CASE WHEN program_id IS NULL THEN 1 ELSE 0 END) as missing_program,
       SUM(CASE WHEN status NOT IN ('PENDING','CONFIRMED','CANCELLED','REFUNDED') THEN 1 ELSE 0 END) as invalid_status,
       SUM(CASE WHEN currency != 'EGP' THEN 1 ELSE 0 END) as non_egp_currency,
       SUM(CASE WHEN selling_price < purchase_price THEN 1 ELSE 0 END) as negative_profit,
       SUM(CASE WHEN ABS(profit - (selling_price - purchase_price)) > 0.01 THEN 1 ELSE 0 END) as profit_calc_mismatch
FROM hajj_umra_bookings
WHERE deleted_at IS NULL;

-- 1.4 Visa Details Table Validation
SELECT 'visa_details' as table_name,
       COUNT(*) as total_records,
       COUNT(DISTINCT id) as unique_ids,
       SUM(CASE WHEN visa_type IS NULL OR visa_type NOT IN ('TOURIST','BUSINESS','VISIT','TRANSIT','WORK','STUDENT','UMRA','HAJJ','RESIDENCE') THEN 1 ELSE 0 END) as invalid_types,
       SUM(CASE WHEN country IS NULL OR country = '' THEN 1 ELSE 0 END) as missing_country,
       SUM(CASE WHEN entry_type NOT IN ('SINGLE','MULTIPLE','TRIPLE') THEN 1 ELSE 0 END) as invalid_entry,
       SUM(CASE WHEN status NOT IN ('DRAFT','SUBMITTED','UNDER_REVIEW','APPROVED','REJECTED','CANCELLED') THEN 1 ELSE 0 END) as invalid_status
FROM visa_details
WHERE deleted_at IS NULL;

-- 1.5 Visa Bookings Table Validation
SELECT 'visa_bookings' as table_name,
       COUNT(*) as total_records,
       COUNT(DISTINCT id) as unique_ids,
       SUM(CASE WHEN customer_id IS NULL THEN 1 ELSE 0 END) as missing_customer,
       SUM(CASE WHEN visa_detail_id IS NULL THEN 1 ELSE 0 END) as missing_visa_detail,
       SUM(CASE WHEN status NOT IN ('PENDING','IN_PROGRESS','COMPLETED','REJECTED','REFUNDED','CANCELLED') THEN 1 ELSE 0 END) as invalid_status,
       SUM(CASE WHEN currency != 'EGP' THEN 1 ELSE 0 END) as non_egp_currency,
       SUM(CASE WHEN selling_price + COALESCE(service_fee, 0) - purchase_price < 0 THEN 1 ELSE 0 END) as negative_profit,
       SUM(CASE WHEN ABS(profit - (selling_price + COALESCE(service_fee, 0) - purchase_price)) > 0.01 THEN 1 ELSE 0 END) as profit_calc_mismatch
FROM visa_bookings
WHERE deleted_at IS NULL;

-- =========================================
-- PHASE 2 — FOREIGN KEY RELATIONSHIPS
-- =========================================

-- 2.1 Check Hajj/Umra Foreign Keys
SELECT 'hajj_umra_bookings_fk' as check_name,
       COUNT(*) as broken_relations
FROM hajj_umra_bookings h
WHERE h.deleted_at IS NULL
  AND (NOT EXISTS (SELECT 1 FROM customers c WHERE c.id = h.customer_id AND c.deleted_at IS NULL)
       OR NOT EXISTS (SELECT 1 FROM programs p WHERE p.id = h.program_id AND p.deleted_at IS NULL));

-- 2.2 Check Visa Foreign Keys
SELECT 'visa_bookings_fk' as check_name,
       COUNT(*) as broken_relations
FROM visa_bookings v
WHERE v.deleted_at IS NULL
  AND (NOT EXISTS (SELECT 1 FROM customers c WHERE c.id = v.customer_id AND c.deleted_at IS NULL)
       OR NOT EXISTS (SELECT 1 FROM visa_details vd WHERE vd.id = v.visa_detail_id AND vd.deleted_at IS NULL));

-- =========================================
-- PHASE 3 — DUPLICATE DETECTION
-- =========================================

-- 3.1 Duplicate Customers (by phone)
SELECT 'duplicate_customers_phone' as duplicate_type,
       phone,
       COUNT(*) as duplicate_count,
       GROUP_CONCAT(id ORDER BY id) as duplicate_ids,
       'HIGH' as severity
FROM customers
WHERE deleted_at IS NULL
GROUP BY phone
HAVING COUNT(*) > 1;

-- 3.2 Duplicate Customers (by passport)
SELECT 'duplicate_customers_passport' as duplicate_type,
       passport_number,
       COUNT(*) as duplicate_count,
       GROUP_CONCAT(id ORDER BY id) as duplicate_ids,
       'HIGH' as severity
FROM customers
WHERE passport_number IS NOT NULL AND deleted_at IS NULL
GROUP BY passport_number
HAVING COUNT(*) > 1;

-- 3.3 Duplicate Programs
SELECT 'duplicate_programs' as duplicate_type,
       program_name,
       departure_date,
       COUNT(*) as duplicate_count,
       GROUP_CONCAT(id ORDER BY id) as duplicate_ids,
       'HIGH' as severity
FROM programs
WHERE deleted_at IS NULL
GROUP BY program_name, departure_date
HAVING COUNT(*) > 1;

-- 3.4 Duplicate Hajj/Umra Bookings
SELECT 'duplicate_hajj_umra_bookings' as duplicate_type,
       CONCAT(c.phone, ' - ', p.program_name) as identifier,
       COUNT(*) as duplicate_count,
       GROUP_CONCAT(h.id ORDER BY h.id) as duplicate_ids,
       'MEDIUM' as severity
FROM hajj_umra_bookings h
JOIN customers c ON h.customer_id = c.id
JOIN programs p ON h.program_id = p.id
WHERE h.deleted_at IS NULL
GROUP BY h.customer_id, h.program_id, DATE(h.created_at)
HAVING COUNT(*) > 1;

-- =========================================
-- PHASE 4 — ORPHAN RECORDS
-- =========================================

-- 4.1 Orphan Hajj/Umra Bookings
SELECT 'orphan_hajj_umra' as orphan_type,
       h.id,
       h.customer_id,
       h.program_id,
       h.status,
       h.selling_price
FROM hajj_umra_bookings h
LEFT JOIN customers c ON h.customer_id = c.id AND c.deleted_at IS NULL
LEFT JOIN programs p ON h.program_id = p.id AND p.deleted_at IS NULL
WHERE h.deleted_at IS NULL
  AND (c.id IS NULL OR p.id IS NULL);

-- 4.2 Orphan Visa Bookings
SELECT 'orphan_visa_bookings' as orphan_type,
       v.id,
       v.customer_id,
       v.visa_detail_id,
       v.status,
       v.selling_price
FROM visa_bookings v
LEFT JOIN customers c ON v.customer_id = c.id AND c.deleted_at IS NULL
LEFT JOIN visa_details vd ON v.visa_detail_id = vd.id AND vd.deleted_at IS NULL
WHERE v.deleted_at IS NULL
  AND (c.id IS NULL OR vd.id IS NULL);

-- =========================================
-- PHASE 5 — FINANCIAL INTEGRITY
-- =========================================

-- 5.1 Profit Calculation Mismatch - Hajj/Umra
SELECT 'hajj_umra_profit_mismatch' as issue_type,
       h.id,
       h.selling_price,
       h.purchase_price,
       h.profit,
       (h.selling_price - h.purchase_price) as calculated_profit,
       ABS(h.profit - (h.selling_price - h.purchase_price)) as difference
FROM hajj_umra_bookings h
WHERE h.deleted_at IS NULL
  AND ABS(h.profit - (h.selling_price - h.purchase_price)) > 0.01;

-- 5.2 Profit Calculation Mismatch - Visa
SELECT 'visa_profit_mismatch' as issue_type,
       v.id,
       v.selling_price,
       v.purchase_price,
       v.service_fee,
       v.profit,
       (v.selling_price + COALESCE(v.service_fee, 0) - v.purchase_price) as calculated_profit,
       ABS(v.profit - (v.selling_price + COALESCE(v.service_fee, 0) - v.purchase_price)) as difference
FROM visa_bookings v
WHERE v.deleted_at IS NULL
  AND ABS(v.profit - (v.selling_price + COALESCE(v.service_fee, 0) - v.purchase_price)) > 0.01;

-- =========================================
-- SUMMARY
-- =========================================

SELECT 'SUMMARY' as report_section,
       (SELECT COUNT(*) FROM customers WHERE deleted_at IS NULL) as customers,
       (SELECT COUNT(*) FROM programs WHERE deleted_at IS NULL) as programs,
       (SELECT COUNT(*) FROM hajj_umra_bookings WHERE deleted_at IS NULL) as hajj_umra_bookings,
       (SELECT COUNT(*) FROM visa_details WHERE deleted_at IS NULL) as visa_details,
       (SELECT COUNT(*) FROM visa_bookings WHERE deleted_at IS NULL) as visa_bookings,
       (SELECT COUNT(*) FROM hajj_umra_payments) as hajj_payments,
       (SELECT COUNT(*) FROM visa_payments) as visa_payments,
       (SELECT COUNT(*) FROM treasury_transactions) as treasury_transactions;
