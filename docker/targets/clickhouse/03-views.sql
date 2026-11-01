USE shop;

CREATE VIEW v_order_totals AS
SELECT o.id AS order_id, o.customer_id AS customer_id, o.status AS status,
       count() AS item_count,
       sum(i.quantity * i.unit_price_cents) AS total_cents
FROM orders AS o
JOIN order_items AS i ON i.order_id = o.id
GROUP BY o.id, o.customer_id, o.status;

CREATE VIEW v_product_sales AS
SELECT p.id AS product_id, p.sku AS sku, p.name AS name,
       sum(i.quantity) AS units_sold,
       sum(i.quantity * i.unit_price_cents) AS revenue_cents
FROM products AS p
LEFT JOIN order_items AS i ON i.product_id = p.id
GROUP BY p.id, p.sku, p.name;
