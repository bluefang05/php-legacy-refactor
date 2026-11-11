-- Schema of the original module. Kept as-is on purpose: REAL money columns and
-- a TEXT status are what the legacy report already reads.
CREATE TABLE orders (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    customer    TEXT    NOT NULL,
    status      TEXT    NOT NULL,
    subtotal    REAL    NOT NULL,
    tax_rate    REAL    NOT NULL,
    discount    REAL    NOT NULL DEFAULT 0,
    created_at  TEXT    NOT NULL
);
