-- =====================================================================
-- OPTIONAL - only for the simulated GCash test payments in api/billing/pay.php.
-- Your repository never contained a CREATE TABLE for demo_gcash_transactions, so
-- THIS STRUCTURE WAS RECONSTRUCTED from the columns pay.php reads and writes
-- (id, reference_no, amount, used_payment_id, used_at). If you already created this
-- table before, do NOT run this file (it will do nothing harmful: IF NOT EXISTS).
-- Without the table, payments still work: they just stay "pending" until the
-- registrar verifies them.
-- =====================================================================
USE cfs_sis;

CREATE TABLE IF NOT EXISTS demo_gcash_transactions (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  reference_no    VARCHAR(30)  NOT NULL UNIQUE,
  amount          DECIMAL(10,2) NOT NULL,
  used_payment_id INT NULL,
  used_at         TIMESTAMP NULL
);
-- Add test references yourself, for example:
-- INSERT INTO demo_gcash_transactions (reference_no, amount) VALUES ('1234567890123', 5450.00);
