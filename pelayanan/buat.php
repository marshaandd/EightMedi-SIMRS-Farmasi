<?php
declare(strict_types=1);
require_once __DIR__ . '/_common.php';

$pdo = db();
$user = current_user();
$errors = [];
$nowInput = date('Y-m-d\\TH:i');
$form = [
    'patient_id' => '',
    'polyclinic_id' => '',
    'doctor_id' => '',
    'visit_at' => $nowInput,
    'visit_notes' => '',
    'prescription_date' => $nowInput,
    'notes' => '',
];
$items = [['drug_id' => '', 'dose' => '', 'frequency' => '', 'usage_instruction' => '', 'quantity' => '', 'item_notes' => '']];

$patients = $pdo->query('SELECT id, medical_record_no, full_name FROM patients WHERE is_active = 1 ORDER BY full_name')->fetchAll();
$polyclinics = $pdo->query('SELECT id, polyclinic_name FROM polyclinics WHERE is_active = 1 ORDER BY polyclinic_name')->fetchAll();
$doctors = $pdo->query('SELECT d.id, d.doctor_name, d.polyclinic_id FROM doctors d JOIN polyclinics p ON p.id = d.polyclinic_id WHERE d.is_active = 1 AND p.is_active = 1 ORDER BY d.doctor_name')->fetchAll();
$drugs = $pdo->query('SELECT id, drug_code, drug_name, strength, unit, stock_qty FROM drugs WHERE is_active = 1 ORDER BY drug_name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        exit('Sesi formulir tidak valid. Muat ulang halaman, lalu coba kembali.');
    }
    foreach ($form as $key => $default) $form[$key] = trim((string) ($_POST[$key] ?? $default));
    $postedItems = $_POST['items'] ?? [];
    if (!is_array($postedItems)) $postedItems = [];
    $items = [];
    foreach (array_slice($postedItems, 0, 20, true) as $item) {
        if (!is_array($item)) continue;
        $items[] = [
            'drug_id' => trim((string) ($item['drug_id'] ?? '')),
            'dose' => trim((string) ($item['dose'] ?? '')),
            'frequency' => trim((string) ($item['frequency'] ?? '')),
            'usage_instruction' => trim((string) ($item['usage_instruction'] ?? '')),
            'quantity' => trim((string) ($item['quantity'] ?? '')),
            'item_notes' => trim((string) ($item['item_notes'] ?? '')),
        ];
    }
    if (!$items) $items = [['drug_id' => '', 'dose' => '', 'frequency' => '', 'usage_instruction' => '', 'quantity' => '', 'item_notes' => '']];

    $patientId = filter_var($form['patient_id'], FILTER_VALIDATE_INT);
    $polyclinicId = filter_var($form['polyclinic_id'], FILTER_VALIDATE_INT);
    $doctorId = filter_var($form['doctor_id'], FILTER_VALIDATE_INT);
    $visitAt = rx_date_input_to_sql($form['visit_at']);
    $prescriptionDate = rx_date_input_to_sql($form['prescription_date']);

    if (!$patientId || $patientId < 1) $errors[] = 'Pilih pasien terlebih dahulu.';
    if (!$polyclinicId || $polyclinicId < 1) $errors[] = 'Pilih poliklinik asal resep.';
    if (!$doctorId || $doctorId < 1) $errors[] = 'Pilih dokter yang menangani pasien.';
    if (!$visitAt) $errors[] = 'Tanggal kunjungan tidak valid.';
    if (!$prescriptionDate) $errors[] = 'Tanggal penerimaan resep tidak valid.';
    if (strlen($form['visit_notes']) > 4000) $errors[] = 'Catatan kunjungan terlalu panjang.';
    if (strlen($form['notes']) > 4000) $errors[] = 'Catatan resep terlalu panjang.';
    if (count($items) > 20) $errors[] = 'Satu resep maksimal berisi 20 item obat.';

    $seenDrugs = [];
    $normalizedItems = [];
    foreach ($items as $index => $item) {
        $drugId = filter_var($item['drug_id'], FILTER_VALIDATE_INT);
        if (!$drugId || $drugId < 1) {
            $errors[] = 'Pilih obat pada baris ' . ($index + 1) . '.';
            continue;
        }
        if (isset($seenDrugs[$drugId])) $errors[] = 'Obat yang sama tidak boleh dimasukkan dua kali. Gabungkan jumlahnya pada satu baris.';
        $seenDrugs[$drugId] = true;
        if ($item['dose'] === '' || strlen($item['dose']) > 100) $errors[] = 'Dosis pada baris ' . ($index + 1) . ' wajib diisi (maksimal 100 karakter).';
        if (strlen($item['frequency']) > 100) $errors[] = 'Frekuensi pada baris ' . ($index + 1) . ' maksimal 100 karakter.';
        if (strlen($item['usage_instruction']) > 255) $errors[] = 'Aturan pakai pada baris ' . ($index + 1) . ' maksimal 255 karakter.';
        if (!rx_valid_quantity($item['quantity'])) $errors[] = 'Jumlah resep pada baris ' . ($index + 1) . ' harus lebih besar dari 0 (maksimal 2 angka desimal).';
        if (strlen($item['item_notes']) > 255) $errors[] = 'Catatan item pada baris ' . ($index + 1) . ' maksimal 255 karakter.';
        $normalizedItems[] = [
            'drug_id' => $drugId,
            'dose' => $item['dose'],
            'frequency' => $item['frequency'],
            'usage_instruction' => $item['usage_instruction'],
            'quantity' => $item['quantity'],
            'item_notes' => $item['item_notes'],
        ];
    }
    if (!$normalizedItems) $errors[] = 'Tambahkan minimal satu item obat ke dalam resep.';

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $patientCheck = $pdo->prepare('SELECT id FROM patients WHERE id = :id AND is_active = 1 FOR UPDATE');
            $patientCheck->execute(['id' => $patientId]);
            if (!$patientCheck->fetch()) throw new RuntimeException('Pasien tidak ditemukan atau sudah nonaktif.');

            $doctorCheck = $pdo->prepare('SELECT d.id FROM doctors d JOIN polyclinics p ON p.id = d.polyclinic_id WHERE d.id = :doctor_id AND d.polyclinic_id = :polyclinic_id AND d.is_active = 1 AND p.is_active = 1');
            $doctorCheck->execute(['doctor_id' => $doctorId, 'polyclinic_id' => $polyclinicId]);
            if (!$doctorCheck->fetch()) throw new RuntimeException('Dokter tidak aktif atau tidak sesuai dengan poliklinik yang dipilih.');

            $drugCheck = $pdo->prepare('SELECT id FROM drugs WHERE id = :id AND is_active = 1');
            foreach ($normalizedItems as $item) {
                $drugCheck->execute(['id' => $item['drug_id']]);
                if (!$drugCheck->fetch()) throw new RuntimeException('Salah satu obat tidak ditemukan atau sudah nonaktif. Muat ulang daftar obat.');
            }

            $visitNumber = rx_number('KJG');
            $prescriptionNumber = rx_number('RSP');
            $visitInsert = $pdo->prepare('INSERT INTO visits (visit_number, patient_id, polyclinic_id, doctor_id, visit_date, visit_notes, created_by) VALUES (:visit_number, :patient_id, :polyclinic_id, :doctor_id, :visit_date, :visit_notes, :created_by)');
            $visitInsert->execute([
                'visit_number' => $visitNumber,
                'patient_id' => $patientId,
                'polyclinic_id' => $polyclinicId,
                'doctor_id' => $doctorId,
                'visit_date' => $visitAt,
                'visit_notes' => $form['visit_notes'] !== '' ? $form['visit_notes'] : null,
                'created_by' => $user['id'] ?? null,
            ]);
            $visitId = (int) $pdo->lastInsertId();

            $prescriptionInsert = $pdo->prepare("INSERT INTO prescriptions (prescription_number, visit_id, prescription_date, status, notes, received_by) VALUES (:prescription_number, :visit_id, :prescription_date, 'PENDING', :notes, :received_by)");
            $prescriptionInsert->execute([
                'prescription_number' => $prescriptionNumber,
                'visit_id' => $visitId,
                'prescription_date' => $prescriptionDate,
                'notes' => $form['notes'] !== '' ? $form['notes'] : null,
                'received_by' => $user['id'] ?? null,
            ]);
            $prescriptionId = (int) $pdo->lastInsertId();

            $itemInsert = $pdo->prepare('INSERT INTO prescription_items (prescription_id, drug_id, dose, frequency, usage_instruction, quantity_prescribed, item_notes) VALUES (:prescription_id, :drug_id, :dose, :frequency, :usage_instruction, :quantity_prescribed, :item_notes)');
            foreach ($normalizedItems as $item) {
                $itemInsert->execute([
                    'prescription_id' => $prescriptionId,
                    'drug_id' => $item['drug_id'],
                    'dose' => $item['dose'],
                    'frequency' => $item['frequency'] !== '' ? $item['frequency'] : null,
                    'usage_instruction' => $item['usage_instruction'] !== '' ? $item['usage_instruction'] : null,
                    'quantity_prescribed' => $item['quantity'],
                    'item_notes' => $item['item_notes'] !== '' ? $item['item_notes'] : null,
                ]);
            }

            $pdo->commit();
            set_flash('success', 'Resep berhasil diterima. Nomor resep: ' . $prescriptionNumber . '.');
            redirect('pelayanan/detail.php?id=' . $prescriptionId);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log($exception->getMessage());
            $errors[] = $exception instanceof RuntimeException ? $exception->getMessage() : 'Resep gagal disimpan. Periksa koneksi database, lalu coba kembali.';
        }
    }
}

$dosePresets = [
    '1 tablet', '1 kapsul', '1 sachet', '5 mL', '10 mL',
    '1 sendok takar (5 mL)', '1 tetes', '2 tetes',
    '1 semprot', '1 puff', '1 suppositoria', 'Sesuai resep dokter',
];
$frequencyPresets = [
    '1 kali sehari', '2 kali sehari', '3 kali sehari', '4 kali sehari',
    'Setiap 4 jam', 'Setiap 6 jam', 'Setiap 8 jam', 'Setiap 12 jam',
    'Bila perlu (PRN)', 'Sebelum tidur', 'Sesuai resep dokter',
];
$usagePresets = [
    'Sebelum makan', 'Sesudah makan', 'Bersama makanan', 'Saat perut kosong',
    'Pagi hari', 'Malam hari', 'Sebelum tidur',
    'Kocok dahulu sebelum digunakan', 'Larutkan dahulu sebelum digunakan',
    'Oleskan pada area sesuai resep', 'Teteskan sesuai petunjuk',
    'Sesuai petunjuk dokter',
];
$quantityPresets = ['1', '2', '3', '5', '10', '15', '20', '30', '60', '90', '100'];

$renderPresetOptions = static function (array $options, string $current, string $placeholder): string {
    $html = '<option value="">' . e($placeholder) . '</option>';
    foreach ($options as $option) {
        $selected = $current === $option ? ' selected' : '';
        $html .= '<option value="' . e($option) . '"' . $selected . '>' . e($option) . '</option>';
    }
    if ($current !== '' && !in_array($current, $options, true)) {
        $html .= '<option value="' . e($current) . '" data-custom-value="1" selected>' . e($current) . ' (manual)</option>';
    }
    $html .= '<option value="__CUSTOM__">Lainnya (isi manual)</option>';
    return $html;
};

$drugOptions = '<option value="">Pilih obat</option>';
foreach ($drugs as $drug) {
    $label = $drug['drug_name'] . ' · ' . $drug['drug_code'] . ' — stok ' . rx_qty($drug['stock_qty']) . ' ' . $drug['unit'];
    if ($drug['strength']) $label .= ' (' . $drug['strength'] . ')';
    $drugOptions .= '<option value="' . (int) $drug['id'] . '">' . e($label) . '</option>';
}

rx_render_start('Terima resep baru', 'Catat kunjungan dari poliklinik beserta item obat yang diresepkan. Nomor kunjungan dan nomor resep dibuat otomatis.');
?>
<div class="rx-action-row rx-subnav"><a class="quiet-button" href="index.php">← Kembali ke antrean resep</a></div>
<section class="drug-form-panel rx-form-panel">
    <div class="drug-form-heading"><div><p class="section-kicker">PENERIMAAN DARI POLI</p><h2>Data kunjungan dan resep</h2><p class="list-subtitle">Lengkapi pasien, dokter, poliklinik, lalu tambahkan obat sesuai resep.</p></div></div>
    <?php if ($errors): ?><div class="form-errors"><?php foreach (array_unique($errors) as $error): ?><p><?= e($error) ?></p><?php endforeach; ?></div><?php endif; ?>
    <?php if (!$patients || !$polyclinics || !$doctors || !$drugs): ?>
        <div class="rx-guidance rx-guidance-block">
            <strong>Data referensi belum lengkap.</strong>
            <span><?php if (!$patients): ?>Tambahkan pasien aktif terlebih dahulu. <?php endif; ?><?php if (!$polyclinics || !$doctors): ?>Pastikan master poliklinik dan dokter sudah tersedia. <?php endif; ?><?php if (!$drugs): ?>Tambahkan minimal satu obat aktif.<?php endif; ?></span>
            <a href="../master/index.php">Buka Master data →</a>
        </div>
    <?php else: ?>
    <form method="post" class="rx-form" id="prescription-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <div class="rx-form-section-title"><span>01</span><strong>Identitas kunjungan</strong><small>Resep harus terhubung dengan pasien dan dokter yang menangani.</small></div>
        <label class="field"><span>Pasien <b>*</b></span><select name="patient_id" required><option value="">Pilih pasien</option><?php foreach ($patients as $patient): ?><option value="<?= (int) $patient['id'] ?>" <?= $form['patient_id'] === (string) $patient['id'] ? 'selected' : '' ?>><?= e($patient['full_name']) ?> · <?= e($patient['medical_record_no']) ?></option><?php endforeach; ?></select><small>Belum ada pasien? <a href="../master/pasien.php?tambah=1">Tambah pasien di Master data</a>.</small></label>
        <label class="field"><span>Poliklinik asal <b>*</b></span><select name="polyclinic_id" id="polyclinic-select" required><option value="">Pilih poliklinik</option><?php foreach ($polyclinics as $clinic): ?><option value="<?= (int) $clinic['id'] ?>" <?= $form['polyclinic_id'] === (string) $clinic['id'] ? 'selected' : '' ?>><?= e($clinic['polyclinic_name']) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>Dokter <b>*</b></span><select name="doctor_id" id="doctor-select" required><option value="">Pilih dokter</option><?php foreach ($doctors as $doctor): ?><option value="<?= (int) $doctor['id'] ?>" data-polyclinic="<?= (int) $doctor['polyclinic_id'] ?>" <?= $form['doctor_id'] === (string) $doctor['id'] ? 'selected' : '' ?>><?= e($doctor['doctor_name']) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>Tanggal kunjungan <b>*</b></span><input type="datetime-local" name="visit_at" value="<?= e($form['visit_at']) ?>" required></label>
        <label class="field field-wide"><span>Catatan kunjungan</span><textarea name="visit_notes" rows="2" maxlength="4000" placeholder="Keluhan atau catatan singkat dari poliklinik (opsional)"><?= e($form['visit_notes']) ?></textarea></label>

        <div class="rx-form-section-title"><span>02</span><strong>Informasi resep</strong><small>Nomor resep akan dibuat otomatis setelah data disimpan.</small></div>
        <label class="field"><span>Tanggal penerimaan resep <b>*</b></span><input type="datetime-local" name="prescription_date" value="<?= e($form['prescription_date']) ?>" required></label>
        <label class="field"><span>Catatan resep</span><input type="text" name="notes" maxlength="4000" value="<?= e($form['notes']) ?>" placeholder="Catatan tambahan dari dokter (opsional)"></label>

        <div class="rx-form-section-title rx-form-section-wide"><span>03</span><strong>Rincian obat</strong><small>Isi dosis, frekuensi, aturan pakai, dan jumlah yang diresepkan.</small></div>
        <div class="rx-items-wrap rx-form-section-wide">
            <div class="rx-items-heading"><strong>Daftar item resep</strong><span>Maksimal 20 item</span></div>
            <p class="rx-preset-note">Pilihan cepat hanya membantu pengisian. Pastikan dosis, frekuensi, aturan pakai, dan jumlah sama dengan resep dokter serta sesuai bentuk dan kekuatan obat.</p>
            <div id="prescription-items">
                <?php foreach ($items as $index => $item): ?>
                <div class="rx-item-card" data-item-row>
                    <div class="rx-item-card-top"><strong>Obat <span data-row-number><?= $index + 1 ?></span></strong><button type="button" class="rx-remove-item" data-remove-item <?= count($items) === 1 ? 'disabled' : '' ?>>Hapus</button></div>
                    <label class="field rx-item-drug"><span>Nama obat <b>*</b></span><select name="items[<?= $index ?>][drug_id]" required><option value="">Pilih obat</option><?php foreach ($drugs as $drug): $label = $drug['drug_name'] . ' · ' . $drug['drug_code'] . ' — stok ' . rx_qty($drug['stock_qty']) . ' ' . $drug['unit']; if ($drug['strength']) $label .= ' (' . $drug['strength'] . ')'; ?><option value="<?= (int) $drug['id'] ?>" <?= $item['drug_id'] === (string) $drug['id'] ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
                    <?php
                    $doseCustom = $item['dose'] !== '' && !in_array($item['dose'], $dosePresets, true);
                    $frequencyCustom = $item['frequency'] !== '' && !in_array($item['frequency'], $frequencyPresets, true);
                    $usageCustom = $item['usage_instruction'] !== '' && !in_array($item['usage_instruction'], $usagePresets, true);
                    $quantityCustom = $item['quantity'] !== '' && !in_array($item['quantity'], $quantityPresets, true);
                    ?>
                    <label class="field"><span>Dosis <b>*</b></span><select name="items[<?= $index ?>][dose]" data-preset-select required><?= $renderPresetOptions($dosePresets, $item['dose'], 'Pilih dosis') ?></select><input class="rx-custom-input" type="text" data-custom-input value="<?= $doseCustom ? e($item['dose']) : '' ?>" maxlength="100" placeholder="Tulis dosis sesuai resep" <?= $doseCustom ? '' : 'hidden' ?>></label>
                    <label class="field"><span>Frekuensi</span><select name="items[<?= $index ?>][frequency]" data-preset-select><?= $renderPresetOptions($frequencyPresets, $item['frequency'], 'Pilih frekuensi (opsional)') ?></select><input class="rx-custom-input" type="text" data-custom-input value="<?= $frequencyCustom ? e($item['frequency']) : '' ?>" maxlength="100" placeholder="Tulis frekuensi sesuai resep" <?= $frequencyCustom ? '' : 'hidden' ?>></label>
                    <label class="field"><span>Aturan pakai</span><select name="items[<?= $index ?>][usage_instruction]" data-preset-select><?= $renderPresetOptions($usagePresets, $item['usage_instruction'], 'Pilih aturan pakai (opsional)') ?></select><input class="rx-custom-input" type="text" data-custom-input value="<?= $usageCustom ? e($item['usage_instruction']) : '' ?>" maxlength="255" placeholder="Tulis aturan pakai sesuai resep" <?= $usageCustom ? '' : 'hidden' ?>></label>
                    <label class="field"><span>Jumlah diresepkan <b>*</b></span><select name="items[<?= $index ?>][quantity]" data-preset-select required><?= $renderPresetOptions($quantityPresets, $item['quantity'], 'Pilih jumlah') ?></select><input class="rx-custom-input" type="number" data-custom-input min="0.01" step="0.01" value="<?= $quantityCustom ? e($item['quantity']) : '' ?>" placeholder="Masukkan jumlah" <?= $quantityCustom ? '' : 'hidden' ?>></label>
                    <label class="field rx-item-notes"><span>Catatan item</span><input type="text" name="items[<?= $index ?>][item_notes]" maxlength="255" value="<?= e($item['item_notes']) ?>" placeholder="Opsional"></label>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="rx-add-item" id="add-prescription-item">＋ Tambah obat</button>
        </div>
        <div class="rx-form-actions rx-form-section-wide"><a class="quiet-button" href="index.php">Batal</a><button class="primary-button" type="submit">Simpan dan terima resep</button></div>
    </form>
    <template id="prescription-item-template">
        <div class="rx-item-card" data-item-row>
            <div class="rx-item-card-top"><strong>Obat <span data-row-number>__NUMBER__</span></strong><button type="button" class="rx-remove-item" data-remove-item>Hapus</button></div>
            <label class="field rx-item-drug"><span>Nama obat <b>*</b></span><select name="items[__INDEX__][drug_id]" required><?= $drugOptions ?></select></label>
            <label class="field"><span>Dosis <b>*</b></span><select name="items[__INDEX__][dose]" data-preset-select required><?= $renderPresetOptions($dosePresets, '', 'Pilih dosis') ?></select><input class="rx-custom-input" type="text" data-custom-input maxlength="100" placeholder="Tulis dosis sesuai resep" hidden></label>
            <label class="field"><span>Frekuensi</span><select name="items[__INDEX__][frequency]" data-preset-select><?= $renderPresetOptions($frequencyPresets, '', 'Pilih frekuensi (opsional)') ?></select><input class="rx-custom-input" type="text" data-custom-input maxlength="100" placeholder="Tulis frekuensi sesuai resep" hidden></label>
            <label class="field"><span>Aturan pakai</span><select name="items[__INDEX__][usage_instruction]" data-preset-select><?= $renderPresetOptions($usagePresets, '', 'Pilih aturan pakai (opsional)') ?></select><input class="rx-custom-input" type="text" data-custom-input maxlength="255" placeholder="Tulis aturan pakai sesuai resep" hidden></label>
            <label class="field"><span>Jumlah diresepkan <b>*</b></span><select name="items[__INDEX__][quantity]" data-preset-select required><?= $renderPresetOptions($quantityPresets, '', 'Pilih jumlah') ?></select><input class="rx-custom-input" type="number" data-custom-input min="0.01" step="0.01" placeholder="Masukkan jumlah" hidden></label>
            <label class="field rx-item-notes"><span>Catatan item</span><input type="text" name="items[__INDEX__][item_notes]" maxlength="255" placeholder="Opsional"></label>
        </div>
    </template>
    <?php endif; ?>
</section>
<script>
(function () {
    const clinicSelect = document.getElementById('polyclinic-select');
    const doctorSelect = document.getElementById('doctor-select');
    if (clinicSelect && doctorSelect) {
        const doctorOptions = Array.from(doctorSelect.options).slice(1);
        function filterDoctors() {
            const clinicId = clinicSelect.value;
            doctorOptions.forEach(option => {
                option.hidden = !!clinicId && option.dataset.polyclinic !== clinicId;
                option.disabled = option.hidden;
            });
            if (doctorSelect.selectedOptions[0] && doctorSelect.selectedOptions[0].disabled) doctorSelect.value = '';
        }
        clinicSelect.addEventListener('change', filterDoctors);
        filterDoctors();
    }

    const list = document.getElementById('prescription-items');
    const addButton = document.getElementById('add-prescription-item');
    const template = document.getElementById('prescription-item-template');
    if (!list || !addButton || !template) return;

    let nextIndex = 0;
    list.querySelectorAll('select[name^="items["]').forEach(select => {
        const match = select.name.match(/^items\[(\d+)\]/);
        if (match) nextIndex = Math.max(nextIndex, Number(match[1]) + 1);
    });
    function syncCustomInput(select) {
        const input = select.parentElement.querySelector('[data-custom-input]');
        if (!input) return;
        const selected = select.selectedOptions[0];
        const isCustom = select.value === '__CUSTOM__' || !!(selected && selected.dataset.customValue === '1');
        input.hidden = !isCustom;
        input.required = isCustom && select.required;
        if (!isCustom) input.value = '';
    }

    list.querySelectorAll('[data-preset-select]').forEach(syncCustomInput);
    list.addEventListener('change', function (event) {
        const select = event.target.closest('[data-preset-select]');
        if (select) syncCustomInput(select);
    });

    const prescriptionForm = document.getElementById('prescription-form');
    if (prescriptionForm) {
        prescriptionForm.addEventListener('submit', function (event) {
            let missingCustomInput = null;
            list.querySelectorAll('[data-preset-select]').forEach(select => {
                const selected = select.selectedOptions[0];
                const isCustom = select.value === '__CUSTOM__' || !!(selected && selected.dataset.customValue === '1');
                if (!isCustom) return;

                const input = select.parentElement.querySelector('[data-custom-input]');
                const customValue = input ? input.value.trim() : '';
                if (!customValue) {
                    if (select.required) {
                        if (input) {
                            input.hidden = false;
                            input.required = true;
                            missingCustomInput = input;
                        }
                    } else {
                        select.value = '';
                    }
                    return;
                }

                if (select.value === '__CUSTOM__') {
                    const option = new Option(customValue, customValue, true, true);
                    option.dataset.customValue = '1';
                    select.add(option);
                } else {
                    selected.value = customValue;
                    selected.textContent = customValue + ' (manual)';
                    selected.dataset.customValue = '1';
                }
                select.value = customValue;
                if (input) {
                    input.required = false;
                    input.hidden = true;
                }
            });
            if (missingCustomInput) {
                event.preventDefault();
                missingCustomInput.focus();
            }
        });
    }

    function refreshRows() {
        const rows = Array.from(list.querySelectorAll('[data-item-row]'));
        rows.forEach((row, index) => {
            row.querySelector('[data-row-number]').textContent = String(index + 1);
            const remove = row.querySelector('[data-remove-item]');
            remove.disabled = rows.length === 1;
        });
        addButton.disabled = rows.length >= 20;
        addButton.textContent = rows.length >= 20 ? 'Maksimal 20 item' : '＋ Tambah obat';
    }
    addButton.addEventListener('click', function () {
        const count = list.querySelectorAll('[data-item-row]').length;
        if (count >= 20) return;
        const html = template.innerHTML.replaceAll('__INDEX__', String(nextIndex++)).replaceAll('__NUMBER__', String(count + 1));
        const wrapper = document.createElement('div');
        wrapper.innerHTML = html.trim();
        list.appendChild(wrapper.firstElementChild);
        refreshRows();
    });
    list.addEventListener('click', function (event) {
        const button = event.target.closest('[data-remove-item]');
        if (!button || button.disabled) return;
        button.closest('[data-item-row]').remove();
        refreshRows();
    });
    refreshRows();
})();
</script>
<?php rx_render_end(); ?>
