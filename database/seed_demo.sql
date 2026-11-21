-- EightMedi - Seed data demonstrasi (lokal saja; akun demo wajib ganti password)
-- Target: MariaDB 10.4.32 / MySQL kompatibel
-- Aman dijalankan ulang: data yang sudah ada tidak dihapus dan stok tidak ditimpa.
-- Jalankan melalui phpMyAdmin > pilih database simrs_farmasi > tab Import.
-- Semua pasien/tenaga kesehatan/pemasok di bawah adalah data fiktif demonstrasi.
-- Stok obat dimulai dari 0.00. Tambahkan stok melalui transaksi penerimaan agar
-- setiap perubahan stok tercatat di stock_movements.

SET NAMES utf8mb4;
START TRANSACTION;

-- 1. Akun administrator demonstrasi.
-- Password awal demonstrasi: FarmasiDemo!2026 (wajib diganti saat login pertama; jangan gunakan di hosting publik).
-- password_hash() PHP menghasilkan hash bcrypt berikut.
-- Jika username sudah ada, password yang tersimpan TIDAK diubah.
INSERT IGNORE INTO users (full_name, username, password_hash, role, must_change_password, is_active)
VALUES (
    'Administrator Farmasi',
    'adminfarmasi',
    '$2y$12$Xc7X3XftKto9XnEpiphXm.3sGVxBoFI13vrCvMWoJ51PvNR.mw9V2',
    'ADMIN',
    1,
    1
);

-- 2. Poliklinik demonstrasi.
INSERT IGNORE INTO polyclinics (polyclinic_code, polyclinic_name, is_active) VALUES
('POLI-UMU', 'Poli Umum', 1),
('POLI-ANA', 'Poli Anak', 1),
('POLI-GIG', 'Poli Gigi dan Mulut', 1),
('POLI-INT', 'Poli Penyakit Dalam', 1),
('POLI-KAN', 'Poli Kandungan', 1),
('POLI-MAT', 'Poli Mata', 1);

-- 3. Dokter demonstrasi: satu dokter untuk satu poliklinik.
INSERT INTO doctors (polyclinic_id, doctor_name, is_active)
SELECT p.id, 'dr. Andi Pratama', 1
FROM polyclinics p
WHERE p.polyclinic_code = 'POLI-UMU'
  AND NOT EXISTS (SELECT 1 FROM doctors d WHERE d.polyclinic_id = p.id AND d.doctor_name = 'dr. Andi Pratama');

INSERT INTO doctors (polyclinic_id, doctor_name, is_active)
SELECT p.id, 'dr. Budi Santoso', 1
FROM polyclinics p
WHERE p.polyclinic_code = 'POLI-ANA'
  AND NOT EXISTS (SELECT 1 FROM doctors d WHERE d.polyclinic_id = p.id AND d.doctor_name = 'dr. Budi Santoso');

INSERT INTO doctors (polyclinic_id, doctor_name, is_active)
SELECT p.id, 'drg. Citra Lestari', 1
FROM polyclinics p
WHERE p.polyclinic_code = 'POLI-GIG'
  AND NOT EXISTS (SELECT 1 FROM doctors d WHERE d.polyclinic_id = p.id AND d.doctor_name = 'drg. Citra Lestari');

INSERT INTO doctors (polyclinic_id, doctor_name, is_active)
SELECT p.id, 'dr. Dimas Saputra', 1
FROM polyclinics p
WHERE p.polyclinic_code = 'POLI-INT'
  AND NOT EXISTS (SELECT 1 FROM doctors d WHERE d.polyclinic_id = p.id AND d.doctor_name = 'dr. Dimas Saputra');

INSERT INTO doctors (polyclinic_id, doctor_name, is_active)
SELECT p.id, 'dr. Eka Wulandari', 1
FROM polyclinics p
WHERE p.polyclinic_code = 'POLI-KAN'
  AND NOT EXISTS (SELECT 1 FROM doctors d WHERE d.polyclinic_id = p.id AND d.doctor_name = 'dr. Eka Wulandari');

INSERT INTO doctors (polyclinic_id, doctor_name, is_active)
SELECT p.id, 'dr. Farhan Nugraha', 1
FROM polyclinics p
WHERE p.polyclinic_code = 'POLI-MAT'
  AND NOT EXISTS (SELECT 1 FROM doctors d WHERE d.polyclinic_id = p.id AND d.doctor_name = 'dr. Farhan Nugraha');

-- 4. Kategori obat demonstrasi (9 kategori).
INSERT IGNORE INTO drug_categories (category_name, description, is_active) VALUES
('Analgesik & Antipiretik', 'Kategori demonstrasi obat pereda nyeri dan demam.', 1),
('Antibiotik', 'Kategori demonstrasi antibiotik.', 1),
('Antihistamin', 'Kategori demonstrasi obat alergi.', 1),
('Gastrointestinal', 'Kategori demonstrasi obat saluran cerna.', 1),
('Vitamin & Suplemen', 'Kategori demonstrasi vitamin dan suplemen.', 1),
('Antihipertensi', 'Kategori demonstrasi obat tekanan darah.', 1),
('Antidiabetes', 'Kategori demonstrasi obat diabetes.', 1),
('Obat Topikal', 'Kategori demonstrasi obat pemakaian luar.', 1),
('Kortikosteroid', 'Kategori demonstrasi obat kortikosteroid.', 1);

-- 5. Supplier demonstrasi. Cek nama terlebih dahulu supaya tidak menggandakan.
INSERT INTO suppliers (supplier_name, contact_person, phone, email, address, is_active)
SELECT 'PT Sehat Farma Nusantara (DEMO)', 'Rina Demo', '0800-000-0001', 'supplier1@example.test', 'Alamat demonstrasi 1', 1
WHERE NOT EXISTS (SELECT 1 FROM suppliers WHERE supplier_name = 'PT Sehat Farma Nusantara (DEMO)');

INSERT INTO suppliers (supplier_name, contact_person, phone, email, address, is_active)
SELECT 'CV Medika Persada (DEMO)', 'Agus Demo', '0800-000-0002', 'supplier2@example.test', 'Alamat demonstrasi 2', 1
WHERE NOT EXISTS (SELECT 1 FROM suppliers WHERE supplier_name = 'CV Medika Persada (DEMO)');

INSERT INTO suppliers (supplier_name, contact_person, phone, email, address, is_active)
SELECT 'PT Prima Medis Indonesia (DEMO)', 'Sari Demo', '0800-000-0003', 'supplier3@example.test', 'Alamat demonstrasi 3', 1
WHERE NOT EXISTS (SELECT 1 FROM suppliers WHERE supplier_name = 'PT Prima Medis Indonesia (DEMO)');

INSERT INTO suppliers (supplier_name, contact_person, phone, email, address, is_active)
SELECT 'CV Sumber Sehat (DEMO)', 'Bambang Demo', '0800-000-0004', 'supplier4@example.test', 'Alamat demonstrasi 4', 1
WHERE NOT EXISTS (SELECT 1 FROM suppliers WHERE supplier_name = 'CV Sumber Sehat (DEMO)');

INSERT INTO suppliers (supplier_name, contact_person, phone, email, address, is_active)
SELECT 'PT Farmasi Sentosa (DEMO)', 'Dewi Demo', '0800-000-0005', 'supplier5@example.test', 'Alamat demonstrasi 5', 1
WHERE NOT EXISTS (SELECT 1 FROM suppliers WHERE supplier_name = 'PT Farmasi Sentosa (DEMO)');

-- 6. Master obat demonstrasi. Stok awal sengaja 0.00.
-- Jika kode obat sudah ada, baris yang ada tidak ditimpa.
INSERT IGNORE INTO drugs
(drug_code, drug_name, category_id, dosage_form, strength, unit, unit_price, stock_qty, minimum_stock, expiry_date, description, is_active)
SELECT v.drug_code, v.drug_name, c.id, v.dosage_form, v.strength, v.unit,
       v.unit_price, 0.00, v.minimum_stock, v.expiry_date, 'Data obat demonstrasi.', 1
FROM (
    SELECT 'OBT-001' AS drug_code, 'Paracetamol' AS drug_name, 'Analgesik & Antipiretik' AS category_name, 'Tablet' AS dosage_form, '500 mg' AS strength, 'tablet' AS unit, 500.00 AS unit_price, 100.00 AS minimum_stock, '2027-12-31' AS expiry_date
    UNION ALL SELECT 'OBT-002', 'Ibuprofen', 'Analgesik & Antipiretik', 'Tablet', '200 mg', 'tablet', 700.00, 50.00, '2027-09-30'
    UNION ALL SELECT 'OBT-003', 'Amoxicillin', 'Antibiotik', 'Kapsul', '500 mg', 'kapsul', 1200.00, 100.00, '2027-06-30'
    UNION ALL SELECT 'OBT-004', 'Ciprofloxacin', 'Antibiotik', 'Tablet', '500 mg', 'tablet', 1500.00, 50.00, '2027-08-31'
    UNION ALL SELECT 'OBT-005', 'Cetirizine', 'Antihistamin', 'Tablet', '10 mg', 'tablet', 800.00, 50.00, '2027-11-30'
    UNION ALL SELECT 'OBT-006', 'Loratadine', 'Antihistamin', 'Tablet', '10 mg', 'tablet', 900.00, 50.00, '2027-10-31'
    UNION ALL SELECT 'OBT-007', 'Omeprazole', 'Gastrointestinal', 'Kapsul', '20 mg', 'kapsul', 1100.00, 50.00, '2027-12-31'
    UNION ALL SELECT 'OBT-008', 'Antasida suspensi', 'Gastrointestinal', 'Suspensi', '60 ml', 'botol', 8500.00, 20.00, '2027-07-31'
    UNION ALL SELECT 'OBT-009', 'Oralit', 'Gastrointestinal', 'Serbuk', 'Sachet', 'sachet', 1000.00, 50.00, '2027-12-31'
    UNION ALL SELECT 'OBT-010', 'Vitamin C', 'Vitamin & Suplemen', 'Tablet', '500 mg', 'tablet', 600.00, 100.00, '2027-12-31'
    UNION ALL SELECT 'OBT-011', 'Vitamin B Kompleks', 'Vitamin & Suplemen', 'Tablet', 'Standar', 'tablet', 700.00, 50.00, '2027-12-31'
    UNION ALL SELECT 'OBT-012', 'Amlodipine', 'Antihipertensi', 'Tablet', '5 mg', 'tablet', 900.00, 50.00, '2027-09-30'
    UNION ALL SELECT 'OBT-013', 'Captopril', 'Antihipertensi', 'Tablet', '25 mg', 'tablet', 500.00, 50.00, '2027-10-31'
    UNION ALL SELECT 'OBT-014', 'Metformin', 'Antidiabetes', 'Tablet', '500 mg', 'tablet', 700.00, 100.00, '2027-11-30'
    UNION ALL SELECT 'OBT-015', 'Glimepiride', 'Antidiabetes', 'Tablet', '2 mg', 'tablet', 1200.00, 30.00, '2027-08-31'
    UNION ALL SELECT 'OBT-016', 'Povidone Iodine', 'Obat Topikal', 'Larutan', '10%', 'botol', 12000.00, 20.00, '2027-12-31'
    UNION ALL SELECT 'OBT-017', 'Hydrocortisone cream', 'Obat Topikal', 'Krim', '1%', 'tube', 9500.00, 20.00, '2027-09-30'
    UNION ALL SELECT 'OBT-018', 'Gentamicin cream', 'Obat Topikal', 'Krim', '0.1%', 'tube', 8500.00, 20.00, '2027-10-31'
    UNION ALL SELECT 'OBT-019', 'Dexamethasone', 'Kortikosteroid', 'Tablet', '0.5 mg', 'tablet', 500.00, 30.00, '2027-11-30'
    UNION ALL SELECT 'OBT-020', 'Asam folat', 'Vitamin & Suplemen', 'Tablet', '1 mg', 'tablet', 600.00, 50.00, '2027-12-31'
) AS v
INNER JOIN drug_categories c ON c.category_name = v.category_name;

COMMIT;

-- Verifikasi setelah import:
-- SELECT COUNT(*) AS total_users FROM users WHERE username = 'adminfarmasi';
-- SELECT COUNT(*) AS total_polyclinics FROM polyclinics;
-- SELECT COUNT(*) AS total_doctors FROM doctors;
-- SELECT COUNT(*) AS total_categories FROM drug_categories;
-- SELECT COUNT(*) AS total_suppliers FROM suppliers;
-- SELECT COUNT(*) AS total_drugs FROM drugs;
-- SELECT drug_code, drug_name, stock_qty FROM drugs ORDER BY drug_code;
