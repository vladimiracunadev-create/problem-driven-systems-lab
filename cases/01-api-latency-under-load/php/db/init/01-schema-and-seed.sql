CREATE TABLE IF NOT EXISTS customers (
    id SERIAL PRIMARY KEY,
    name TEXT NOT NULL,
    tier TEXT NOT NULL,
    region TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS orders (
    id BIGSERIAL PRIMARY KEY,
    customer_id INTEGER NOT NULL REFERENCES customers(id),
    status TEXT NOT NULL,
    total_amount NUMERIC(12,2) NOT NULL,
    created_at TIMESTAMP NOT NULL
);

CREATE TABLE IF NOT EXISTS customer_daily_summary (
    customer_id INTEGER NOT NULL REFERENCES customers(id),
    order_date DATE NOT NULL,
    total_amount NUMERIC(14,2) NOT NULL,
    order_count INTEGER NOT NULL,
    refreshed_at TIMESTAMP NOT NULL DEFAULT NOW(),
    PRIMARY KEY (customer_id, order_date)
);

CREATE TABLE IF NOT EXISTS worker_state (
    worker_name TEXT PRIMARY KEY,
    last_heartbeat TIMESTAMP NULL,
    last_status TEXT NOT NULL,
    last_duration_ms NUMERIC(14,2) NULL,
    last_message TEXT NULL
);

CREATE TABLE IF NOT EXISTS job_runs (
    id BIGSERIAL PRIMARY KEY,
    worker_name TEXT NOT NULL,
    status TEXT NOT NULL,
    started_at TIMESTAMP NOT NULL,
    finished_at TIMESTAMP NULL,
    duration_ms NUMERIC(14,2) NULL,
    rows_written INTEGER NULL,
    note TEXT NULL
);

CREATE INDEX IF NOT EXISTS idx_orders_created_customer ON orders (created_at, customer_id);
CREATE INDEX IF NOT EXISTS idx_orders_customer_created ON orders (customer_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_orders_status_created ON orders (status, created_at);
CREATE INDEX IF NOT EXISTS idx_summary_order_date_customer ON customer_daily_summary (order_date, customer_id);

INSERT INTO worker_state (worker_name, last_status, last_message)
VALUES ('report-refresh', 'init', 'worker not started yet')
ON CONFLICT (worker_name) DO NOTHING;

INSERT INTO customers (name, tier, region, created_at)
SELECT
    'Customer ' || gs,
    CASE
        WHEN gs % 10 = 0 THEN 'gold'
        WHEN gs % 3 = 0 THEN 'silver'
        ELSE 'bronze'
    END AS tier,
    CASE
        WHEN gs % 4 = 0 THEN 'north'
        WHEN gs % 4 = 1 THEN 'south'
        WHEN gs % 4 = 2 THEN 'east'
        ELSE 'west'
    END AS region,
    NOW() - ((random() * 365)::int || ' days')::interval
FROM generate_series(1, 2500) gs;

INSERT INTO orders (customer_id, status, total_amount, created_at)
SELECT
    1 + floor(random() * 2499)::int,
    CASE WHEN random() < 0.88 THEN 'paid' ELSE 'pending' END,
    round((15 + random() * 1500)::numeric, 2),
    NOW()
      - ((random() * 180)::int || ' days')::interval
      - ((random() * 86400)::int || ' seconds')::interval
FROM generate_series(1, 120000);

ANALYZE customers;
ANALYZE orders;
ANALYZE customer_daily_summary;
