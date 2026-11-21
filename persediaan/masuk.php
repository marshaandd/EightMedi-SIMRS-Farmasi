<?php
declare(strict_types=1);
require_once __DIR__ . '/_common.php';

$pdo = db();
$user = current_user();
$errors = [];
$form = [
    'supplier_id' => '',
    'drug_id' => (string) max(0, (int) ($_GET['drug_id'] ?? 0)),
    'quantity' => '',
    'received_at' => date('Y-m-d\\TH:i'),
    'receipt_number' => '',
    'notes' => '',
];

$suppliers = $pdo->query('SELECT id, supplier_name FROM suppliers WHERE is_active = 1 ORDER BY supplier_name')->fetchAll();
$drugs = $pdo->query('SELECT id, drug_code, drug_name, unit, stock_qty FROM drugs WHERE is_active = 1 ORDER BY drug_name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        exit('Sesi formulir tidak valid. Muat ulang halaman, lalu coba kembali.');
    }
    foreach ($form as $key => $value) $form[$key] = trim((string) ($_POST[$key] ?? $value));
    $supplierId = filter_var($form['supplier_id'], FILTER_VALIDATE_INT);
    $drugId = filter_var($form['drug_id'], FILTER_VALIDATE_INT);
    $quantity = $form['quantity'];
    $receivedAt = inv_date_input_to_sql($form['received_at']);
    $receiptNumber = $form['receipt_number'] !== '' ? $form['receipt_number'] : 'TRM-' . date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(2)));

    if (!$supplierId || $supplierId < 1) $errors[] = 'Pilih supplier yang aktif.';
    if (!$drugId || $drugId < 1) $errors[] = 'Pilih obat yang akan diterima.';
    if (!inv_valid_quantity($quantity)) $errors[] = 'Jumlah masuk harus lebih besar dari 0.';
    if ($receivedAt === null) $errors[] = 'Tanggal penerimaan tidak valid.';
    if (strlen($receiptNumber) > 60) $errors[] = 'Nomor bukti maksimal 60 karakter.';
    if (strlen($form['notes']) > 255) $errors[] = 'Catatan maksimal 255 karakter.';

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $supplierCheck = $pdo->prepare('SELECT id FROM suppliers WHERE id = :id AND is_active = 1');
            $supplierCheck->execute(['id' => $supplierId]);
            if (!$supplierCheck->fetchColumn()) throw new RuntimeException('Supplier tidak ditemukan atau sudah nonaktif.');

            $drugCheck = $pdo->prepare('SELECT id, stock_qty, unit FROM drugs WHERE id = :id AND is_active = 1 FOR UPDATE');
            $drugCheck->execute(['id' => $drugId]);
            $drug = $drugCheck->fetch();
            if (!$drug) throw new RuntimeException('Obat tidak ditemukan atau sudah nonaktif.');

            $before = (float) $drug['stock_qty'];
            $amount = (float) $quantity;
            $after = $before + $amount;

            $purchase = $pdo->prepare('INSERT INTO purchases (receipt_number, supplier_id, received_at, received_by, notes) VALUES (:receipt_number, :supplier_id, :received_at, :received_by, :notes)');
            $purchase->execute([
                'receipt_number' => $receiptNumber,
                'supplier_id' => $supplierId,
                'received_at' => $receivedAt,
                'received_by' => $user['id'] ?? null,
                'notes' => $form['notes'] !== '' ? $form['notes'] : null,
            ]);
            $purchaseId = (int) $pdo->lastInsertId();

            $item = $pdo->prepare('INSERT INTO purchase_items (purchase_id, drug_id, quantity_received, notes) VALUES (:purchase_id, :drug_id, :quantity_received, :notes)');
            $item->execute([
                'purchase_id' => $purchaseId,
                'drug_id' => $drugId,
                'quantity_received' => $amount,
                'notes' => $form['notes'] !== '' ? $form['notes'] : null,
            ]);
            $purchaseItemId = (int) $pdo->lastInsertId();

            $update = $pdo->prepare('UPDATE drugs SET stock_qty = :stock_qty WHERE id = :id');
            $update->execute(['stock_qty' => $after, 'id' => $drugId]);

            $movement = $pdo->prepare("INSERT INTO stock_movements (drug_id, movement_type, quantity, stock_before, stock_after, purchase_item_id, notes, created_by) VALUES (:drug_id, 'IN', :quantity, :stock_before, :stock_after, :purchase_item_id, :notes, :created_by)");
            $movement->execute([
                'drug_id' => $drugId,
                'quantity' => $amount,
                'stock_before' => $before,
                'stock_after' => $after,
                'purchase_item_id' => $purchaseItemId,
                'notes' => $form['notes'] !== '' ? $form['notes'] : 'Penerimaan ' . $receiptNumber,
                'created_by' => $user['id'] ?? null,
            ]);
            $pdo->commit();
            set_flash('success', 'Penerimaan berhasil disimpan. Stok obat sudah bertambah.');
            redirect('persediaan/index.php');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log($exception->getMessage());
            $errors[] = $exception instanceof RuntimeException ? $exception->getMessage() : 'Penerimaan gagal disimpan. Pastikan nomor bukti belum pernah digunakan.';
        }
    }
}

inv_render_start('Penerimaan obat');
?>
<div class="inventory-action-row inventory-subnav"><a class="quiet-button" href="index.php">← Kembali ke persediaan</a><a class="quiet-button" href="riwayat.php">Riwayat stok</a></div>
<section class="drug-form-panel inventory-form-panel">
    <div class="drug-form-heading"><div><p class="section-kicker">STOK MASUK</p><h2>Catat penerimaan obat</h2><p class="list-subtitle">Penerimaan dicatat sebagai transaksi supplier dan otomatis menambah stok.</p></div></div>
    <?php if ($errors): ?><div class="form-errors"><?php foreach ($errors as $error): ?><p><?= e($error) ?></p><?php endforeach; ?></div><?php endif; ?>
    <?php if (!$suppliers || !$drugs): ?><div class="master-footnote"><strong>Belum dapat mencatat penerimaan.</strong> Pastikan sudah ada supplier aktif dan obat aktif pada menu Master data.</div><?php else: ?>
    <form method="post" class="drug-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <label class="field"><span>Supplier <b>*</b></span><select name="supplier_id" required><option value="">Pilih supplier</option><?php foreach ($suppliers as $supplier): ?><option value="<?= (int) $supplier['id'] ?>" <?= $form['supplier_id'] === (string) $supplier['id'] ? 'selected' : '' ?>><?= e($supplier['supplier_name']) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>Obat <b>*</b></span><select name="drug_id" required><option value="">Pilih obat</option><?php foreach ($drugs as $drug): ?><option value="<?= (int) $drug['id'] ?>" <?= $form['drug_id'] === (string) $drug['id'] ? 'selected' : '' ?>><?= e($drug['drug_name']) ?> · <?= e($drug['drug_code']) ?> (stok <?= inv_qty($drug['stock_qty']) ?> <?= e($drug['unit']) ?>)</option><?php endforeach; ?></select></label>
        <label class="field"><span>Jumlah diterima <b>*</b></span><input type="number" name="quantity" min="0.01" step="0.01" value="<?= e($form['quantity']) ?>" placeholder="Contoh: 100" required></label>
        <label class="field"><span>Tanggal penerimaan <b>*</b></span><input type="datetime-local" name="received_at" value="<?= e($form['received_at']) ?>" required></label>
        <label class="field"><span>Nomor bukti / faktur</span><input type="text" name="receipt_number" maxlength="60" value="<?= e($form['receipt_number']) ?>" placeholder="Kosongkan untuk dibuat otomatis"><small>Nomor akan dibuat otomatis jika dikosongkan.</small></label>
        <label class="field"><span>Catatan</span><input type="text" name="notes" maxlength="255" value="<?= e($form['notes']) ?>" placeholder="Nomor faktur, batch, atau keterangan lain"></label>
        <div class="form-actions field-wide"><a class="quiet-button" href="index.php">Batal</a><button class="primary-button" type="submit">Simpan penerimaan</button></div>
    </form>
    <?php endif; ?>
</section>
<?php inv_render_end(); ?>
