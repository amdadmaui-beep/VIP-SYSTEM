-- Backfill: create missing delivery_detail rows for existing deliveries
-- Use this if you already have rows in `delivery` but none in `delivery_detail`.
--
-- It will insert one `delivery_detail` row per `order_details` item for each delivery,
-- but only when that (Delivery_ID, Order_detail_ID) pair does not already exist.

USE vip_db;

INSERT INTO delivery_detail (Delivery_ID, Order_detail_ID, received_qty, damage_qty, status, created_at, updated_at)
SELECT
    d.Delivery_ID,
    od.Order_detail_ID,
    od.ordered_qty,
    0,
    'Pending',
    NOW(),
    NOW()
FROM delivery d
INNER JOIN order_details od
    ON od.Order_ID = d.Order_ID
LEFT JOIN delivery_detail dd
    ON dd.Delivery_ID = d.Delivery_ID
   AND dd.Order_detail_ID = od.Order_detail_ID
WHERE d.Order_ID IS NOT NULL
  AND dd.Delivery_Detail_ID IS NULL;

