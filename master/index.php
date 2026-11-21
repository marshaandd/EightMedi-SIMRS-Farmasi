<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_login();
$user = current_user();
$flash = get_flash();
$modules = [
    ['title'=>'Obat','description'=>'Kode, nama, kategori, harga, batas minimum, dan kedaluwarsa.','href'=>'obat.php','status'=>'Sudah tersedia','countSql'=>'SELECT COUNT(*) FROM drugs'],
    ['title'=>'Kategori obat','description'=>'Kelompok kategori untuk pengelompokan katalog obat.','href'=>'kategori.php','status'=>'Baru ditambahkan','countSql'=>'SELECT COUNT(*) FROM drug_categories'],
    ['title'=>'Supplier','description'=>'Pemasok obat, kontak, nomor telepon, email, dan alamat.','href'=>'supplier.php','status'=>'Baru ditambahkan','countSql'=>'SELECT COUNT(*) FROM suppliers'],
    ['title'=>'Dokter','description'=>'Data dokter dan relasi dengan poliklinik.','href'=>'dokter.php','status'=>'Baru ditambahkan','countSql'=>'SELECT COUNT(*) FROM doctors'],
    ['title'=>'Poliklinik','description'=>'Kode dan nama poliklinik sebagai referensi dokter.','href'=>'poliklinik.php','status'=>'Baru ditambahkan','countSql'=>'SELECT COUNT(*) FROM polyclinics'],
    ['title'=>'Pasien','description'=>'Identitas pasien, nomor rekam medis, kontak, dan alamat.','href'=>'pasien.php','status'=>'Baru ditambahkan','countSql'=>'SELECT COUNT(*) FROM patients'],
];
foreach ($modules as &$module) {
    try { $module['count'] = (int) db()->query($module['countSql'])->fetchColumn(); }
    catch (PDOException $exception) { $module['count'] = null; error_log($exception->getMessage()); }
}
unset($module);
?>
<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#f7dbe9"><title>Master Data · <?= e(APP_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="../assets/css/app.css"><link rel="stylesheet" href="../assets/css/master.css">
<link rel="stylesheet" href="../assets/css/theme.css?v=1"></head>
<body class="app-body"><header class="app-header"><a class="brand" href="../dashboard.php"><span class="brand-mark"><span></span><i></i></span><span class="brand-name">Eight<span>Medi</span></span></a><nav class="main-nav" aria-label="Navigasi utama"><a href="../dashboard.php">Ringkasan</a><a class="active" href="index.php">Master data</a><a href="../pelayanan/index.php">Pelayanan resep</a><a href="../persediaan/index.php">Persediaan</a><a href="../laporan/index.php">Laporan</a><?php if (($user['role'] ?? '') === 'ADMIN'): ?><a href="../auth/users.php">Pengguna</a><?php endif; ?><a href="../dashboard.php#status">Status sistem</a></nav><div class="header-account"><div class="account-copy"><strong><?= e($user['full_name'] ?? 'Pengguna') ?></strong><span><a href="../auth/change-password.php">Ubah password</a> · Pengguna farmasi</span></div><div class="avatar"><?= e(mb_strtoupper(mb_substr((string) ($user['full_name'] ?? 'U'), 0, 1))) ?></div><form method="post" action="../auth/logout.php"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button class="logout-button" type="submit" title="Keluar" aria-label="Keluar"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 17l5-5-5-5M15 12H3M13 3h5a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-5"></path></svg></button></form></div></header>
<main class="dashboard-main master-main"><?php if ($flash): ?><div class="master-alert <?= ($flash['type'] ?? '') === 'success' ? 'is-success' : 'is-error' ?>"><?= e($flash['message'] ?? '') ?></div><?php endif; ?>
<div class="master-breadcrumb"><a href="../dashboard.php">Ringkasan</a><span>/</span><strong>Master data</strong></div>
<section class="master-title-row"><div><p class="section-kicker">PENGELOLAAN DATA UTAMA</p><h1>Master data farmasi</h1><p class="welcome-description">Pilih data yang ingin dikelola. Perubahan disimpan langsung ke database SIMRS Farmasi.</p></div></section>
<div class="module-grid master-module-grid">
<?php foreach ($modules as $index => $module): ?><a class="module-card module-card-link" href="<?= e($module['href']) ?>"><span class="module-number"><?= sprintf('%02d', $index + 1) ?></span><h3><?= e($module['title']) ?></h3><p><?= e($module['description']) ?></p><div class="master-module-meta"><span><?= $module['count'] === null ? 'Data belum terbaca' : number_format($module['count'], 0, ',', '.') . ' data' ?></span><strong><?= e($module['status']) ?> →</strong></div></a><?php endforeach; ?>
</div>
<footer class="dashboard-footer"><span>EightMedi · SIMRS Farmasi</span><span>Master data · <?= date('Y') ?></span></footer></main></body></html>
