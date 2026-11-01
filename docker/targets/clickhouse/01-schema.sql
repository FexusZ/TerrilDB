USE shop;

-- Même jeu que les autres cibles, à la façon ClickHouse : pas de clés étrangères ni d'unicité, une clé de tri.
CREATE TABLE categories (
    id UInt32,
    parent_id Nullable(UInt32),
    name String
) ENGINE = MergeTree ORDER BY id;

CREATE TABLE customers (
    id UInt32,
    email String,
    first_name String,
    last_name String,
    country FixedString(2),
    created_at DateTime
) ENGINE = MergeTree ORDER BY id;

CREATE TABLE products (
    id UInt32,
    category_id UInt32,
    sku String,
    name String,
    price_cents UInt32,
    stock UInt16
) ENGINE = MergeTree ORDER BY id;

CREATE TABLE orders (
    id UInt32,
    customer_id UInt32,
    status LowCardinality(String),
    ordered_at DateTime
) ENGINE = MergeTree ORDER BY (customer_id, ordered_at);

CREATE TABLE order_items (
    id UInt32,
    order_id UInt32,
    line_no UInt16,
    product_id UInt32,
    quantity UInt16,
    unit_price_cents UInt32,
    INDEX idx_order_items_product product_id TYPE minmax GRANULARITY 4
) ENGINE = MergeTree ORDER BY (order_id, line_no);
