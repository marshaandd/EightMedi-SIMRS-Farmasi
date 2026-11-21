<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (current_user()) {
    redirect('dashboard.php');
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usernameInput = $_POST['username'] ?? '';
    $passwordInput = $_POST['password'] ?? '';
    $username = is_string($usernameInput) ? trim($usernameInput) : '';
    $password = is_string($passwordInput) ? $passwordInput : '';
    $token = $_POST['csrf_token'] ?? null;

    if (!csrf_is_valid($token)) {
        $error = 'Sesi formulir tidak valid. Muat ulang halaman lalu coba lagi.';
    } elseif ($username === '' || $password === '') {
        $error = 'Username dan password wajib diisi.';
    } elseif (strlen($username) > 60 || strlen($password) > 4096) {
        $error = 'Username atau password tidak valid.';
    } else {
        try {
            $keys = auth_login_attempt_keys($username);
            if (auth_login_is_limited($keys)) {
                $error = 'Terlalu banyak percobaan login. Silakan tunggu 15 menit sebelum mencoba kembali.';
            } else {
                $stmt = db()->prepare(
                    'SELECT id, full_name, username, password_hash, is_active, role, must_change_password
                     FROM users
                     WHERE username = :username
                     LIMIT 1'
                );
                $stmt->execute(['username' => $username]);
                $user = $stmt->fetch();

                if (
                    $user
                    && (int) $user['is_active'] === 1
                    && password_verify($password, (string) $user['password_hash'])
                ) {
                    auth_login_clear_attempts($keys);
                    session_regenerate_id(true);
                    $_SESSION = [];
                    $_SESSION['auth_user'] = [
                        'id' => (int) $user['id'],
                        'full_name' => (string) $user['full_name'],
                        'username' => (string) $user['username'],
                        'role' => (string) $user['role'],
                        'must_change_password' => (int) $user['must_change_password'],
                    ];
                    $_SESSION['auth_started_at'] = time();
                    $_SESSION['last_activity'] = time();

                    if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
                        $rehash = db()->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
                        $rehash->execute([
                            'hash' => password_hash($password, PASSWORD_DEFAULT),
                            'id' => (int) $user['id'],
                        ]);
                    }

                    $update = db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
                    $update->execute(['id' => (int) $user['id']]);

                    unset($_SESSION['csrf_token']);
                    if ((int) $user['must_change_password'] === 1) {
                        redirect('auth/change-password.php');
                    }
                    redirect('dashboard.php');
                }

                auth_login_record_failure($keys);
                $error = 'Username atau password tidak sesuai, atau akun tidak aktif.';
            }
        } catch (PDOException $exception) {
            error_log($exception->getMessage());
            $error = 'Layanan autentikasi atau database sedang bermasalah. Periksa konfigurasi dan migrasi keamanan.';
        }
    }
}

$flash = get_flash();
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f7dbe9">
    <title>Masuk · <?= e(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/login.css">
    <link rel="stylesheet" href="../assets/css/theme.css?v=1">
</head>
<body>
<main class="login-layout">
    <section class="visual-panel" aria-label="Suasana pelayanan farmasi">
        <div class="visual-image"></div>
        <div class="visual-shade"></div>

        <a class="brand brand-light" href="../index.php" aria-label="EightMedi beranda">
            <span class="brand-mark"><span></span><i></i></span>
            <span class="brand-name">Eight<span>Medi</span></span>
        </a>

        <div class="visual-copy">
            <p class="eyebrow"><span></span> PHARMACY MANAGEMENT SYSTEM</p>
            <h1>Pelayanan farmasi yang tertata, <em>dimulai dari data yang tepat.</em></h1>
            <p class="visual-description">
                Satu ruang kerja untuk mengelola resep, persediaan obat,
                penerimaan, dan pelayanan farmasi rumah sakit.
            </p>
        </div>

        <div class="visual-footer">
            <span>SIMRS FARMASI</span>
            <span class="footer-line"></span>
            <span><?= APP_ENV === 'production' ? 'SECURE WORKSPACE' : 'LOCAL DEMONSTRATION' ?></span>
        </div>
        <p class="photo-credit">Foto: National Cancer Institute / Unsplash</p>
    </section>

    <section class="form-panel">
        <div class="form-topline">
            <span class="system-label">SISTEM INFORMASI MANAJEMEN</span>
            <span class="local-status"><i></i> <?= APP_ENV === 'production' ? 'Lingkungan produksi' : 'Lingkungan lokal' ?></span>
        </div>

        <div class="login-card">
            <div class="mobile-brand brand">
                <span class="brand-mark"><span></span><i></i></span>
                <span class="brand-name">Eight<span>Medi</span></span>
            </div>

            <div class="form-heading">
                <p class="eyebrow form-eyebrow">SELAMAT DATANG</p>
                <h2>Masuk ke akun Anda</h2>
                <p>Gunakan akun yang telah terdaftar untuk melanjutkan ke ruang kerja farmasi.</p>
            </div>

            <?php if ($error !== ''): ?>
                <div class="alert alert-error" role="alert"><?= e($error) ?></div>
            <?php endif; ?>

            <?php if ($flash): ?>
                <div class="alert alert-<?= e($flash['type'] ?? 'info') ?>" role="status">
                    <?= e($flash['message'] ?? '') ?>
                </div>
            <?php endif; ?>

            <form method="post" action="login.php" class="login-form" autocomplete="on">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                <label for="username">Username</label>
                <div class="input-wrap">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3.5"></circle><path d="M5 20c.7-3.3 3.1-5 7-5s6.3 1.7 7 5"></path></svg>
                    <input
                        id="username"
                        name="username"
                        type="text"
                        value="<?= e($username) ?>"
                        placeholder="Masukkan username"
                        autocomplete="username"
                        maxlength="60"
                        required
                        autofocus
                    >
                </div>

                <div class="password-label-row">
                    <label for="password">Password</label>
                </div>
                <div class="input-wrap">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4.5" y="10" width="15" height="10" rx="2"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3"></path></svg>
                    <input
                        id="password"
                        name="password"
                        type="password"
                        placeholder="Masukkan password"
                        autocomplete="current-password"
                        required
                    >
                    <button class="toggle-password" type="button" id="togglePassword" aria-label="Tampilkan password">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.2-6 9.5-6 9.5 6 9.5 6-3.2 6-9.5 6-9.5-6-9.5-6Z"></path><circle cx="12" cy="12" r="2.5"></circle></svg>
                    </button>
                </div>

                <button class="submit-button" type="submit">
                    <span>Masuk ke aplikasi</span>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"></path></svg>
                </button>
            </form>

            <div class="security-note">
                <span class="security-icon">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 19 6v5c0 4.5-2.8 7.8-7 10-4.2-2.2-7-5.5-7-10V6l7-3Z"></path><path d="m9 12 2 2 4-4"></path></svg>
                </span>
                <p><strong>Akses terlindungi</strong><br>Jangan bagikan informasi akun kepada orang lain.</p>
            </div>
        </div>

        <footer class="form-footer">
            <span>© <?= date('Y') ?> EightMedi</span>
            <span>SIMRS Farmasi · Versi demonstrasi akademik</span>
        </footer>
    </section>
</main>
<script>
(() => {
    const button = document.getElementById('togglePassword');
    const input = document.getElementById('password');
    if (!button || !input) return;

    button.addEventListener('click', () => {
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        button.setAttribute('aria-label', show ? 'Sembunyikan password' : 'Tampilkan password');
    });
})();
</script>
</body>
</html>
