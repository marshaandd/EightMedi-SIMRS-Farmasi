-- ============================================================
-- SIMRS FARMASI - DATABASE SCHEMA v1.2
-- Target: MySQL 8.0+ atau MariaDB 10.4+ / InnoDB
-- Catatan:
-- 1. Tanggal kedaluwarsa disimpan sebagai satu tanggal acuan
--    pada tabel drugs (bukan per batch).
-- 2. Pengadaan menggunakan penerimaan langsung tanpa Purchase Order.
-- 3. Harga referensi disimpan satu kali pada tabel drugs.unit_price.
-- 4. Perubahan stok wajib dicatat pada stock_movements.
-- ============================================================

CREATE DATABASE IF NOT EXISTS simrs_farmasi
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE simrs_farmasi;

-- Import this file into a new/empty database. Existing tables are not dropped.

-- 1. USERS: semua pengguna memakai modul operasional yang sama; ADMIN hanya mengelola akun.
CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  full_name VARCHAR(120) NOT NULL,
  username VARCHAR(60) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('ADMIN','PETUGAS') NOT NULL DEFAULT 'PETUGAS',
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB;

-- 1A. AUTH LOGIN ATTEMPTS: rate-limit login tanpa menyimpan IP mentah.
CREATE TABLE IF NOT EXISTS auth_login_attempts (
  attempt_key CHAR(64) NOT NULL,
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  window_started_at DATETIME NOT NULL,
  locked_until DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (attempt_key),
  KEY idx_auth_login_attempts_updated (updated_at),
  KEY idx_auth_login_attempts_locked (locked_until)
) ENGINE=InnoDB;

-- 2. PATIENTS: data pasien yang diterima/dimasukkan dari simulasi kiriman poli.
CREATE TABLE IF NOT EXISTS patients (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  medical_record_no VARCHAR(40) NOT NULL,
  nik VARCHAR(30) NULL,
  full_name VARCHAR(150) NOT NULL,
  gender ENUM('L','P','UNKNOWN') NOT NULL DEFAULT 'UNKNOWN',
  birth_date DATE NULL,
  address TEXT NULL,
  phone VARCHAR(30) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_patients_medical_record_no (medical_record_no),
  UNIQUE KEY uq_patients_nik (nik),
  KEY idx_patients_full_name (full_name),
  CONSTRAINT fk_patients_created_by FOREIGN KEY (created_by) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

-- 3. POLYCLINICS: master poliklinik asal kunjungan/resep.
CREATE TABLE IF NOT EXISTS polyclinics (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  polyclinic_code VARCHAR(30) NULL,
  polyclinic_name VARCHAR(120) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_polyclinics_code (polyclinic_code),
  UNIQUE KEY uq_polyclinics_name (polyclinic_name)
) ENGINE=InnoDB;

-- 4. DOCTORS: satu dokter memiliki satu poliklinik utama.
CREATE TABLE IF NOT EXISTS doctors (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  polyclinic_id BIGINT UNSIGNED NOT NULL,
  doctor_name VARCHAR(150) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_doctors_name (doctor_name),
  KEY idx_doctors_polyclinic (polyclinic_id),
  CONSTRAINT fk_doctors_polyclinic FOREIGN KEY (polyclinic_id) REFERENCES polyclinics(id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 5. DRUG_CATEGORIES: kategori master obat.
CREATE TABLE IF NOT EXISTS drug_categories (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  category_name VARCHAR(100) NOT NULL,
  description VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_drug_categories_name (category_name)
) ENGINE=InnoDB;

-- 6. SUPPLIERS: pemasok obat.
CREATE TABLE IF NOT EXISTS suppliers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  supplier_name VARCHAR(150) NOT NULL,
  contact_person VARCHAR(120) NULL,
  phone VARCHAR(30) NULL,
  email VARCHAR(120) NULL,
  address TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_suppliers_name (supplier_name)
) ENGINE=InnoDB;

-- 7. VISITS: riwayat kunjungan pasien dari poli.
CREATE TABLE IF NOT EXISTS visits (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  visit_number VARCHAR(50) NOT NULL,
  patient_id BIGINT UNSIGNED NOT NULL,
  polyclinic_id BIGINT UNSIGNED NOT NULL,
  doctor_id BIGINT UNSIGNED NOT NULL,
  visit_date DATETIME NOT NULL,
  visit_notes TEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_visits_visit_number (visit_number),
  KEY idx_visits_patient_date (patient_id, visit_date),
  KEY idx_visits_polyclinic_date (polyclinic_id, visit_date),
  CONSTRAINT fk_visits_patient FOREIGN KEY (patient_id) REFERENCES patients(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_visits_polyclinic FOREIGN KEY (polyclinic_id) REFERENCES polyclinics(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_visits_doctor FOREIGN KEY (doctor_id) REFERENCES doctors(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_visits_created_by FOREIGN KEY (created_by) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

-- 8. DRUGS: satu stok, satu harga referensi, dan satu tanggal kedaluwarsa acuan per obat.
CREATE TABLE IF NOT EXISTS drugs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  drug_code VARCHAR(50) NOT NULL,
  drug_name VARCHAR(180) NOT NULL,
  category_id BIGINT UNSIGNED NULL,
  dosage_form VARCHAR(80) NULL,
  strength VARCHAR(80) NULL,
  unit VARCHAR(40) NOT NULL,
  unit_price DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  stock_qty DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  minimum_stock DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  expiry_date DATE NULL,
  description TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_drugs_code (drug_code),
  KEY idx_drugs_name (drug_name),
  KEY idx_drugs_category (category_id),
  KEY idx_drugs_expiry (expiry_date),
  KEY idx_drugs_stock (stock_qty, minimum_stock),
  CONSTRAINT fk_drugs_category FOREIGN KEY (category_id) REFERENCES drug_categories(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

-- 9. PRESCRIPTIONS: resep yang diterima dari poli.
CREATE TABLE IF NOT EXISTS prescriptions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  prescription_number VARCHAR(60) NOT NULL,
  visit_id BIGINT UNSIGNED NOT NULL,
  prescription_date DATETIME NOT NULL,
  status ENUM('PENDING','IN_PROCESS','PARTIALLY_DISPENSED','COMPLETED','CANCELLED') NOT NULL DEFAULT 'PENDING',
  notes TEXT NULL,
  received_by BIGINT UNSIGNED NULL,
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_prescriptions_number (prescription_number),
  KEY idx_prescriptions_visit (visit_id),
  KEY idx_prescriptions_status_date (status, prescription_date),
  CONSTRAINT fk_prescriptions_visit FOREIGN KEY (visit_id) REFERENCES visits(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_prescriptions_received_by FOREIGN KEY (received_by) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

-- 10. PRESCRIPTION_ITEMS: rincian obat, dosis, aturan pakai, dan jumlah resep.
CREATE TABLE IF NOT EXISTS prescription_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  prescription_id BIGINT UNSIGNED NOT NULL,
  drug_id BIGINT UNSIGNED NOT NULL,
  dose VARCHAR(100) NOT NULL,
  frequency VARCHAR(100) NULL,
  usage_instruction VARCHAR(255) NULL,
  quantity_prescribed DECIMAL(14,2) NOT NULL,
  item_notes VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_prescription_items_prescription (prescription_id),
  KEY idx_prescription_items_drug (drug_id),
  CONSTRAINT fk_prescription_items_prescription FOREIGN KEY (prescription_id) REFERENCES prescriptions(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_prescription_items_drug FOREIGN KEY (drug_id) REFERENCES drugs(id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 11. DISPENSINGS: satu resep dapat memiliki beberapa transaksi penyerahan.
CREATE TABLE IF NOT EXISTS dispensings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dispensing_number VARCHAR(60) NOT NULL,
  prescription_id BIGINT UNSIGNED NOT NULL,
  dispensed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  served_by BIGINT UNSIGNED NULL,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dispensings_number (dispensing_number),
  KEY idx_dispensings_prescription_date (prescription_id, dispensed_at),
  CONSTRAINT fk_dispensings_prescription FOREIGN KEY (prescription_id) REFERENCES prescriptions(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_dispensings_served_by FOREIGN KEY (served_by) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

-- 12. DISPENSING_ITEMS: jumlah aktual yang diserahkan per item resep.
CREATE TABLE IF NOT EXISTS dispensing_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dispensing_id BIGINT UNSIGNED NOT NULL,
  prescription_item_id BIGINT UNSIGNED NOT NULL,
  quantity_dispensed DECIMAL(14,2) NOT NULL,
  notes VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dispensing_item_line (dispensing_id, prescription_item_id),
  KEY idx_dispensing_items_prescription_item (prescription_item_id),
  CONSTRAINT fk_dispensing_items_dispensing FOREIGN KEY (dispensing_id) REFERENCES dispensings(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_dispensing_items_prescription_item FOREIGN KEY (prescription_item_id) REFERENCES prescription_items(id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 13. PURCHASES: penerimaan langsung dari pemasok, tanpa Purchase Order.
CREATE TABLE IF NOT EXISTS purchases (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  receipt_number VARCHAR(60) NOT NULL,
  supplier_id BIGINT UNSIGNED NOT NULL,
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  received_by BIGINT UNSIGNED NULL,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_purchases_receipt_number (receipt_number),
  KEY idx_purchases_supplier_date (supplier_id, received_at),
  CONSTRAINT fk_purchases_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_purchases_received_by FOREIGN KEY (received_by) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

-- 14. PURCHASE_ITEMS: detail obat yang diterima pada satu transaksi penerimaan.
CREATE TABLE IF NOT EXISTS purchase_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  purchase_id BIGINT UNSIGNED NOT NULL,
  drug_id BIGINT UNSIGNED NOT NULL,
  quantity_received DECIMAL(14,2) NOT NULL,
  notes VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_purchase_items_purchase (purchase_id),
  KEY idx_purchase_items_drug (drug_id),
  CONSTRAINT fk_purchase_items_purchase FOREIGN KEY (purchase_id) REFERENCES purchases(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_purchase_items_drug FOREIGN KEY (drug_id) REFERENCES drugs(id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- 15. STOCK_MOVEMENTS: ledger seluruh stok masuk/keluar/koreksi.
-- Setiap purchase_item harus mempunyai satu movement IN.
-- Setiap dispensing_item harus mempunyai satu movement OUT.
-- OPENING dan ADJUSTMENT dicatat tanpa sumber purchase/dispensing.
CREATE TABLE IF NOT EXISTS stock_movements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  drug_id BIGINT UNSIGNED NOT NULL,
  movement_type ENUM('OPENING','IN','OUT','ADJUSTMENT_IN','ADJUSTMENT_OUT') NOT NULL,
  quantity DECIMAL(14,2) NOT NULL,
  stock_before DECIMAL(14,2) NOT NULL,
  stock_after DECIMAL(14,2) NOT NULL,
  purchase_item_id BIGINT UNSIGNED NULL,
  dispensing_item_id BIGINT UNSIGNED NULL,
  notes VARCHAR(255) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_stock_movement_purchase_item (purchase_item_id),
  UNIQUE KEY uq_stock_movement_dispensing_item (dispensing_item_id),
  KEY idx_stock_movements_drug_date (drug_id, created_at),
  KEY idx_stock_movements_type_date (movement_type, created_at),
  CONSTRAINT fk_stock_movements_drug FOREIGN KEY (drug_id) REFERENCES drugs(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_stock_movements_purchase_item FOREIGN KEY (purchase_item_id) REFERENCES purchase_items(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_stock_movements_dispensing_item FOREIGN KEY (dispensing_item_id) REFERENCES dispensing_items(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_stock_movements_created_by FOREIGN KEY (created_by) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- IMPLEMENTATION NOTES
-- ============================================================
-- Aplikasi harus menjalankan transaksi database saat:
-- A. Penerimaan obat:
--    1) INSERT purchases
--    2) INSERT purchase_items
--    3) SELECT drugs.stock_qty ... FOR UPDATE
--    4) UPDATE drugs.stock_qty = stock_qty + quantity_received
--    5) INSERT stock_movements (movement_type='IN')
--    Semua langkah harus COMMIT bersama atau ROLLBACK bersama.
--
-- B. Penyerahan obat:
--    1) Validasi jumlah yang akan diserahkan terhadap sisa resep.
--    2) SELECT drugs.stock_qty ... FOR UPDATE
--    3) Pastikan stok mencukupi dan tidak negatif.
--    4) INSERT dispensings dan dispensing_items
--    5) UPDATE drugs.stock_qty = stock_qty - quantity_dispensed
--    6) INSERT stock_movements (movement_type='OUT')
--    7) Hitung ulang status resep dari total jumlah yang diserahkan.
--    Semua langkah harus COMMIT bersama atau ROLLBACK bersama.
--
-- C. Sisa resep per item:
--    quantity_prescribed - SUM(dispensing_items.quantity_dispensed)
--
-- D. Status resep:
--    PENDING: belum ada penyerahan dan belum mulai diproses.
--    IN_PROCESS: sedang diperiksa/disiapkan.
--    PARTIALLY_DISPENSED: ada penyerahan, masih ada sisa pada satu/lebih item.
--    COMPLETED: semua item resep telah terpenuhi.
--    CANCELLED: dibatalkan dengan alasan yang dicatat.
--
-- E. Validasi lintas tabel (misalnya dispensing_item harus berasal dari
--    prescription yang sama dengan dispensing header, jumlah kumulatif tidak
--    boleh melebihi resep, dan setiap item transaksi harus punya movement)
--    wajib dilakukan di service/backend dalam transaksi database.
