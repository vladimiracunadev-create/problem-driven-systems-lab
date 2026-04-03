CREATE TABLE IF NOT EXISTS customers (
    id SERIAL PRIMARY KEY,
    name TEXT NOT NULL,
    email TEXT NOT NULL,
    segment TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS categories (
    id SERIAL PRIMARY KEY,
    name TEXT NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS products (
    id SERIAL PRIMARY KEY,
    sku TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    category_id INTEGER NOT NULL REFERENCES categories(id),
    list_price NUMERIC(10,2) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS orders (
    id BIGSERIAL PRIMARY KEY,
    customer_id INTEGER NOT NULL REFERENCES customers(id),
    status TEXT NOT NULL,
    total_amount NUMERIC(12,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL
);

CREATE TABLE IF NOT EXISTS order_items (
    id BIGSERIAL PRIMARY KEY,
    order_id BIGINT NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
    product_id INTEGER NOT NULL REFERENCES products(id),
    quantity INTEGER NOT NULL,
    unit_price NUMERIC(10,2) NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_orders_created_status ON orders (created_at DESC, status);
CREATE INDEX IF NOT EXISTS idx_orders_customer_created ON orders (customer_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_order_items_order ON order_items (order_id);
CREATE INDEX IF NOT EXISTS idx_products_category ON products (category_id);

INSERT INTO categories (name)
SELECT 'Category ' || gs
FROM generate_series(1, 24) gs
ON CONFLICT (name) DO NOTHING;

INSERT INTO customers (name, email, segment, created_at)
SELECT
    'Customer ' || gs,
    'customer' || gs || '@lab.local',
    CASE
        WHEN gs % 12 = 0 THEN 'enterprise'
        WHEN gs % 4 = 0 THEN 'mid-market'
        ELSE 'smb'
    END,
    NOW() - ((random() * 730)::int || ' days')::interval
FROM generate_series(1, 1200) gs;

INSERT INTO products (sku, name, category_id, list_price, created_at)
SELECT
    'SKU-' || lpad(gs::text, 4, '0'),
    'Product ' || gs,
    1 + ((gs - 1) % 24),
    round((15 + random() * 250)::numeric, 2),
    NOW() - ((random() * 540)::int || ' days')::interval
FROM generate_series(1, 600) gs
ON CONFLICT (sku) DO NOTHING;

INSERT INTO orders (customer_id, status, total_amount, created_at)
SELECT
    1 + floor(random() * 1199)::int,
    CASE
        WHEN random() < 0.55 THEN 'paid'
        WHEN random() < 0.85 THEN 'shipped'
        ELSE 'pending'
    END,
    0,
    NOW()
      - ((random() * 120)::int || ' days')::interval
      - ((random() * 86400)::int || ' seconds')::interval
FROM generate_series(1, 9000);

INSERT INTO order_items (order_id, product_id, quantity, unit_price)
SELECT
    o.id,
    1 + floor(random() * 599)::int,
    1 + floor(random() * 3)::int,
    round((10 + random() * 220)::numeric, 2)
FROM orders o
JOIN LATERAL generate_series(1, 2 + floor(random() * 4)::int) AS item_n ON true;

UPDATE orders o
SET total_amount = totals.total_amount
FROM (
    SELECT order_id, ROUND(SUM(quantity * unit_price), 2) AS total_amount
    FROM order_items
    GROUP BY order_id
) totals
WHERE totals.order_id = o.id;

ANALYZE customers;
ANALYZE categories;
ANALYZE products;
ANALYZE orders;
ANALYZE order_items;
