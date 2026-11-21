<?php
declare(strict_types=1);
require_once __DIR__ . '/_common.php';

$pdo = db();
$user = current_user();
$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    http_response_code(404);
    exit('Resep tidak ditemukan.');
}
$errors = [];
$dispenseValues = [];
$dispenseNotes = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        exit('Sesi formulir tidak valid. Muat ulang halaman, lalu coba kembali.');
    }
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'start') {
        $statement = $pdo->prepare("UPDATE prescriptions SET status = 'IN_PROCESS' WHERE id = :id AND status = 'PENDING'");
        $statement->execute(['id' => $id]);
        if ($statement->rowCount() === 1) {
            set_flash('success', 'Resep ditandai sedang diproses.');
            redirect('pelayanan/detail.php?id=' . $id);
        }
        $errors[] = 'Status resep sudah berubah atau resep tidak dapat diproses.';
    } elseif ($action === 'cancel') {
        $reason = trim((string) ($_POST['cancel_reason'] ?? ''));
        if ($reason === '' || strlen($reason) > 255) {
            $errors[] = 'Alasan pembatalan wajib diisi dan maksimal 255 karakter.';
        } else {
            try {
                $pdo->beginTransaction();
                $lock = $pdo->prepare('SELECT id, status, notes FROM prescriptions WHERE id = :id FOR UPDATE');
                $lock->execute(['id' => $id]);
                $prescription = $lock->fetch();
                if (!$prescription) throw new RuntimeException('Resep tidak ditemukan.');
                if (!in_array($prescription['status'], ['PENDING', 'IN_PROCESS'], true)) throw new RuntimeException('Resep hanya dapat dibatalkan sebelum ada penyerahan obat.');
                $dispensed = $pdo->prepare('SELECT COUNT(*) FROM dispensing_items di JOIN dispensings ds ON ds.id = di.dispensing_id WHERE ds.prescription_id = :id');
                $dispensed->execute(['id' => $id]);
                if ((int) $dispensed->fetchColumn() > 0) throw new RuntimeException('Resep yang sudah memiliki penyerahan tidak dapat dibatalkan.');
                $oldNotes = trim((string) ($prescription['notes'] ?? ''));
                $newNotes = ($oldNotes !== '' ? $oldNotes . "\n" : '') . 'Pembatalan: ' . $reason;
                $update = $pdo->prepare("UPDATE prescriptions SET status = 'CANCELLED', notes = :notes WHERE id = :id");
                $update->execute(['notes' => $newNotes, 'id' => $id]);
                $pdo->commit();
                set_flash('success', 'Resep berhasil dibatalkan.');
                redirect('pelayanan/detail.php?id=' . $id);
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log($exception->getMessage());
                $errors[] = $exception instanceof RuntimeException ? $exception->getMessage() : 'Pembatalan resep gagal disimpan.';
            }
        }
    } elseif ($action === 'dispense') {
        $postedQuantities = $_POST['quantity'] ?? [];
        if (!is_array($postedQuantities)) $postedQuantities = [];
        $dispenseValues = $postedQuantities;
        $dispenseNotes = trim((string) ($_POST['dispense_notes'] ?? ''));
        if (strlen($dispenseNotes) > 255) $errors[] = 'Catatan penyerahan maksimal 255 karakter.';

        if (!$errors) {
            try {
                $pdo->beginTransaction();
                $prescriptionLock = $pdo->prepare('SELECT id, prescription_number, status FROM prescriptions WHERE id = :id FOR UPDATE');
                $prescriptionLock->execute(['id' => $id]);
                $lockedPrescription = $prescriptionLock->fetch();
                if (!$lockedPrescription) throw new RuntimeException('Resep tidak ditemukan.');
                if (in_array($lockedPrescription['status'], ['CANCELLED', 'COMPLETED'], true)) throw new RuntimeException('Resep sudah selesai atau dibatalkan.');

                $itemQuery = $pdo->prepare('SELECT pi.id, pi.drug_id, pi.quantity_prescribed, d.drug_name, d.unit, d.stock_qty, d.is_active
                    FROM prescription_items pi JOIN drugs d ON d.id = pi.drug_id
                    WHERE pi.prescription_id = :id ORDER BY d.id, pi.id FOR UPDATE');
                $itemQuery->execute(['id' => $id]);
                $lockedItems = $itemQuery->fetchAll();
                if (!$lockedItems) throw new RuntimeException('Resep tidak memiliki item obat.');

                // Database menyimpan kuantitas dengan 2 angka desimal. Gunakan satuan
                // minor (1/100 unit) agar penjumlahan beberapa baris obat tetap presisi.
                $toMinorUnits = static fn (mixed $value): int => (int) round((float) $value * 100);
                $deliveredQuery = $pdo->prepare('SELECT COALESCE(SUM(di.quantity_dispensed), 0) FROM dispensing_items di WHERE di.prescription_item_id = :item_id');
                $itemMap = [];
                $stockByDrug = [];
                foreach ($lockedItems as $item) {
                    $deliveredQuery->execute(['item_id' => $item['id']]);
                    $item['delivered_qty'] = (float) $deliveredQuery->fetchColumn();
                    $item['remaining_qty'] = max(0, (float) $item['quantity_prescribed'] - $item['delivered_qty']);
                    $itemMap[(string) $item['id']] = $item;

                    $drugKey = (string) $item['drug_id'];
                    if (!isset($stockByDrug[$drugKey])) {
                        $stockByDrug[$drugKey] = [
                            'stock_minor' => $toMinorUnits($item['stock_qty']),
                            'drug_name' => $item['drug_name'],
                            'unit' => $item['unit'],
                        ];
                    }
                }

                $toDispense = [];
                $requestedByDrug = [];
                foreach ($postedQuantities as $itemId => $quantityValue) {
                    $itemId = (string) $itemId;
                    if (!is_scalar($quantityValue)) {
                        throw new RuntimeException('Format jumlah penyerahan tidak valid.');
                    }
                    $quantityValue = trim((string) $quantityValue);
                    if ($quantityValue === '' || (is_numeric($quantityValue) && (float) $quantityValue == 0.0)) continue;
                    if (!ctype_digit($itemId) || !isset($itemMap[$itemId])) throw new RuntimeException('Item resep yang dikirim tidak valid. Muat ulang halaman.');
                    if (!rx_valid_quantity($quantityValue)) throw new RuntimeException('Jumlah penyerahan harus lebih besar dari 0 dan maksimal 2 angka desimal.');

                    $item = $itemMap[$itemId];
                    $amountMinor = $toMinorUnits($quantityValue);
                    $remainingMinor = $toMinorUnits($item['remaining_qty']);
                    if ((int) $item['is_active'] !== 1) throw new RuntimeException('Obat ' . $item['drug_name'] . ' sudah nonaktif dan tidak dapat diserahkan.');
                    if ($amountMinor > $remainingMinor) throw new RuntimeException('Jumlah ' . $item['drug_name'] . ' melebihi sisa resep (' . rx_qty($item['remaining_qty']) . ').');

                    $drugKey = (string) $item['drug_id'];
                    $requestedByDrug[$drugKey] = ($requestedByDrug[$drugKey] ?? 0) + $amountMinor;
                    $toDispense[] = ['item' => $item, 'quantity_minor' => $amountMinor];
                }
                if (!$toDispense) throw new RuntimeException('Isi jumlah pada minimal satu item obat yang akan diserahkan.');

                // Validasi stok berdasarkan total seluruh baris resep untuk obat yang sama,
                // bukan membandingkan tiap baris secara terpisah terhadap stok awal.
                foreach ($requestedByDrug as $drugKey => $requestedMinor) {
                    $availableMinor = $stockByDrug[$drugKey]['stock_minor'];
                    if ($requestedMinor > $availableMinor) {
                        $drug = $stockByDrug[$drugKey];
                        throw new RuntimeException(
                            'Total penyerahan ' . $drug['drug_name'] . ' (' . rx_qty($requestedMinor / 100)
                            . ' ' . $drug['unit'] . ') melebihi stok tersedia (' . rx_qty($availableMinor / 100)
                            . ' ' . $drug['unit'] . ').'
                        );
                    }
                }

                $dispensingNumber = rx_number('SER');
                $dispensingInsert = $pdo->prepare('INSERT INTO dispensings (dispensing_number, prescription_id, served_by, notes) VALUES (:number, :prescription_id, :served_by, :notes)');
                $dispensingInsert->execute([
                    'number' => $dispensingNumber,
                    'prescription_id' => $id,
                    'served_by' => $user['id'] ?? null,
                    'notes' => $dispenseNotes !== '' ? $dispenseNotes : null,
                ]);
                $dispensingId = (int) $pdo->lastInsertId();

                $insertLine = $pdo->prepare('INSERT INTO dispensing_items (dispensing_id, prescription_item_id, quantity_dispensed, notes) VALUES (:dispensing_id, :prescription_item_id, :quantity_dispensed, :notes)');
                $updateDrug = $pdo->prepare('UPDATE drugs SET stock_qty = stock_qty - :quantity WHERE id = :id AND stock_qty >= :quantity_check AND is_active = 1');
                $insertMovement = $pdo->prepare("INSERT INTO stock_movements (drug_id, movement_type, quantity, stock_before, stock_after, dispensing_item_id, notes, created_by) VALUES (:drug_id, 'OUT', :quantity, :stock_before, :stock_after, :dispensing_item_id, :notes, :created_by)");

                // Catatan stok dihitung berurutan per obat. Jika obat yang sama muncul
                // beberapa kali dalam satu resep, saldo baris berikutnya memakai saldo
                // setelah baris sebelumnya, bukan mengulang stok awal.
                $runningStockByDrug = [];
                foreach ($stockByDrug as $drugKey => $drugStock) {
                    $runningStockByDrug[$drugKey] = $drugStock['stock_minor'];
                }

                foreach ($toDispense as $line) {
                    $item = $line['item'];
                    $drugKey = (string) $item['drug_id'];
                    $amountMinor = $line['quantity_minor'];
                    $amount = $amountMinor / 100;
                    $beforeMinor = $runningStockByDrug[$drugKey];
                    $afterMinor = $beforeMinor - $amountMinor;
                    $before = $beforeMinor / 100;
                    $after = $afterMinor / 100;

                    $insertLine->execute([
                        'dispensing_id' => $dispensingId,
                        'prescription_item_id' => $item['id'],
                        'quantity_dispensed' => $amount,
                        'notes' => null,
                    ]);
                    $dispensingItemId = (int) $pdo->lastInsertId();
                    $updateDrug->execute(['quantity' => $amount, 'quantity_check' => $amount, 'id' => $item['drug_id']]);
                    if ($updateDrug->rowCount() !== 1) throw new RuntimeException('Stok ' . $item['drug_name'] . ' berubah atau tidak mencukupi. Silakan muat ulang dan coba lagi.');

                    $movementNotes = 'Penyerahan ' . $lockedPrescription['prescription_number'];
                    if ($dispenseNotes !== '') $movementNotes .= ' — ' . $dispenseNotes;
                    $insertMovement->execute([
                        'drug_id' => $item['drug_id'],
                        'quantity' => $amount,
                        'stock_before' => $before,
                        'stock_after' => $after,
                        'dispensing_item_id' => $dispensingItemId,
                        'notes' => mb_substr($movementNotes, 0, 255),
                        'created_by' => $user['id'] ?? null,
                    ]);

                    $runningStockByDrug[$drugKey] = $afterMinor;
                }

                $remainingQuery = $pdo->prepare('SELECT pi.quantity_prescribed - COALESCE(SUM(di.quantity_dispensed), 0) AS remaining_qty
                    FROM prescription_items pi LEFT JOIN dispensing_items di ON di.prescription_item_id = pi.id
                    WHERE pi.prescription_id = :id GROUP BY pi.id, pi.quantity_prescribed');
                $remainingQuery->execute(['id' => $id]);
                $remainingRows = $remainingQuery->fetchAll();
                $allComplete = count($remainingRows) > 0;
                foreach ($remainingRows as $remainingRow) {
                    if ((float) $remainingRow['remaining_qty'] > 0.00001) $allComplete = false;
                }
                $newStatus = $allComplete ? 'COMPLETED' : 'PARTIALLY_DISPENSED';
                $statusUpdate = $pdo->prepare('UPDATE prescriptions SET status = :status WHERE id = :id');
                $statusUpdate->execute(['status' => $newStatus, 'id' => $id]);

                $pdo->commit();
                set_flash('success', 'Penyerahan ' . $dispensingNumber . ' berhasil dicatat. Stok obat dan status resep sudah diperbarui.');
                redirect('pelayanan/detail.php?id=' . $id);
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log($exception->getMessage());
                $errors[] = $exception instanceof RuntimeException ? $exception->getMessage() : 'Penyerahan gagal disimpan. Tidak ada stok yang diubah.';
            }
        }
    }
}

$headerQuery = $pdo->prepare('SELECT p.id, p.prescription_number, p.prescription_date, p.status, p.notes, p.received_at,
    v.visit_number, v.visit_date, v.visit_notes,
    pa.medical_record_no, pa.full_name AS patient_name, pa.gender, pa.birth_date, pa.phone,
    pc.polyclinic_name, d.doctor_name, u.full_name AS received_by_name
    FROM prescriptions p JOIN visits v ON v.id = p.visit_id
    JOIN patients pa ON pa.id = v.patient_id
    JOIN polyclinics pc ON pc.id = v.polyclinic_id
    JOIN doctors d ON d.id = v.doctor_id
    LEFT JOIN users u ON u.id = p.received_by WHERE p.id = :id LIMIT 1');
$headerQuery->execute(['id' => $id]);
$prescription = $headerQuery->fetch();
if (!$prescription) {
    http_response_code(404);
    exit('Resep tidak ditemukan.');
}

$itemQuery = $pdo->prepare('SELECT pi.id, pi.dose, pi.frequency, pi.usage_instruction, pi.quantity_prescribed, pi.item_notes,
    d.id AS drug_id, d.drug_code, d.drug_name, d.unit, d.stock_qty, d.is_active,
    (SELECT COALESCE(SUM(di.quantity_dispensed), 0) FROM dispensing_items di WHERE di.prescription_item_id = pi.id) AS delivered_qty
    FROM prescription_items pi JOIN drugs d ON d.id = pi.drug_id
    WHERE pi.prescription_id = :id ORDER BY pi.id');
$itemQuery->execute(['id' => $id]);
$items = $itemQuery->fetchAll();
foreach ($items as &$item) {
    $item['remaining_qty'] = max(0, (float) $item['quantity_prescribed'] - (float) $item['delivered_qty']);
    $item['suggested_qty'] = min((float) $item['remaining_qty'], (int) $item['is_active'] === 1 ? (float) $item['stock_qty'] : 0.0);
}
unset($item);

$historyQuery = $pdo->prepare('SELECT ds.id AS dispensing_id, ds.dispensing_number, ds.dispensed_at, ds.notes AS dispensing_notes,
    u.full_name AS served_by_name, di.quantity_dispensed, pi.dose, d.drug_name, d.unit
    FROM dispensings ds JOIN dispensing_items di ON di.dispensing_id = ds.id
    JOIN prescription_items pi ON pi.id = di.prescription_item_id
    JOIN drugs d ON d.id = pi.drug_id LEFT JOIN users u ON u.id = ds.served_by
    WHERE ds.prescription_id = :id ORDER BY ds.dispensed_at DESC, ds.id DESC, di.id');
$historyQuery->execute(['id' => $id]);
$historyRows = $historyQuery->fetchAll();

$totalPrescribed = 0.0;
$totalDelivered = 0.0;
foreach ($items as $item) {
    $totalPrescribed += (float) $item['quantity_prescribed'];
    $totalDelivered += (float) $item['delivered_qty'];
}
$canDispense = in_array($prescription['status'], ['PENDING', 'IN_PROCESS', 'PARTIALLY_DISPENSED'], true);
$canCancel = in_array($prescription['status'], ['PENDING', 'IN_PROCESS'], true) && $totalDelivered <= 0.00001;

rx_render_start('Detail resep', 'Periksa rincian obat, pantau sisa resep, dan catat penyerahan obat ke pasien.');
?>
<div class="rx-action-row rx-subnav"><a class="quiet-button" href="index.php">← Kembali ke antrean resep</a><span class="rx-status <?= e(rx_status_class($prescription['status'])) ?>"><?= e(rx_status_label($prescription['status'])) ?></span></div>
<?php if ($errors): ?><div class="form-errors rx-detail-errors"><?php foreach (array_unique($errors) as $error): ?><p><?= e($error) ?></p><?php endforeach; ?></div><?php endif; ?>
<section class="rx-detail-hero">
    <div><p class="section-kicker">NOMOR RESEP</p><h2><?= e($prescription['prescription_number']) ?></h2><p><?= e(rx_datetime($prescription['prescription_date'])) ?> · Kunjungan <?= e($prescription['visit_number']) ?></p></div>
    <div class="rx-detail-total"><span>Total diserahkan</span><strong><?= rx_qty($totalDelivered) ?> <small>/ <?= rx_qty($totalPrescribed) ?></small></strong><div class="rx-progress"><span style="width:<?= $totalPrescribed > 0 ? min(100, ($totalDelivered / $totalPrescribed) * 100) : 0 ?>%"></span></div></div>
</section>
<div class="rx-detail-grid">
    <section class="drug-list-panel rx-detail-panel">
        <div class="list-heading"><div><p class="section-kicker">IDENTITAS PASIEN</p><h2><?= e($prescription['patient_name']) ?></h2><p class="list-subtitle">No. rekam medis: <?= e($prescription['medical_record_no']) ?></p></div></div>
        <div class="rx-info-grid">
            <div><span>Jenis kelamin</span><strong><?= $prescription['gender'] === 'L' ? 'Laki-laki' : ($prescription['gender'] === 'P' ? 'Perempuan' : 'Belum diisi') ?></strong></div>
            <div><span>Tanggal lahir</span><strong><?= $prescription['birth_date'] ? e(date('d/m/Y', strtotime($prescription['birth_date']))) : '—' ?></strong></div>
            <div><span>Telepon</span><strong><?= e($prescription['phone'] ?: '—') ?></strong></div>
            <div><span>Poliklinik</span><strong><?= e($prescription['polyclinic_name']) ?></strong></div>
            <div><span>Dokter</span><strong><?= e($prescription['doctor_name']) ?></strong></div>
            <div><span>Tanggal kunjungan</span><strong><?= e(rx_datetime($prescription['visit_date'])) ?></strong></div>
        </div>
        <?php if ($prescription['visit_notes']): ?><div class="rx-note-box"><strong>Catatan kunjungan</strong><p><?= nl2br(e($prescription['visit_notes'])) ?></p></div><?php endif; ?>
        <?php if ($prescription['notes']): ?><div class="rx-note-box"><strong>Catatan resep</strong><p><?= nl2br(e($prescription['notes'])) ?></p></div><?php endif; ?>
    </section>
    <section class="drug-list-panel rx-detail-panel rx-workflow-panel">
        <div class="list-heading"><div><p class="section-kicker">ALUR PELAYANAN</p><h2>Tindakan resep</h2><p class="list-subtitle">Petugas: <?= e($prescription['received_by_name'] ?: '—') ?></p></div></div>
        <?php if ($prescription['status'] === 'PENDING'): ?>
            <div class="rx-step-note"><strong>Resep menunggu verifikasi.</strong><span>Periksa identitas pasien dan kesesuaian item sebelum mulai menyiapkan obat.</span></div>
            <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="start"><button class="primary-button rx-full-button" type="submit">Mulai proses resep</button></form>
        <?php elseif ($prescription['status'] === 'IN_PROCESS'): ?>
            <div class="rx-step-note"><strong>Resep sedang diproses.</strong><span>Isi jumlah obat yang benar-benar diserahkan pada formulir di bawah.</span></div>
        <?php elseif ($prescription['status'] === 'PARTIALLY_DISPENSED'): ?>
            <div class="rx-step-note"><strong>Penyerahan belum lengkap.</strong><span>Resep masih dapat dilanjutkan untuk item yang memiliki sisa.</span></div>
        <?php elseif ($prescription['status'] === 'COMPLETED'): ?>
            <div class="rx-step-note rx-step-success"><strong>Seluruh item resep sudah diserahkan.</strong><span>Transaksi penyerahan telah selesai.</span></div>
        <?php else: ?>
            <div class="rx-step-note rx-step-muted"><strong>Resep dibatalkan.</strong><span>Tidak ada penyerahan lanjutan yang dapat dicatat.</span></div>
        <?php endif; ?>
        <?php if ($canCancel): ?>
            <form method="post" class="rx-cancel-form" onsubmit="return confirm('Yakin ingin membatalkan resep ini?')"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="cancel"><label class="field"><span>Alasan pembatalan</span><input type="text" name="cancel_reason" maxlength="255" placeholder="Wajib diisi jika resep dibatalkan"></label><button type="submit" class="rx-cancel-button">Batalkan resep</button></form>
        <?php endif; ?>
    </section>
</div>
<section class="drug-list-panel rx-detail-panel rx-prescribed-panel">
    <div class="list-heading"><div><p class="section-kicker">RINCIAN OBAT</p><h2>Item dalam resep</h2><p class="list-subtitle">Sisa resep dihitung dari jumlah yang diresepkan dikurangi seluruh penyerahan.</p></div><span class="result-count"><?= count($items) ?> item</span></div>
    <div class="table-wrap"><table class="drug-table rx-items-table"><thead><tr><th>Obat / kode</th><th>Dosis & aturan pakai</th><th>Diresepkan</th><th>Diserahkan</th><th>Sisa</th><th>Stok tersedia</th></tr></thead><tbody>
    <?php foreach ($items as $item): ?>
        <tr>
            <td><div class="rx-primary-cell"><strong><?= e($item['drug_name']) ?></strong><span><?= e($item['drug_code']) ?> · <?= e($item['unit']) ?></span></div></td>
            <td><div class="rx-primary-cell"><strong><?= e($item['dose']) ?></strong><span><?= e($item['frequency'] ?: 'Frekuensi tidak dicantumkan') ?><?= $item['usage_instruction'] ? ' · ' . e($item['usage_instruction']) : '' ?></span></div><?php if ($item['item_notes']): ?><small class="rx-item-note"><?= e($item['item_notes']) ?></small><?php endif; ?></td>
            <td><?= rx_qty($item['quantity_prescribed']) ?> <?= e($item['unit']) ?></td>
            <td><?= rx_qty($item['delivered_qty']) ?> <?= e($item['unit']) ?></td>
            <td><strong class="<?= (float) $item['remaining_qty'] > 0 ? 'rx-remaining' : 'rx-done' ?>"><?= rx_qty($item['remaining_qty']) ?></strong> <?= e($item['unit']) ?></td>
            <td><?= rx_qty($item['stock_qty']) ?> <?= e($item['unit']) ?><?= (int) $item['is_active'] !== 1 ? ' · nonaktif' : '' ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div>
</section>
<?php if ($canDispense): ?>
<section class="drug-form-panel rx-form-panel rx-dispense-panel">
    <div class="drug-form-heading"><div><p class="section-kicker">PENYERAHAN OBAT</p><h2>Catat jumlah yang diserahkan</h2><p class="list-subtitle">Nilai awal diisi berdasarkan jumlah sisa resep dan stok yang tersedia. Ubah bila penyerahan dilakukan sebagian.</p></div></div>
    <form method="post" class="rx-dispense-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="dispense">
        <div class="rx-dispense-list">
            <?php foreach ($items as $item): $maxQty = (float) $item['suggested_qty']; ?>
            <div class="rx-dispense-row">
                <div class="rx-dispense-drug"><strong><?= e($item['drug_name']) ?></strong><span>Sisa resep <?= rx_qty($item['remaining_qty']) ?> <?= e($item['unit']) ?> · stok <?= rx_qty($item['stock_qty']) ?> <?= e($item['unit']) ?></span></div>
                <label class="field"><span>Jumlah diserahkan</span><input type="number" name="quantity[<?= (int) $item['id'] ?>]" min="0" max="<?= e(number_format($maxQty, 2, '.', '')) ?>" step="0.01" value="<?= e(array_key_exists((string) $item['id'], $dispenseValues) ? (string) $dispenseValues[(string) $item['id']] : number_format($maxQty, 2, '.', '')) ?>" <?= $maxQty <= 0 ? 'disabled' : '' ?>><small><?= $maxQty <= 0 ? 'Tidak ada stok tersedia atau sisa resep sudah terpenuhi.' : 'Maksimal ' . rx_qty($maxQty) . ' ' . e($item['unit']) . ' pada transaksi ini.' ?></small></label>
            </div>
            <?php endforeach; ?>
        </div>
        <label class="field rx-dispense-notes"><span>Catatan penyerahan</span><input type="text" name="dispense_notes" maxlength="255" value="<?= e($dispenseNotes) ?>" placeholder="Contoh: diserahkan kepada pasien / keluarga"></label>
        <div class="rx-form-actions"><a class="quiet-button" href="index.php">Kembali</a><button class="primary-button" type="submit">Simpan penyerahan obat</button></div>
    </form>
</section>
<?php endif; ?>
<section class="drug-list-panel rx-detail-panel rx-history-panel">
    <div class="list-heading"><div><p class="section-kicker">RIWAYAT PENYERAHAN</p><h2>Transaksi obat yang sudah diserahkan</h2></div><span class="result-count"><?= count($historyRows) ?> baris detail</span></div>
    <div class="table-wrap"><table class="drug-table rx-history-table"><thead><tr><th>Nomor penyerahan</th><th>Tanggal</th><th>Obat</th><th>Jumlah</th><th>Petugas</th><th>Catatan</th></tr></thead><tbody>
    <?php if (!$historyRows): ?><tr><td colspan="6" class="empty-state"><strong>Belum ada penyerahan obat</strong><span>Transaksi yang disimpan akan tercatat di bagian ini dan Riwayat stok.</span></td></tr>
    <?php else: foreach ($historyRows as $history): ?>
        <tr><td><strong><?= e($history['dispensing_number']) ?></strong></td><td><span class="expiry-label"><?= e(rx_datetime($history['dispensed_at'])) ?></span></td><td><div class="rx-primary-cell"><strong><?= e($history['drug_name']) ?></strong><span><?= e($history['dose']) ?></span></div></td><td><strong class="qty-negative">−<?= rx_qty($history['quantity_dispensed']) ?></strong> <?= e($history['unit']) ?></td><td><?= e($history['served_by_name'] ?: 'Sistem') ?></td><td><?= e($history['dispensing_notes'] ?: '—') ?></td></tr>
    <?php endforeach; endif; ?>
    </tbody></table></div>
</section>
<div class="master-footnote"><strong>Kontrol stok:</strong> penyerahan disimpan dalam satu transaksi database. Jika stok tidak cukup atau jumlah melebihi sisa resep, seluruh transaksi dibatalkan dan stok tidak berubah.</div>
<?php rx_render_end(); ?>
