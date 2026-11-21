<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_login();

function rx_render_start(string $title, string $description = ''): void
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
        <link rel="stylesheet" href="../assets/css/pelayanan.css">
        <link rel="stylesheet" href="../assets/css/theme.css?v=1">
    </head>
    <body class="app-body">
    <header class="app-header">
        <a class="brand" href="../dashboard.php"><span class="brand-mark"><span></span><i></i></span><span class="brand-name">Eight<span>Medi</span></span></a>
        <nav class="main-nav" aria-label="Navigasi utama">
            <a href="../dashboard.php">Ringkasan</a>
            <a href="../master/index.php">Master data</a>
            <a class="active" href="index.php">Pelayanan resep</a>
            <a href="../persediaan/index.php">Persediaan</a>
            <a href="../laporan/index.php">Laporan</a>
            <?php if (($user['role'] ?? '') === 'ADMIN'): ?><a href="../auth/users.php">Pengguna</a><?php endif; ?>
        </nav>
        <div class="header-account">
            <div class="account-copy"><strong><?= e($user['full_name'] ?? 'Pengguna') ?></strong><span><a href="../auth/change-password.php">Ubah password</a> · Pengguna farmasi</span></div>
            <div class="avatar"><?= e(mb_strtoupper(mb_substr((string) ($user['full_name'] ?? 'U'), 0, 1))) ?></div>
            <form method="post" action="../auth/logout.php"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button class="logout-button" type="submit" title="Keluar" aria-label="Keluar"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 17l5-5-5-5M15 12H3M13 3h5a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-5"></path></svg></button></form>
        </div>
    </header>
    <main class="dashboard-main master-main rx-main">
        <?php if ($flash): ?>
            <div class="master-alert <?= ($flash['type'] ?? '') === 'success' ? 'is-success' : 'is-error' ?>"><?= e($flash['message'] ?? '') ?></div>
        <?php endif; ?>
        <div class="master-breadcrumb"><a href="../dashboard.php">Ringkasan</a><span>/</span><strong>Pelayanan resep</strong><span>/</span><strong><?= e($title) ?></strong></div>
        <section class="master-title-row rx-title-row">
            <div><p class="section-kicker">PELAYANAN FARMASI</p><h1><?= e($title) ?></h1><p class="welcome-description"><?= e($description) ?></p></div>
        </section>
    <?php
}

function rx_render_end(): void
{
    ?>
        <footer class="dashboard-footer"><span>EightMedi · SIMRS Farmasi</span><span>Pelayanan resep · <?= date('Y') ?></span></footer>
    </main>
    </body>
    </html>
    <?php
}

function rx_qty(mixed $value): string
{
    return number_format((float) $value, 2, ',', '.');
}

function rx_datetime(?string $value): string
{
    if (!$value) return '—';
    $timestamp = strtotime($value);
    return $timestamp ? date('d/m/Y H:i', $timestamp) : '—';
}

function rx_date_input_to_sql(string $value): ?string
{
    $date = DateTime::createFromFormat('!Y-m-d\\TH:i', $value);
    $errors = DateTime::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d\\TH:i') !== $value) {
        return null;
    }
    return $date->format('Y-m-d H:i:s');
}

function rx_valid_quantity(string $value): bool
{
    return $value !== ''
        && preg_match('/^\\d+(?:\\.\\d{1,2})?$/', $value) === 1
        && (float) $value > 0
        && (float) $value <= 999999999999.99;
}

function rx_number(string $prefix): string
{
    return $prefix . '-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
}

function rx_status_label(string $status): string
{
    return [
        'PENDING' => 'Menunggu verifikasi',
        'IN_PROCESS' => 'Sedang diproses',
        'PARTIALLY_DISPENSED' => 'Diserahkan sebagian',
        'COMPLETED' => 'Selesai',
        'CANCELLED' => 'Dibatalkan',
    ][$status] ?? $status;
}

function rx_status_class(string $status): string
{
    return [
        'PENDING' => 'rx-status-pending',
        'IN_PROCESS' => 'rx-status-process',
        'PARTIALLY_DISPENSED' => 'rx-status-partial',
        'COMPLETED' => 'rx-status-complete',
        'CANCELLED' => 'rx-status-cancelled',
    ][$status] ?? 'rx-status-pending';
}
