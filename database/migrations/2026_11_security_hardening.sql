-- EightMedi security migration v1.2
-- Target: MariaDB 10.4+ / MySQL 8.0+
-- Jalankan SATU KALI pada database simrs_farmasi yang sudah ada.
-- Backup database terlebih dahulu melalui phpMyAdmin > Export.

ALTER TABLE users
    ADD COLUMN role ENUM('ADMIN','PETUGAS') NOT NULL DEFAULT 'PETUGAS' AFTER password_hash,
    ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER role;

-- Jadikan akun tertua sebagai administrator awal.
-- Modul operasional tetap sama untuk ADMIN dan PETUGAS; hanya pengelolaan akun
-- yang dibatasi untuk administrator.
UPDATE users
SET role = 'PETUGAS', must_change_password = 0;

UPDATE users
SET role = 'ADMIN', must_change_password = 1
WHERE id = (
    SELECT first_user_id
    FROM (
        SELECT COALESCE(MIN(CASE WHEN is_active = 1 THEN id END), MIN(id)) AS first_user_id
        FROM users
    ) AS first_user
)
  AND EXISTS (SELECT 1 FROM users);

CREATE TABLE IF NOT EXISTS auth_login_attempts (
    attempt_key CHAR(64) NOT NULL,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    window_started_at DATETIME NOT NULL,
    locked_until DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (attempt_key),
    KEY idx_auth_login_attempts_updated (updated_at),
    KEY idx_auth_login_attempts_locked (locked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verifikasi:
-- SELECT id, username, role, must_change_password, is_active FROM users;
-- SHOW TABLES LIKE 'auth_login_attempts';
