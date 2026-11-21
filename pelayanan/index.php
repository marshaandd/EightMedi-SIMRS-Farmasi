<?php
declare(strict_types=1);
require_once __DIR__ . '/_common.php';

$pdo = db();
$keyword = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? 'semua');
$validStatuses = ['semua', 'PENDING', 'IN_PROCESS', 'PARTIALLY_DISPENSED', 'COMPLETED', 'CANCELLED'];
if (!in_array($status, $validStatuses, true)) $status = 'semua';

$where = [];
$params = [];
if ($keyword !== '') {
    $where[] = '(p.prescription_number LIKE :q_prescription OR v.visit_number LIKE :q_visit OR pa.medical_record_no LIKE :q_mrn OR pa.full_name LIKE :q_patient OR d.doctor_name LIKE :q_doctor)';
    $like = '%' . $keyword . '%';
    $params = ['q_prescription' => $like, 'q_visit' => $like, 'q_mrn' => $like, 'q_patient' => $like, 'q_doctor' => $like];
}
if ($status !== 'semua') {
    $where[] = 'p.status = :status';
    $params['status'] = $status;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$stats = [
    'today' => (int) $pdo->query('SELECT COUNT(*) FROM prescriptions WHERE DATE(prescription_date) = CURRENT_DATE')->fetchColumn(),
    'pending' => (int) $pdo->query("SELECT COUNT(*) FROM prescriptions WHERE status = 'PENDING'")->fetchColumn(),
    'partial' => (int) $pdo->query("SELECT COUNT(*) FROM prescriptions WHERE status = 'PARTIALLY_DISPENSED'")->fetchColumn(),
    'completed' => (int) $pdo->query("SELECT COUNT(*) FROM prescriptions WHERE status = 'COMPLETED'")->fetchColumn(),
];

$fromSql = ' FROM prescriptions p
    JOIN visits v ON v.id = p.visit_id
    JOIN patients pa ON pa.id = v.patient_id
    JOIN polyclinics pc ON pc.id = v.polyclinic_id
    JOIN doctors d ON d.id = v.doctor_id';
$count = $pdo->prepare('SELECT COUNT(*)' . $fromSql . $whereSql);
$count->execute($params);
$totalRows = (int) $count->fetchColumn();

$perPage = 12;
$page = max(1, (int) ($_GET['halaman'] ?? 1));
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$sql = 'SELECT p.id, p.prescription_number, p.prescription_date, p.status,
        v.visit_number, pa.medical_record_no, pa.full_name AS patient_name,
        pc.polyclinic_name, d.doctor_name,
        (SELECT COUNT(*) FROM prescription_items pi WHERE pi.prescription_id = p.id) AS item_count
        ' . $fromSql . $whereSql . '
        ORDER BY p.prescription_date DESC, p.id DESC LIMIT :limit OFFSET :offset';
$list = $pdo->prepare($sql);
foreach ($params as $key => $value) $list->bindValue(':' . $key, $value, PDO::PARAM_STR);
$list->bindValue(':limit', $perPage, PDO::PARAM_INT);
$list->bindValue(':offset', $offset, PDO::PARAM_INT);
$list->execute();
$rows = $list->fetchAll();
$patientCount = (int) $pdo->query('SELECT COUNT(*) FROM patients WHERE is_active = 1')->fetchColumn();

function rx_list_url(array $changes = []): string
{
    $query = array_merge($_GET, $changes);
    unset($query['halaman']);
    foreach ($query as $key => $value) if ($value === '' || $value === null) unset($query[$key]);
    return 'index.php' . ($query ? '?' . http_build_query($query) : '');
}

rx_render_start('Pelayanan resep', 'Terima resep dari poliklinik, verifikasi detail obat, lalu catat penyerahan sesuai stok tersedia.');
?>
<div class="rx-action-row">
    <a class="primary-button" href="buat.php"><span>＋</span> Terima resep baru</a>
    <a class="quiet-button" href="../master/index.php">Master data</a>
</div>
<section class="master-stats rx-stats" aria-label="Ringkasan resep">
    <article class="master-stat"><span>Resep hari ini</span><strong><?= number_format($stats['today'], 0, ',', '.') ?></strong><small>Resep diterima hari ini</small></article>
    <article class="master-stat stat-warning"><span>Menunggu verifikasi</span><strong><?= number_format($stats['pending'], 0, ',', '.') ?></strong><small>Belum mulai diproses</small></article>
    <article class="master-stat stat-expiry"><span>Diserahkan sebagian</span><strong><?= number_format($stats['partial'], 0, ',', '.') ?></strong><small>Masih memiliki sisa resep</small></article>
    <article class="master-stat"><span>Resep selesai</span><strong><?= number_format($stats['completed'], 0, ',', '.') ?></strong><small>Seluruh item sudah diserahkan</small></article>
</section>
<?php if ($patientCount === 0): ?>
    <div class="rx-guidance"><strong>Data pasien belum tersedia.</strong><span>Tambahkan minimal satu pasien di Master data sebelum menerima resep pertama.</span><a href="../master/pasien.php?tambah=1">Tambah pasien →</a></div>
<?php endif; ?>
<section class="drug-list-panel rx-list-panel">
    <div class="list-heading"><div><p class="section-kicker">DAFTAR RESEP</p><h2>Antrean pelayanan resep</h2><p class="list-subtitle">Setiap resep terhubung dengan kunjungan, pasien, dokter, dan poliklinik.</p></div><span class="result-count"><?= number_format($totalRows, 0, ',', '.') ?> resep ditemukan</span></div>
    <form method="get" class="filter-bar rx-filter-bar">
        <label class="search-field"><span class="visually-hidden">Cari resep</span><input type="search" name="q" value="<?= e($keyword) ?>" placeholder="Cari nomor resep, pasien, RM, kunjungan, atau dokter..."></label>
        <label class="filter-field"><span class="visually-hidden">Status resep</span><select name="status">
            <option value="semua" <?= $status === 'semua' ? 'selected' : '' ?>>Semua status</option>
            <?php foreach (['PENDING','IN_PROCESS','PARTIALLY_DISPENSED','COMPLETED','CANCELLED'] as $value): ?><option value="<?= e($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= e(rx_status_label($value)) ?></option><?php endforeach; ?>
        </select></label>
        <button class="filter-button" type="submit">Terapkan</button>
        <?php if ($keyword !== '' || $status !== 'semua'): ?><a class="reset-filter" href="index.php">Reset</a><?php endif; ?>
    </form>
    <div class="table-wrap"><table class="drug-table rx-table"><thead><tr><th>Nomor resep</th><th>Pasien</th><th>Poliklinik / dokter</th><th>Tanggal</th><th>Item</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
    <?php if (!$rows): ?>
        <tr><td colspan="7" class="empty-state"><strong><?= $keyword !== '' || $status !== 'semua' ? 'Resep tidak ditemukan' : 'Belum ada resep yang diterima' ?></strong><span><?= $keyword !== '' || $status !== 'semua' ? 'Coba ubah kata kunci atau filter status.' : 'Gunakan tombol Terima resep baru untuk mencatat resep dari poliklinik.' ?></span></td></tr>
    <?php else: foreach ($rows as $row): ?>
        <tr>
            <td><div class="rx-primary-cell"><strong><?= e($row['prescription_number']) ?></strong><span><?= e($row['visit_number']) ?></span></div></td>
            <td><div class="rx-primary-cell"><strong><?= e($row['patient_name']) ?></strong><span><?= e($row['medical_record_no']) ?></span></div></td>
            <td><div class="rx-primary-cell"><strong><?= e($row['polyclinic_name']) ?></strong><span><?= e($row['doctor_name']) ?></span></div></td>
            <td><span class="expiry-label"><?= e(rx_datetime($row['prescription_date'])) ?></span></td>
            <td><?= number_format((int) $row['item_count'], 0, ',', '.') ?> item</td>
            <td><span class="rx-status <?= e(rx_status_class($row['status'])) ?>"><?= e(rx_status_label($row['status'])) ?></span></td>
            <td><a class="action-link" href="detail.php?id=<?= (int) $row['id'] ?>">Buka detail →</a></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody></table></div>
    <?php if ($totalPages > 1): ?><div class="pagination"><span>Halaman <?= $page ?> dari <?= $totalPages ?></span><div><?php if ($page > 1): ?><a href="<?= e(rx_list_url(['halaman' => $page - 1])) ?>">← Sebelumnya</a><?php endif; ?><?php if ($page < $totalPages): ?><a href="<?= e(rx_list_url(['halaman' => $page + 1])) ?>">Berikutnya →</a><?php endif; ?></div></div><?php endif; ?>
</section>
<div class="master-footnote"><strong>Alur:</strong> resep diterima dari poli → verifikasi → penyerahan obat penuh atau sebagian. Setiap penyerahan otomatis mengurangi stok dan tercatat di Riwayat stok.</div>
<?php rx_render_end(); ?>
