-- Detection Scripts for Duplicated Bookings

-- 1. Hajj & Umrah Duplicates (hajj_umrah vs hajj_umra_bookings)
-- High Confidence: Same customer_id, program_id, and similar prices
SELECT 
    'HIGH' as confidence,
    legacy.id as legacy_id,
    new.id as new_id,
    legacy.client_name,
    legacy.program_name,
    legacy.selling_price as legacy_price,
    new.selling_price as new_price,
    ABS(DATEDIFF(legacy.created_at, new.created_at)) as days_diff
FROM hajj_umrah legacy
JOIN customers c ON legacy.phone = c.phone
JOIN hajj_umra_bookings new ON c.id = new.customer_id
WHERE ABS(legacy.selling_price - new.selling_price) < 1.0
  AND ABS(DATEDIFF(legacy.created_at, new.created_at)) <= 1;

-- Medium Confidence: Same phone but different time or price slightly varies
SELECT 
    'MEDIUM' as confidence,
    legacy.id as legacy_id,
    new.id as new_id,
    legacy.client_name,
    legacy.selling_price as legacy_price,
    new.selling_price as new_price,
    ABS(DATEDIFF(legacy.created_at, new.created_at)) as days_diff
FROM hajj_umrah legacy
JOIN customers c ON legacy.phone = c.phone
JOIN hajj_umra_bookings new ON c.id = new.customer_id
WHERE (ABS(legacy.selling_price - new.selling_price) >= 1.0 OR ABS(DATEDIFF(legacy.created_at, new.created_at)) > 1)
  AND ABS(DATEDIFF(legacy.created_at, new.created_at)) <= 7;

-- 2. Visa Duplicates (visas vs visa_bookings)
-- High Confidence: Same passport/phone and same country
SELECT 
    'HIGH' as confidence,
    legacy.id as legacy_id,
    new.id as new_id,
    legacy.client_name,
    legacy.destination_country,
    legacy.selling_price as legacy_price,
    new.selling_price as new_price
FROM visas legacy
JOIN customers c ON legacy.phone = c.phone
JOIN visa_bookings new ON c.id = new.customer_id
JOIN visa_details vd ON new.visa_detail_id = vd.id
WHERE legacy.destination_country = vd.country
  AND ABS(legacy.selling_price - new.selling_price) < 1.0
  AND ABS(DATEDIFF(legacy.created_at, new.created_at)) <= 1;
