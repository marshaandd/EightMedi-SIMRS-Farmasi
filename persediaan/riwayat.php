<?php
declare(strict_types=1);
require_once __DIR__ . '/_common.php';

$pdo = db();
$keyword = trim((string) ($_GET['q'] ?? ''));
$type = (string) ($_GET['type'] ?? 'semua');
$drugId = trim((string) ($_GET['drug_id'] ?? ''));
$dateFrom = trim((string) ($_GET['dari'] ?? ''));
$dateTo = trim((string) ($_GET['sampai'] ?? ''));
$validTypes = ['semua', 'OPENING', 'IN', 'OUT', 'ADJUSTMENT_IN', 'ADJUSTMENT_OUT'];
if (!in_array($type, $validTypes, true)) $type = 'semua';
if ($dateFrom !== '' && !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $dateFrom)) $dateFrom = '';
if ($dateTo !== '' && !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $dateTo)) $dateTo = '';

$where = [];
$params = [];
if ($keyword !== '') {
    $where[] = '(d.drug_code LIKE :q_code OR d.drug_name LIKE :q_name)';
    $params['q_code'] = '%' . $keyword . '%';
    $params['q_name'] = '%' . $keyword . '%';
}
if ($type !== 'semua') {
    $where[] = 'sm.movement_type = :movement_type';
    $params['movement_type'] = $type;
}
if ($drugId !== '' && ctype_digit($drugId) && (int) $drugId > 0) {
    $where[] = 'sm.drug_id = :drug_id';
    $params['drug_id'] = (int) $drugId;
} else {
    $drugId = '';
}
if ($dateFrom !== '') {
    $where[] = 'sm.created_at >= :date_from';
    $params['date_from'] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $where[] = 'sm.created_at <= :date_to';
    $params['date_to'] = $dateTo . ' 23:59:59';
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$count = $pdo->prepare('SELECT COUNT(*) FROM stock_movements sm JOIN drugs d ON d.id = sm.drug_id' . $whereSql);
$count->execute($params);
$totalRows = (int) $count->fetchColumn();
$perPage = 15;
$page = max(1, (int) ($_GET['halaman'] ?? 1));
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$sql = 'SELECT sm.id, sm.created_at, sm.movement_type, sm.quantity, sm.stock_before, sm.stock_after, sm.notes,
        d.drug_code, d.drug_name, d.unit, u.full_name AS actor_name
        FROM stock_movements sm JOIN drugs d ON d.id = sm.drug_id
        LEFT JOIN users u ON u.id = sm.created_by' . $whereSql . '
        ORDER BY sm.created_at DESC, sm.id DESC LIMIT :limit OFFSET :offset';
$list = $pdo->prepare($sql);
foreach ($params as $key => $value) $list->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
$list->bindValue(':limit', $perPage, PDO::PARAM_INT);
$list->bindValue(':offset', $offset, PDO::PARAM_INT);
$list->execute();
$rows = $list->fetchAll();
$drugs = $pdo->query('SELECT id, drug_code, drug_name FROM drugs ORDER BY drug_name')->fetchAll();

function inv_history_url(array $changes = []): string
{
    $query = array_merge($_GET, $changes);
    unset($query['halaman']);
    foreach ($query as $key => $value) if ($value === '' || $value === null) unset($query[$key]);
    return 'riwayat.php' . ($query ? '?' . http_build_query($query) : '');
}

inv_render_start('Riwayat stok');
?>
<div class="inventory-action-row inventory-subnav"><a class="quiet-button" href="index.php">← Kembali ke persediaan</a><a class="primary-button" href="masuk.php"><span>＋</span> Penerimaan obat</a><a class="inventory-secondary-button" href="keluar.php">− Stok keluar</a></div>
<section class="drug-list-panel inventory-list-panel">
    <div class="list-heading"><div><p class="section-kicker">BUKU MUTASI</p><h2>Riwayat pergerakan stok</h2><p class="list-subtitle">Setiap mutasi menyimpan stok sebelum dan sesudah transaksi.</p></div><span class="result-count"><?= number_format($totalRows, 0, ',', '.') ?> mutasi ditemukan</span></div>
    <form method="get" class="inventory-history-filters">
        <?php if ($drugId !== ''): ?><input type="hidden" name="drug_id" value="<?= e($drugId) ?>"><?php endif; ?>
        <label class="search-field"><span class="visually-hidden">Cari obat</span><input type="search" name="q" value="<?= e($keyword) ?>" placeholder="Cari kode atau nama obat..."></label>
        <label class="filter-field"><span class="visually-hidden">Jenis mutasi</span><select name="type"><option value="semua">Semua mutasi</option><?php foreach (['IN','OUT','ADJUSTMENT_IN','ADJUSTMENT_OUT','OPENING'] as $movementType): ?><option value="<?= e($movementType) ?>" <?= $type === $movementType ? 'selected' : '' ?>><?= e(inv_type_label($movementType)) ?></option><?php endforeach; ?></select></label>
        <label class="field history-date-field"><span>Dari tanggal</span><input type="date" name="dari" value="<?= e($dateFrom) ?>"></label>
        <label class="field history-date-field"><span>Sampai tanggal</span><input type="date" name="sampai" value="<?= e($dateTo) ?>"></label>
        <button class="filter-button" type="submit">Terapkan</button>
        <?php if ($keyword !== '' || $type !== 'semua' || $drugId !== '' || $dateFrom !== '' || $dateTo !== ''): ?><a class="reset-filter" href="riwayat.php">Reset</a><?php endif; ?>
    </form>
    <?php if ($drugId !== ''): ?><p class="history-filter-note">Menampilkan mutasi untuk obat terpilih. <a href="riwayat.php">Lihat semua obat</a></p><?php endif; ?>
    <div class="table-wrap"><table class="drug-table inventory-history-table"><thead><tr><th>Tanggal</th><th>Obat</th><th>Jenis mutasi</th><th>Jumlah</th><th>Stok sebelum</th><th>Stok sesudah</th><th>Catatan</th><th>Petugas</th></tr></thead><tbody>
    <?php if (!$rows): ?><tr><td colspan="8" class="empty-state"><strong>Belum ada riwayat mutasi</strong><span>Transaksi stok yang berhasil disimpan akan muncul di sini.</span></td></tr>
    <?php else: foreach ($rows as $row): ?>
        <tr>
            <td><span class="expiry-label"><?= e(inv_datetime($row['created_at'])) ?></span></td>
            <td><div class="drug-name-cell"><strong><?= e($row['drug_name']) ?></strong><span><?= e($row['drug_code']) ?> · <?= e($row['unit']) ?></span></div></td>
            <td><span class="movement-pill <?= e(inv_type_class($row['movement_type'])) ?>"><?= e(inv_type_label($row['movement_type'])) ?></span></td>
            <td><strong class="<?= in_array($row['movement_type'], ['IN','OPENING','ADJUSTMENT_IN'], true) ? 'qty-positive' : 'qty-negative' ?>"><?= in_array($row['movement_type'], ['IN','OPENING','ADJUSTMENT_IN'], true) ? '+' : '−' ?><?= inv_qty($row['quantity']) ?></strong></td>
            <td><?= inv_qty($row['stock_before']) ?></td>
            <td><strong><?= inv_qty($row['stock_after']) ?></strong></td>
            <td class="history-note"><?= e($row['notes'] ?: '—') ?></td>
            <td><?= e($row['actor_name'] ?: 'Sistem') ?></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody></table></div>
    <?php if ($totalPages > 1): ?><div class="pagination"><span>Halaman <?= $page ?> dari <?= $totalPages ?></span><div><?php if ($page > 1): ?><a href="<?= e(inv_history_url(['halaman' => $page - 1])) ?>">← Sebelumnya</a><?php endif; ?><?php if ($page < $totalPages): ?><a href="<?= e(inv_history_url(['halaman' => $page + 1])) ?>">Berikutnya →</a><?php endif; ?></div></div><?php endif; ?>
</section>
<?php inv_render_end(); ?>
