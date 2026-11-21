<?php
declare(strict_types=1);
require_once __DIR__ . '/_common.php';

$pdo = db();
$keyword = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? 'semua');
if (!in_array($status, ['semua', 'menipis', 'habis', 'aman'], true)) $status = 'semua';

$where = ['d.is_active = 1'];
$params = [];
if ($keyword !== '') {
    $where[] = '(d.drug_code LIKE :q_code OR d.drug_name LIKE :q_name)';
    $params['q_code'] = '%' . $keyword . '%';
    $params['q_name'] = '%' . $keyword . '%';
}
if ($status === 'menipis') $where[] = 'd.stock_qty <= d.minimum_stock';
if ($status === 'habis') $where[] = 'd.stock_qty <= 0';
if ($status === 'aman') $where[] = 'd.stock_qty > d.minimum_stock';
$whereSql = ' WHERE ' . implode(' AND ', $where);

$stats = [
    'drugs' => (int) $pdo->query('SELECT COUNT(*) FROM drugs WHERE is_active = 1')->fetchColumn(),
    'low' => (int) $pdo->query('SELECT COUNT(*) FROM drugs WHERE is_active = 1 AND stock_qty <= minimum_stock')->fetchColumn(),
    'inbound' => (int) $pdo->query("SELECT COUNT(*) FROM stock_movements WHERE movement_type IN ('IN','ADJUSTMENT_IN') AND created_at >= DATE_FORMAT(CURRENT_DATE, '%Y-%m-01')")->fetchColumn(),
    'outbound' => (int) $pdo->query("SELECT COUNT(*) FROM stock_movements WHERE movement_type IN ('OUT','ADJUSTMENT_OUT') AND created_at >= DATE_FORMAT(CURRENT_DATE, '%Y-%m-01')")->fetchColumn(),
];

$count = $pdo->prepare('SELECT COUNT(*) FROM drugs d' . $whereSql);
$count->execute($params);
$totalRows = (int) $count->fetchColumn();
$list = $pdo->prepare("SELECT d.id, d.drug_code, d.drug_name, d.unit, d.stock_qty, d.minimum_stock, d.expiry_date, c.category_name
    FROM drugs d LEFT JOIN drug_categories c ON c.id = d.category_id
    {$whereSql}
    ORDER BY CASE WHEN d.stock_qty <= 0 THEN 0 WHEN d.stock_qty <= d.minimum_stock THEN 1 ELSE 2 END, d.drug_name ASC");
$list->execute($params);
$rows = $list->fetchAll();

inv_render_start('Persediaan obat');
?>
<div class="inventory-action-row">
    <a class="primary-button" href="masuk.php"><span>＋</span> Penerimaan obat</a>
    <a class="inventory-secondary-button" href="keluar.php">− Stok keluar</a>
    <a class="quiet-button" href="riwayat.php">Riwayat stok</a>
</div>
<section class="master-stats inventory-stats" aria-label="Ringkasan persediaan">
    <article class="master-stat"><span>Jenis obat aktif</span><strong><?= number_format($stats['drugs'], 0, ',', '.') ?></strong><small>Obat yang dapat dikelola</small></article>
    <article class="master-stat stat-warning"><span>Stok perlu perhatian</span><strong><?= number_format($stats['low'], 0, ',', '.') ?></strong><small>Di bawah atau sama dengan minimum</small></article>
    <article class="master-stat"><span>Mutasi masuk bulan ini</span><strong><?= number_format($stats['inbound'], 0, ',', '.') ?></strong><small>Transaksi penerimaan / koreksi tambah</small></article>
    <article class="master-stat stat-expiry"><span>Mutasi keluar bulan ini</span><strong><?= number_format($stats['outbound'], 0, ',', '.') ?></strong><small>Pengeluaran / koreksi stok</small></article>
</section>
<section class="drug-list-panel inventory-list-panel">
    <div class="list-heading"><div><p class="section-kicker">KONTROL STOK</p><h2>Posisi stok obat</h2><p class="list-subtitle">Stok diperbarui otomatis setiap transaksi persediaan berhasil disimpan.</p></div><span class="result-count"><?= number_format($totalRows, 0, ',', '.') ?> obat ditemukan</span></div>
    <form method="get" class="filter-bar">
        <label class="search-field"><span class="visually-hidden">Cari obat</span><input type="search" name="q" value="<?= e($keyword) ?>" placeholder="Cari kode atau nama obat..."></label>
        <label class="filter-field"><span class="visually-hidden">Filter stok</span><select name="status"><option value="semua" <?= $status === 'semua' ? 'selected' : '' ?>>Semua kondisi stok</option><option value="menipis" <?= $status === 'menipis' ? 'selected' : '' ?>>Stok perlu perhatian</option><option value="habis" <?= $status === 'habis' ? 'selected' : '' ?>>Stok habis</option><option value="aman" <?= $status === 'aman' ? 'selected' : '' ?>>Stok aman</option></select></label>
        <button class="filter-button" type="submit">Terapkan</button>
        <?php if ($keyword !== '' || $status !== 'semua'): ?><a class="reset-filter" href="index.php">Reset</a><?php endif; ?>
    </form>
    <div class="table-wrap"><table class="drug-table inventory-table"><thead><tr><th>Obat</th><th>Kategori</th><th>Stok saat ini</th><th>Batas minimum</th><th>Kedaluwarsa</th><th>Kondisi</th><th>Aksi</th></tr></thead><tbody>
    <?php if (!$rows): ?><tr><td colspan="7" class="empty-state"><strong>Data obat tidak ditemukan</strong><span>Coba ubah kata kunci atau filter stok.</span></td></tr>
    <?php else: foreach ($rows as $row): $isLow = (float) $row['stock_qty'] <= (float) $row['minimum_stock']; $isEmpty = (float) $row['stock_qty'] <= 0; ?>
        <tr>
            <td><div class="drug-name-cell"><strong><?= e($row['drug_name']) ?></strong><span><?= e($row['drug_code']) ?> · <?= e($row['unit']) ?></span></div></td>
            <td><span class="category-label"><?= e($row['category_name'] ?: 'Tanpa kategori') ?></span></td>
            <td><div class="stock-cell <?= $isLow ? 'stock-low' : 'stock-ok' ?>"><strong><?= inv_qty($row['stock_qty']) ?></strong><span><?= e($row['unit']) ?></span></div></td>
            <td><?= inv_qty($row['minimum_stock']) ?> <?= e($row['unit']) ?></td>
            <td><span class="expiry-label"><?= $row['expiry_date'] ? e(date('d/m/Y', strtotime($row['expiry_date']))) : '—' ?></span></td>
            <td><span class="status-pill <?= $isLow ? 'status-inactive' : 'status-active' ?>"><?= $isEmpty ? 'Habis' : ($isLow ? 'Menipis' : 'Aman') ?></span></td>
            <td><div class="row-actions"><a class="action-link" href="masuk.php?drug_id=<?= (int) $row['id'] ?>">Masuk</a><a class="action-link action-toggle" href="keluar.php?drug_id=<?= (int) $row['id'] ?>">Keluar</a><a class="action-link" href="riwayat.php?drug_id=<?= (int) $row['id'] ?>">Riwayat</a></div></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody></table></div>
</section>
<div class="master-footnote"><strong>Catatan:</strong> stok keluar pada batch ini digunakan untuk koreksi/pengeluaran non-resep, misalnya obat rusak atau kedaluwarsa. Pengeluaran untuk resep akan dicatat otomatis saat modul pelayanan resep diaktifkan.</div>
<?php inv_render_end(); ?>
