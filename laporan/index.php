<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_login();

$pdo = db();
$user = current_user();
$today = date('Y-m-d');
$firstDay = date('Y-m-01');

function report_valid_date(string $value, string $fallback): string
{
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    $errors = DateTime::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) {
        return $fallback;
    }
    return $value;
}

function report_qty(mixed $value): string
{
    return number_format((float) $value, 2, ',', '.');
}

function report_datetime(?string $value, bool $withTime = true): string
{
    if (!$value) return '—';
    $timestamp = strtotime($value);
    return $timestamp ? date($withTime ? 'd/m/Y H:i' : 'd/m/Y', $timestamp) : '—';
}

function report_status_label(string $status): string
{
    return [
        'PENDING' => 'Menunggu verifikasi',
        'IN_PROCESS' => 'Sedang diproses',
        'PARTIALLY_DISPENSED' => 'Diserahkan sebagian',
        'COMPLETED' => 'Selesai',
        'CANCELLED' => 'Dibatalkan',
    ][$status] ?? $status;
}

function report_movement_label(string $type): string
{
    return [
        'OPENING' => 'Saldo awal',
        'IN' => 'Stok masuk',
        'OUT' => 'Stok keluar',
        'ADJUSTMENT_IN' => 'Koreksi tambah',
        'ADJUSTMENT_OUT' => 'Koreksi keluar',
    ][$type] ?? $type;
}

function report_value(string $key, mixed $value, array $row): string
{
    if ($value === null || $value === '') return '—';
    if (in_array($key, ['tanggal', 'terakhir'], true)) return report_datetime((string) $value, true);
    if ($key === 'kedaluwarsa') return report_datetime((string) $value, false);
    if ($key === 'harga') return 'Rp ' . number_format((float) $value, 0, ',', '.');
    if (in_array($key, ['stok', 'minimum', 'jumlah', 'stok_awal', 'stok_akhir', 'total_diserahkan', 'total_diresepkan'], true)) return report_qty($value);
    if ($key === 'status') return report_status_label((string) $value);
    if ($key === 'tipe') return report_movement_label((string) $value);
    if ($key === 'jumlah_item') return number_format((int) $value, 0, ',', '.') . ' item';
    if ($key === 'transaksi' || $key === 'resep') return number_format((int) $value, 0, ',', '.');
    if ($key === 'kondisi') return (string) $value;
    return (string) $value;
}

$jenis = (string) ($_GET['jenis'] ?? 'stok');
$jenisValid = ['stok', 'mutasi', 'resep', 'penggunaan'];
if (!in_array($jenis, $jenisValid, true)) $jenis = 'stok';

$dari = report_valid_date((string) ($_GET['dari'] ?? $firstDay), $firstDay);
$sampai = report_valid_date((string) ($_GET['sampai'] ?? $today), $today);
if ($dari > $sampai) {
    [$dari, $sampai] = [$sampai, $dari];
}
$awalPeriode = $dari . ' 00:00:00';
$akhirPeriode = (new DateTimeImmutable($sampai))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
$keyword = trim((string) ($_GET['q'] ?? ''));
$keyword = mb_substr($keyword, 0, 100);
$kondisi = (string) ($_GET['kondisi'] ?? 'semua');
if (!in_array($kondisi, ['semua', 'habis', 'menipis', 'aman', 'kedaluwarsa'], true)) $kondisi = 'semua';
$arah = (string) ($_GET['arah'] ?? 'semua');
if (!in_array($arah, ['semua', 'masuk', 'keluar'], true)) $arah = 'semua';
$status = (string) ($_GET['status'] ?? 'semua');
if (!in_array($status, ['semua', 'PENDING', 'IN_PROCESS', 'PARTIALLY_DISPENSED', 'COMPLETED', 'CANCELLED'], true)) $status = 'semua';

// Ringkasan periode: semua angka dibaca langsung dari tabel transaksi SIMRS.
$prescriptionCountStmt = $pdo->prepare('SELECT COUNT(*) FROM prescriptions WHERE prescription_date >= :start_date AND prescription_date < :end_date');
$prescriptionCountStmt->execute(['start_date' => $awalPeriode, 'end_date' => $akhirPeriode]);
$prescriptionCount = (int) $prescriptionCountStmt->fetchColumn();

$movementSummaryStmt = $pdo->prepare("SELECT
    COALESCE(SUM(CASE WHEN movement_type IN ('OPENING','IN','ADJUSTMENT_IN') THEN quantity ELSE 0 END), 0) AS incoming_qty,
    COALESCE(SUM(CASE WHEN movement_type IN ('OUT','ADJUSTMENT_OUT') THEN quantity ELSE 0 END), 0) AS outgoing_qty
    FROM stock_movements WHERE created_at >= :start_date AND created_at < :end_date");
$movementSummaryStmt->execute(['start_date' => $awalPeriode, 'end_date' => $akhirPeriode]);
$movementSummary = $movementSummaryStmt->fetch() ?: ['incoming_qty' => 0, 'outgoing_qty' => 0];
$lowStockCount = (int) $pdo->query('SELECT COUNT(*) FROM drugs WHERE is_active = 1 AND stock_qty <= minimum_stock')->fetchColumn();

$columns = [];
$rows = [];
$reportTitle = '';
$reportDescription = '';

if ($jenis === 'stok') {
    $reportTitle = 'Laporan stok obat';
    $reportDescription = 'Posisi stok terkini, batas minimum, harga referensi, dan tanggal kedaluwarsa obat aktif.';
    $columns = [
        'kode' => 'Kode obat', 'obat' => 'Nama obat', 'kategori' => 'Kategori', 'stok' => 'Stok saat ini',
        'satuan' => 'Satuan', 'minimum' => 'Batas minimum', 'harga' => 'Harga referensi', 'kedaluwarsa' => 'Kedaluwarsa', 'kondisi' => 'Kondisi',
    ];
    $where = ['d.is_active = 1'];
    $params = [];
    if ($keyword !== '') {
        $where[] = '(d.drug_code LIKE :q_code OR d.drug_name LIKE :q_name OR d.strength LIKE :q_strength OR c.category_name LIKE :q_category)';
        $like = '%' . $keyword . '%';
        $params += ['q_code' => $like, 'q_name' => $like, 'q_strength' => $like, 'q_category' => $like];
    }
    if ($kondisi === 'habis') $where[] = 'd.stock_qty <= 0';
    if ($kondisi === 'menipis') $where[] = 'd.stock_qty > 0 AND d.stock_qty <= d.minimum_stock';
    if ($kondisi === 'aman') $where[] = 'd.stock_qty > d.minimum_stock';
    if ($kondisi === 'kedaluwarsa') $where[] = 'd.expiry_date IS NOT NULL AND d.expiry_date BETWEEN CURRENT_DATE AND DATE_ADD(CURRENT_DATE, INTERVAL 90 DAY)';
    $sql = "SELECT d.drug_code AS kode, d.drug_name AS obat, COALESCE(c.category_name, 'Tanpa kategori') AS kategori,
        d.stock_qty AS stok, d.unit AS satuan, d.minimum_stock AS minimum, d.unit_price AS harga, d.expiry_date AS kedaluwarsa,
        CASE WHEN d.stock_qty <= 0 THEN 'Habis' WHEN d.stock_qty <= d.minimum_stock THEN 'Menipis' ELSE 'Aman' END AS kondisi
        FROM drugs d LEFT JOIN drug_categories c ON c.id = d.category_id
        WHERE " . implode(' AND ', $where) . ' ORDER BY (d.stock_qty <= d.minimum_stock) DESC, d.drug_name ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
} elseif ($jenis === 'mutasi') {
    $reportTitle = 'Laporan mutasi stok';
    $reportDescription = 'Riwayat stok masuk, stok keluar, saldo awal, dan koreksi persediaan dalam periode terpilih.';
    $columns = [
        'tanggal' => 'Tanggal', 'kode' => 'Kode obat', 'obat' => 'Nama obat', 'tipe' => 'Jenis mutasi',
        'jumlah' => 'Jumlah', 'satuan' => 'Satuan', 'stok_awal' => 'Stok sebelum', 'stok_akhir' => 'Stok sesudah',
        'referensi' => 'Nomor referensi', 'catatan' => 'Catatan',
    ];
    $where = ['sm.created_at >= :start_date', 'sm.created_at < :end_date'];
    $params = ['start_date' => $awalPeriode, 'end_date' => $akhirPeriode];
    if ($arah === 'masuk') $where[] = "sm.movement_type IN ('OPENING','IN','ADJUSTMENT_IN')";
    if ($arah === 'keluar') $where[] = "sm.movement_type IN ('OUT','ADJUSTMENT_OUT')";
    if ($keyword !== '') {
        $where[] = '(d.drug_code LIKE :q_code OR d.drug_name LIKE :q_name OR sm.notes LIKE :q_notes OR pu.receipt_number LIKE :q_receipt OR ds.dispensing_number LIKE :q_dispensing)';
        $like = '%' . $keyword . '%';
        $params += ['q_code' => $like, 'q_name' => $like, 'q_notes' => $like, 'q_receipt' => $like, 'q_dispensing' => $like];
    }
    $sql = "SELECT sm.created_at AS tanggal, d.drug_code AS kode, d.drug_name AS obat, sm.movement_type AS tipe,
        sm.quantity AS jumlah, d.unit AS satuan, sm.stock_before AS stok_awal, sm.stock_after AS stok_akhir,
        COALESCE(pu.receipt_number, ds.dispensing_number, '—') AS referensi, COALESCE(sm.notes, '') AS catatan
        FROM stock_movements sm JOIN drugs d ON d.id = sm.drug_id
        LEFT JOIN purchase_items pui ON pui.id = sm.purchase_item_id
        LEFT JOIN purchases pu ON pu.id = pui.purchase_id
        LEFT JOIN dispensing_items di ON di.id = sm.dispensing_item_id
        LEFT JOIN dispensings ds ON ds.id = di.dispensing_id
        WHERE " . implode(' AND ', $where) . ' ORDER BY sm.created_at DESC, sm.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
} elseif ($jenis === 'resep') {
    $reportTitle = 'Laporan penerimaan resep';
    $reportDescription = 'Daftar resep yang diterima beserta pasien, nomor rekam medis, poliklinik, dokter, dan status pelayanan.';
    $columns = [
        'tanggal' => 'Tanggal resep', 'nomor' => 'Nomor resep', 'pasien' => 'Nama pasien', 'rm' => 'No. rekam medis',
        'poliklinik' => 'Poliklinik', 'dokter' => 'Dokter', 'jumlah_item' => 'Jumlah item', 'status' => 'Status',
    ];
    $where = ['p.prescription_date >= :start_date', 'p.prescription_date < :end_date'];
    $params = ['start_date' => $awalPeriode, 'end_date' => $akhirPeriode];
    if ($status !== 'semua') {
        $where[] = 'p.status = :status';
        $params['status'] = $status;
    }
    if ($keyword !== '') {
        $where[] = '(p.prescription_number LIKE :q_number OR pa.full_name LIKE :q_patient OR pa.medical_record_no LIKE :q_rm OR pc.polyclinic_name LIKE :q_poly OR doc.doctor_name LIKE :q_doctor)';
        $like = '%' . $keyword . '%';
        $params += ['q_number' => $like, 'q_patient' => $like, 'q_rm' => $like, 'q_poly' => $like, 'q_doctor' => $like];
    }
    $sql = "SELECT p.prescription_date AS tanggal, p.prescription_number AS nomor, pa.full_name AS pasien,
        pa.medical_record_no AS rm, pc.polyclinic_name AS poliklinik, doc.doctor_name AS dokter,
        (SELECT COUNT(*) FROM prescription_items pi WHERE pi.prescription_id = p.id) AS jumlah_item, p.status AS status
        FROM prescriptions p JOIN visits v ON v.id = p.visit_id
        JOIN patients pa ON pa.id = v.patient_id
        JOIN polyclinics pc ON pc.id = v.polyclinic_id
        JOIN doctors doc ON doc.id = v.doctor_id
        WHERE " . implode(' AND ', $where) . ' ORDER BY p.prescription_date DESC, p.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
} else {
    $reportTitle = 'Laporan penggunaan obat';
    $reportDescription = 'Rekap jumlah obat yang benar-benar diserahkan kepada pasien, dihitung dari transaksi penyerahan resep.';
    $columns = [
        'kode' => 'Kode obat', 'obat' => 'Nama obat', 'kategori' => 'Kategori', 'total_diserahkan' => 'Total diserahkan',
        'satuan' => 'Satuan', 'transaksi' => 'Transaksi', 'resep' => 'Jumlah resep', 'terakhir' => 'Penyerahan terakhir',
    ];
    $where = ['ds.dispensed_at >= :start_date', 'ds.dispensed_at < :end_date'];
    $params = ['start_date' => $awalPeriode, 'end_date' => $akhirPeriode];
    if ($keyword !== '') {
        $where[] = '(d.drug_code LIKE :q_code OR d.drug_name LIKE :q_name OR c.category_name LIKE :q_category)';
        $like = '%' . $keyword . '%';
        $params += ['q_code' => $like, 'q_name' => $like, 'q_category' => $like];
    }
    $sql = "SELECT d.drug_code AS kode, d.drug_name AS obat, COALESCE(c.category_name, 'Tanpa kategori') AS kategori,
        SUM(di.quantity_dispensed) AS total_diserahkan, d.unit AS satuan, COUNT(DISTINCT ds.id) AS transaksi,
        COUNT(DISTINCT p.id) AS resep, MAX(ds.dispensed_at) AS terakhir
        FROM dispensing_items di JOIN dispensings ds ON ds.id = di.dispensing_id
        JOIN prescription_items pi ON pi.id = di.prescription_item_id
        JOIN drugs d ON d.id = pi.drug_id JOIN prescriptions p ON p.id = ds.prescription_id
        LEFT JOIN drug_categories c ON c.id = d.category_id
        WHERE " . implode(' AND ', $where) . '
        GROUP BY d.id, d.drug_code, d.drug_name, c.category_name, d.unit
        ORDER BY total_diserahkan DESC, d.drug_name ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
}

$totalRows = count($rows);

// Ekspor CSV berformat UTF-8 (dapat dibuka langsung di Microsoft Excel).
if (($_GET['export'] ?? '') === 'csv') {
    $filename = 'laporan-' . $jenis . '-' . $dari . '-sampai-' . $sampai . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    $output = fopen('php://output', 'wb');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, [$reportTitle], ';');
    fputcsv($output, ['Periode', report_datetime($dari, false) . ' - ' . report_datetime($sampai, false)], ';');
    fputcsv($output, array_values($columns), ';');
    foreach ($rows as $row) {
        $line = [];
        foreach ($columns as $key => $label) {
            $value = report_value($key, $row[$key] ?? null, $row);
            if (preg_match('/^[=+@\\-]/u', $value)) $value = "'" . $value;
            $line[] = $value;
        }
        fputcsv($output, $line, ';');
    }
    fclose($output);
    exit;
}

$tabs = [
    'stok' => 'Stok obat',
    'mutasi' => 'Mutasi stok',
    'resep' => 'Penerimaan resep',
    'penggunaan' => 'Penggunaan obat',
];
$queryForTab = ['dari' => $dari, 'sampai' => $sampai];
$csvParams = $_GET;
$csvParams['export'] = 'csv';
$csvUrl = 'index.php?' . http_build_query($csvParams);
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f7dbe9">
    <title>Laporan · <?= e(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/app.css">
    <link rel="stylesheet" href="../assets/css/master.css">
    <link rel="stylesheet" href="../assets/css/theme.css?v=1">
    <link rel="stylesheet" href="../assets/css/laporan.css?v=1">
</head>
<body class="app-body">
<header class="app-header">
    <a class="brand" href="../dashboard.php"><span class="brand-mark"><span></span><i></i></span><span class="brand-name">Eight<span>Medi</span></span></a>
    <nav class="main-nav" aria-label="Navigasi utama">
        <a href="../dashboard.php">Ringkasan</a>
        <a href="../master/index.php">Master data</a>
        <a href="../pelayanan/index.php">Pelayanan resep</a>
        <a href="../persediaan/index.php">Persediaan</a>
        <a class="active" href="index.php">Laporan</a>
        <?php if (($user['role'] ?? '') === 'ADMIN'): ?><a href="../auth/users.php">Pengguna</a><?php endif; ?>
    </nav>
    <div class="header-account">
        <div class="account-copy"><strong><?= e($user['full_name'] ?? 'Pengguna') ?></strong><span><a href="../auth/change-password.php">Ubah password</a> · Pengguna farmasi</span></div>
        <div class="avatar"><?= e(mb_strtoupper(mb_substr((string) ($user['full_name'] ?? 'U'), 0, 1))) ?></div>
        <form method="post" action="../auth/logout.php"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button class="logout-button" type="submit" title="Keluar" aria-label="Keluar"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 17l5-5-5-5M15 12H3M13 3h5a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-5"></path></svg></button></form>
    </div>
</header>
<main class="dashboard-main master-main report-main">
    <div class="master-breadcrumb"><a href="../dashboard.php">Ringkasan</a><span>/</span><strong>Laporan</strong></div>
    <section class="master-title-row report-title-row">
        <div><p class="section-kicker">ANALISIS DAN PELAPORAN FARMASI</p><h1>Laporan farmasi</h1><p class="welcome-description">Pantau stok, pergerakan persediaan, penerimaan resep, dan penggunaan obat dari data transaksi.</p></div>
        <div class="report-title-mark"><span>✦</span><small>DATA<br>TERINTEGRASI</small></div>
    </section>

    <section class="report-summary-grid" aria-label="Ringkasan laporan">
        <article class="report-summary-card report-pink"><span>Resep tercatat</span><strong><?= number_format($prescriptionCount, 0, ',', '.') ?></strong><small>Dalam periode terpilih</small></article>
        <article class="report-summary-card report-blue"><span>Stok masuk</span><strong><?= report_qty($movementSummary['incoming_qty']) ?></strong><small>Total satuan masuk</small></article>
        <article class="report-summary-card report-purple"><span>Stok keluar</span><strong><?= report_qty($movementSummary['outgoing_qty']) ?></strong><small>Total satuan keluar</small></article>
        <article class="report-summary-card report-rose"><span>Perlu perhatian</span><strong><?= number_format($lowStockCount, 0, ',', '.') ?></strong><small>Obat di bawah batas minimum</small></article>
    </section>

    <section class="report-workspace">
        <div class="report-tabs" role="tablist" aria-label="Jenis laporan">
            <?php foreach ($tabs as $key => $label): $tabParams = array_merge($queryForTab, ['jenis' => $key]); ?>
                <a class="report-tab <?= $jenis === $key ? 'active' : '' ?>" href="index.php?<?= e(http_build_query($tabParams)) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </div>
        <div class="report-panel-heading">
            <div><p class="section-kicker">HASIL LAPORAN</p><h2><?= e($reportTitle) ?></h2><p><?= e($reportDescription) ?></p></div>
            <span class="report-count"><?= number_format($totalRows, 0, ',', '.') ?> baris data</span>
        </div>
        <form method="get" class="report-filter-bar">
            <input type="hidden" name="jenis" value="<?= e($jenis) ?>">
            <label class="report-filter-field"><span>Dari tanggal</span><input type="date" name="dari" value="<?= e($dari) ?>"></label>
            <label class="report-filter-field"><span>Sampai tanggal</span><input type="date" name="sampai" value="<?= e($sampai) ?>"></label>
            <label class="report-search-field"><span>Cari data</span><input type="search" name="q" value="<?= e($keyword) ?>" placeholder="Cari kode, nama obat, pasien, atau nomor..."></label>
            <?php if ($jenis === 'stok'): ?>
                <label class="report-filter-field report-select-field"><span>Kondisi stok</span><select name="kondisi"><option value="semua" <?= $kondisi === 'semua' ? 'selected' : '' ?>>Semua kondisi</option><option value="habis" <?= $kondisi === 'habis' ? 'selected' : '' ?>>Habis</option><option value="menipis" <?= $kondisi === 'menipis' ? 'selected' : '' ?>>Menipis</option><option value="aman" <?= $kondisi === 'aman' ? 'selected' : '' ?>>Aman</option><option value="kedaluwarsa" <?= $kondisi === 'kedaluwarsa' ? 'selected' : '' ?>>Kedaluwarsa ≤ 90 hari</option></select></label>
            <?php elseif ($jenis === 'mutasi'): ?>
                <label class="report-filter-field report-select-field"><span>Jenis mutasi</span><select name="arah"><option value="semua" <?= $arah === 'semua' ? 'selected' : '' ?>>Semua mutasi</option><option value="masuk" <?= $arah === 'masuk' ? 'selected' : '' ?>>Stok masuk</option><option value="keluar" <?= $arah === 'keluar' ? 'selected' : '' ?>>Stok keluar</option></select></label>
            <?php elseif ($jenis === 'resep'): ?>
                <label class="report-filter-field report-select-field"><span>Status resep</span><select name="status"><option value="semua" <?= $status === 'semua' ? 'selected' : '' ?>>Semua status</option><?php foreach (['PENDING','IN_PROCESS','PARTIALLY_DISPENSED','COMPLETED','CANCELLED'] as $value): ?><option value="<?= e($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= e(report_status_label($value)) ?></option><?php endforeach; ?></select></label>
            <?php endif; ?>
            <div class="report-filter-actions"><button class="filter-button" type="submit">Terapkan</button><a class="reset-filter" href="index.php?jenis=<?= e($jenis) ?>">Reset</a></div>
        </form>
        <div class="report-action-row">
            <span>Periode: <strong><?= e(report_datetime($dari, false)) ?> – <?= e(report_datetime($sampai, false)) ?></strong></span>
            <div><a class="report-export-button" href="<?= e($csvUrl) ?>">↓ Export Excel (CSV)</a><button class="report-print-button" type="button" onclick="window.print()">▣ Cetak / Simpan PDF</button></div>
        </div>
        <div class="table-wrap report-table-wrap">
            <table class="drug-table report-table">
                <thead><tr><?php foreach ($columns as $label): ?><th><?= e($label) ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="<?= count($columns) ?>" class="empty-state"><strong>Belum ada data untuk laporan ini</strong><span>Ubah periode, kata kunci, atau filter untuk melihat data lainnya.</span></td></tr>
                <?php else: foreach ($rows as $row): ?>
                    <tr>
                    <?php foreach ($columns as $key => $label): $value = report_value($key, $row[$key] ?? null, $row); ?>
                        <td>
                        <?php if ($key === 'kondisi'): ?><span class="report-pill <?= $value === 'Aman' ? 'pill-good' : ($value === 'Habis' ? 'pill-empty' : 'pill-warn') ?>"><?= e($value) ?></span>
                        <?php elseif ($key === 'status'): ?><span class="report-pill <?= ($row['status'] ?? '') === 'COMPLETED' ? 'pill-good' : ((($row['status'] ?? '') === 'CANCELLED') ? 'pill-empty' : 'pill-warn') ?>"><?= e($value) ?></span>
                        <?php elseif ($key === 'tipe'): ?><span class="report-movement <?= in_array(($row['tipe'] ?? ''), ['OPENING','IN','ADJUSTMENT_IN'], true) ? 'movement-in' : 'movement-out' ?>"><?= e($value) ?></span>
                        <?php elseif (in_array($key, ['obat','pasien','nomor'], true)): ?><strong class="report-primary-cell"><?= e($value) ?></strong>
                        <?php else: ?><?= e($value) ?><?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <div class="report-footnote"><strong>Catatan:</strong> laporan stok menampilkan posisi terkini; laporan mutasi, resep, dan penggunaan mengikuti periode yang dipilih. Export Excel menghasilkan CSV UTF-8, sedangkan PDF dapat dibuat melalui menu cetak browser.</div>
    </section>
    <footer class="dashboard-footer"><span>EightMedi · SIMRS Farmasi</span><span>Laporan · <?= date('Y') ?></span></footer>
</main>
</body>
</html>
