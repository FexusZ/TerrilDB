CREATE VIEW v_order_totals AS
SELECT o.id AS order_id, o.customer_id, o.status,
       COUNT(i.id) AS item_count,
       SUM(i.quantity * i.unit_price_cents) AS total_cents
FROM orders o
JOIN order_items i ON i.order_id = o.id
GROUP BY o.id, o.customer_id, o.status;

CREATE VIEW v_product_sales AS
SELECT p.id AS product_id, p.sku, p.name,
       COALESCE(SUM(i.quantity), 0) AS units_sold,
       COALESCE(SUM(i.quantity * i.unit_price_cents), 0) AS revenue_cents
FROM products p
LEFT JOIN order_items i ON i.product_id = p.id
GROUP BY p.id, p.sku, p.name;
