<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_login();

$user = current_user();
$flash = get_flash();

try {
    $counts = [
        'drugs' => (int) db()->query('SELECT COUNT(*) FROM drugs WHERE is_active = 1')->fetchColumn(),
        'patients' => (int) db()->query('SELECT COUNT(*) FROM patients')->fetchColumn(),
        'prescriptions' => (int) db()->query('SELECT COUNT(*) FROM prescriptions')->fetchColumn(),
        'low_stock' => (int) db()->query('SELECT COUNT(*) FROM drugs WHERE is_active = 1 AND stock_qty <= minimum_stock')->fetchColumn(),
    ];
    $dbStatus = 'Terhubung';
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    $counts = ['drugs' => 0, 'patients' => 0, 'prescriptions' => 0, 'low_stock' => 0];
    $dbStatus = 'Perlu pemeriksaan';
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f7dbe9">
    <title>Ringkasan · <?= e(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/css/theme.css?v=1">
</head>
<body class="app-body">
<header class="app-header">
    <a class="brand" href="dashboard.php">
        <span class="brand-mark"><span></span><i></i></span>
        <span class="brand-name">Eight<span>Medi</span></span>
    </a>

    <nav class="main-nav" aria-label="Navigasi utama">
        <a class="active" href="dashboard.php">Ringkasan</a>
        <a href="master/index.php">Master data</a>
        <a href="pelayanan/index.php">Pelayanan resep</a>
        <a href="persediaan/index.php">Persediaan</a>
        <a href="laporan/index.php">Laporan</a>
        <?php if (($user['role'] ?? '') === 'ADMIN'): ?><a href="auth/users.php">Pengguna</a><?php endif; ?>
        <a href="#status">Status sistem</a>
    </nav>

    <div class="header-account">
        <div class="account-copy">
            <strong><?= e($user['full_name'] ?? 'Pengguna') ?></strong>
            <span><a href="auth/change-password.php">Ubah password</a> · Pengguna farmasi</span>
        </div>
        <div class="avatar"><?= e(mb_strtoupper(mb_substr((string) ($user['full_name'] ?? 'U'), 0, 1))) ?></div>
        <form method="post" action="auth/logout.php">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <button class="logout-button" type="submit" title="Keluar" aria-label="Keluar">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 17l5-5-5-5M15 12H3M13 3h5a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-5"></path></svg>
            </button>
        </form>
    </div>
</header>

<main class="dashboard-main">
    <?php if ($flash): ?>
        <div class="app-alert"><?= e($flash['message'] ?? '') ?></div>
    <?php endif; ?>

    <section class="welcome-row">
        <div>
            <p class="section-kicker">RUANG KERJA FARMASI</p>
            <h1>Selamat datang, <?= e($user['full_name'] ?? 'Pengguna') ?>.</h1>
            <p class="welcome-description">Ringkasan awal sistem informasi manajemen farmasi rumah sakit.</p>
        </div>
        <div class="date-chip">
            <span class="date-dot"></span>
            Lingkungan demonstrasi lokal
        </div>
    </section>

    <section class="summary-grid" aria-label="Ringkasan data">
        <article class="summary-card">
            <div class="summary-top"><span>Master obat aktif</span><span class="summary-icon icon-teal">＋</span></div>
            <strong><?= number_format($counts['drugs'], 0, ',', '.') ?></strong>
            <small>Jenis obat terdaftar</small>
        </article>
        <article class="summary-card">
            <div class="summary-top"><span>Data pasien</span><span class="summary-icon icon-blue">◎</span></div>
            <strong><?= number_format($counts['patients'], 0, ',', '.') ?></strong>
            <small>Pasien dari kiriman poli</small>
        </article>
        <article class="summary-card">
            <div class="summary-top"><span>Resep tercatat</span><span class="summary-icon icon-violet">≡</span></div>
            <strong><?= number_format($counts['prescriptions'], 0, ',', '.') ?></strong>
            <small>Resep yang diterima sistem</small>
        </article>
        <article class="summary-card summary-card-warn">
            <div class="summary-top"><span>Perlu perhatian stok</span><span class="summary-icon icon-amber">!</span></div>
            <strong><?= number_format($counts['low_stock'], 0, ',', '.') ?></strong>
            <small>Stok di bawah batas minimum</small>
        </article>
    </section>

    <section class="content-grid">
        <article class="feature-panel">
            <div class="feature-image"></div>
            <div class="feature-overlay"></div>
            <div class="feature-content">
                <span class="feature-tag">EIGHTMEDI · FARMASI</span>
                <h2>Data yang tertata mendukung pelayanan yang lebih terarah.</h2>
                <p>Kelola alur resep, persediaan, dan penerimaan obat dalam satu sistem.</p>
            </div>
            <span class="feature-credit">Foto: National Cancer Institute / Unsplash</span>
        </article>

        <article class="system-panel" id="status">
            <div class="panel-heading">
                <div>
                    <p class="section-kicker">KONDISI APLIKASI</p>
                    <h2>Status sistem</h2>
                </div>
                <span class="status-pulse"></span>
            </div>
            <div class="system-row">
                <span class="system-symbol">DB</span>
                <div><strong>Database SIMRS Farmasi</strong><small>MariaDB · <?= e($dbStatus) ?></small></div>
                <span class="status-label"><?= e($dbStatus) ?></span>
            </div>
            <div class="system-row">
                <span class="system-symbol">AU</span>
                <div><strong>Autentikasi pengguna</strong><small>Session dan password hash</small></div>
                <span class="status-label">Aktif</span>
            </div>
            <div class="system-note">
                <strong>Catatan demonstrasi</strong>
                <p>Data pasien dikelola melalui Master data; penerimaan resep, verifikasi, dan penyerahan obat melalui Pelayanan resep.</p>
            </div>
        </article>
    </section>

    <section class="module-section" id="modul">
        <div class="module-heading">
            <div>
                <p class="section-kicker">PETA PENGEMBANGAN</p>
                <h2>Modul aplikasi</h2>
            </div>
            <span class="module-caption">Dibangun bertahap dan diuji per modul</span>
        </div>
        <div class="module-grid">
            <a class="module-card module-card-link" href="master/index.php"><span class="module-number">01</span><h3>Master data</h3><p>Obat, kategori, pasien, dokter, poliklinik, dan pemasok.</p><span class="module-state">Buka master data →</span></a>
            <a class="module-card module-card-link" href="pelayanan/index.php"><span class="module-number">02</span><h3>Pelayanan resep</h3><p>Terima resep poli, verifikasi, dan catat penyerahan penuh atau sebagian.</p><span class="module-state">Buka pelayanan resep →</span></a>
            <a class="module-card module-card-link" href="persediaan/index.php"><span class="module-number">03</span><h3>Persediaan</h3><p>Penerimaan, stok keluar, dan histori pergerakan obat.</p><span class="module-state">Buka persediaan →</span></a>
            <a class="module-card module-card-link" href="laporan/index.php"><span class="module-number">04</span><h3>Laporan</h3><p>Stok, kedaluwarsa, resep, penggunaan, dan pelayanan.</p><span class="module-state">Buka laporan →</span></a>
        </div>
    </section>

    <footer class="dashboard-footer">
        <span>EightMedi · SIMRS Farmasi</span>
        <span>Proyek akademik / demonstrasi · <?= date('Y') ?></span>
    </footer>
</main>
</body>
</html>
