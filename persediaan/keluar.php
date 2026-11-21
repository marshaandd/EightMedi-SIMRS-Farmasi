<?php
declare(strict_types=1);
require_once __DIR__ . '/_common.php';

$pdo = db();
$user = current_user();
$errors = [];
$form = [
    'drug_id' => (string) max(0, (int) ($_GET['drug_id'] ?? 0)),
    'quantity' => '',
    'notes' => '',
];
$drugs = $pdo->query('SELECT id, drug_code, drug_name, unit, stock_qty FROM drugs WHERE is_active = 1 ORDER BY drug_name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        exit('Sesi formulir tidak valid. Muat ulang halaman, lalu coba kembali.');
    }
    foreach ($form as $key => $value) $form[$key] = trim((string) ($_POST[$key] ?? ''));
    $drugId = filter_var($form['drug_id'], FILTER_VALIDATE_INT);
    $quantity = $form['quantity'];
    if (!$drugId || $drugId < 1) $errors[] = 'Pilih obat yang akan dikeluarkan.';
    if (!inv_valid_quantity($quantity)) $errors[] = 'Jumlah keluar harus lebih besar dari 0.';
    if ($form['notes'] === '') $errors[] = 'Alasan pengeluaran wajib diisi.';
    if (strlen($form['notes']) > 255) $errors[] = 'Alasan maksimal 255 karakter.';

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $statement = $pdo->prepare('SELECT id, stock_qty FROM drugs WHERE id = :id AND is_active = 1 FOR UPDATE');
            $statement->execute(['id' => $drugId]);
            $drug = $statement->fetch();
            if (!$drug) throw new RuntimeException('Obat tidak ditemukan atau sudah nonaktif.');
            $before = (float) $drug['stock_qty'];
            $amount = (float) $quantity;
            if ($amount > $before) throw new RuntimeException('Stok tidak mencukupi. Stok tersedia saat ini ' . inv_qty($before) . '.');
            $after = $before - $amount;

            $update = $pdo->prepare('UPDATE drugs SET stock_qty = :stock_qty WHERE id = :id');
            $update->execute(['stock_qty' => $after, 'id' => $drugId]);
            $movement = $pdo->prepare("INSERT INTO stock_movements (drug_id, movement_type, quantity, stock_before, stock_after, notes, created_by) VALUES (:drug_id, 'ADJUSTMENT_OUT', :quantity, :stock_before, :stock_after, :notes, :created_by)");
            $movement->execute([
                'drug_id' => $drugId,
                'quantity' => $amount,
                'stock_before' => $before,
                'stock_after' => $after,
                'notes' => $form['notes'],
                'created_by' => $user['id'] ?? null,
            ]);
            $pdo->commit();
            set_flash('success', 'Pengeluaran berhasil dicatat. Stok obat sudah berkurang.');
            redirect('persediaan/index.php');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log($exception->getMessage());
            $errors[] = $exception instanceof RuntimeException ? $exception->getMessage() : 'Pengeluaran gagal disimpan. Periksa koneksi database.';
        }
    }
}

inv_render_start('Stok keluar');
?>
<div class="inventory-action-row inventory-subnav"><a class="quiet-button" href="index.php">← Kembali ke persediaan</a><a class="quiet-button" href="riwayat.php">Riwayat stok</a></div>
<section class="drug-form-panel inventory-form-panel">
    <div class="drug-form-heading"><div><p class="section-kicker">STOK KELUAR</p><h2>Catat pengeluaran / koreksi stok</h2><p class="list-subtitle">Gunakan untuk obat rusak, kedaluwarsa, hilang, atau koreksi non-resep.</p></div></div>
    <div class="inventory-warning-note"><strong>Perhatian</strong><span>Jangan gunakan formulir ini untuk penyerahan obat berdasarkan resep. Mutasi resep akan dibuat otomatis pada modul Pelayanan Resep.</span></div>
    <?php if ($errors): ?><div class="form-errors"><?php foreach ($errors as $error): ?><p><?= e($error) ?></p><?php endforeach; ?></div><?php endif; ?>
    <?php if (!$drugs): ?><div class="master-footnote">Belum ada obat aktif. Tambahkan obat terlebih dahulu pada menu Master data.</div><?php else: ?>
    <form method="post" class="drug-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <label class="field field-wide"><span>Obat <b>*</b></span><select name="drug_id" required><option value="">Pilih obat</option><?php foreach ($drugs as $drug): ?><option value="<?= (int) $drug['id'] ?>" <?= $form['drug_id'] === (string) $drug['id'] ? 'selected' : '' ?>><?= e($drug['drug_name']) ?> · <?= e($drug['drug_code']) ?> — stok <?= inv_qty($drug['stock_qty']) ?> <?= e($drug['unit']) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>Jumlah keluar <b>*</b></span><input type="number" name="quantity" min="0.01" step="0.01" value="<?= e($form['quantity']) ?>" placeholder="Contoh: 5" required></label>
        <label class="field"><span>Alasan pengeluaran <b>*</b></span><input type="text" name="notes" maxlength="255" value="<?= e($form['notes']) ?>" placeholder="Contoh: obat rusak / kedaluwarsa" required></label>
        <div class="form-actions field-wide"><a class="quiet-button" href="index.php">Batal</a><button class="primary-button" type="submit">Simpan stok keluar</button></div>
    </form>
    <?php endif; ?>
</section>
<?php inv_render_end(); ?>
