<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_login();

function inv_render_start(string $title): void
{
    $user = current_user();
    $flash = get_flash();
    ?>
    <!doctype html>
    <html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#f7dbe9">
        <title><?= e($title) ?> · <?= e(APP_NAME) ?></title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="../assets/css/app.css">
        <link rel="stylesheet" href="../assets/css/master.css">
        <link rel="stylesheet" href="../assets/css/persediaan.css">
        <link rel="stylesheet" href="../assets/css/theme.css?v=1">
    </head>
    <body class="app-body">
    <header class="app-header">
        <a class="brand" href="../dashboard.php"><span class="brand-mark"><span></span><i></i></span><span class="brand-name">Eight<span>Medi</span></span></a>
        <nav class="main-nav" aria-label="Navigasi utama">
            <a href="../dashboard.php">Ringkasan</a>
            <a href="../master/index.php">Master data</a>
            <a href="../pelayanan/index.php">Pelayanan resep</a>
            <a href="index.php">Persediaan</a>
            <a href="../laporan/index.php">Laporan</a>
            <?php if (($user['role'] ?? '') === 'ADMIN'): ?><a href="../auth/users.php">Pengguna</a><?php endif; ?>
            <a href="../dashboard.php#status">Status sistem</a>
        </nav>
        <div class="header-account">
            <div class="account-copy"><strong><?= e($user['full_name'] ?? 'Pengguna') ?></strong><span><a href="../auth/change-password.php">Ubah password</a> · Pengguna farmasi</span></div>
            <div class="avatar"><?= e(mb_strtoupper(mb_substr((string) ($user['full_name'] ?? 'U'), 0, 1))) ?></div>
            <form method="post" action="../auth/logout.php"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button class="logout-button" type="submit" title="Keluar" aria-label="Keluar"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 17l5-5-5-5M15 12H3M13 3h5a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-5"></path></svg></button></form>
        </div>
    </header>
    <main class="dashboard-main master-main inventory-main">
        <?php if ($flash): ?>
            <div class="master-alert <?= ($flash['type'] ?? '') === 'success' ? 'is-success' : 'is-error' ?>"><?= e($flash['message'] ?? '') ?></div>
        <?php endif; ?>
        <div class="master-breadcrumb"><a href="../dashboard.php">Ringkasan</a><span>/</span><strong>Persediaan</strong><span>/</span><strong><?= e($title) ?></strong></div>
        <section class="master-title-row inventory-title-row">
            <div><p class="section-kicker">PENGELOLAAN PERSEDIAAN</p><h1><?= e($title) ?></h1><p class="welcome-description">Catat penerimaan, pengeluaran koreksi, dan pantau riwayat pergerakan stok obat.</p></div>
        </section>
    <?php
}

function inv_render_end(): void
{
    ?>
        <footer class="dashboard-footer"><span>EightMedi · SIMRS Farmasi</span><span>Persediaan · <?= date('Y') ?></span></footer>
    </main>
    </body>
    </html>
    <?php
}

function inv_qty(mixed $value): string
{
    return number_format((float) $value, 2, ',', '.');
}

function inv_datetime(?string $value): string
{
    if (!$value) return '—';
    $timestamp = strtotime($value);
    return $timestamp ? date('d/m/Y H:i', $timestamp) : '—';
}

function inv_type_label(string $type): string
{
    return [
        'OPENING' => 'Saldo awal',
        'IN' => 'Stok masuk',
        'OUT' => 'Keluar dari resep',
        'ADJUSTMENT_IN' => 'Koreksi tambah',
        'ADJUSTMENT_OUT' => 'Stok keluar / koreksi',
    ][$type] ?? $type;
}

function inv_type_class(string $type): string
{
    return in_array($type, ['IN', 'OPENING', 'ADJUSTMENT_IN'], true) ? 'movement-in' : 'movement-out';
}

function inv_valid_quantity(string $value): bool
{
    return $value !== '' && preg_match('/^\\d+(?:\\.\\d{1,2})?$/', $value) === 1 && (float) $value > 0 && (float) $value <= 999999999999.99;
}

function inv_date_input_to_sql(string $value): ?string
{
    $date = DateTime::createFromFormat('Y-m-d\\TH:i', $value);
    if (!$date || $date->format('Y-m-d\\TH:i') !== $value) return null;
    return $date->format('Y-m-d H:i:s');
}
