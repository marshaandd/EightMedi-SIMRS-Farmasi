<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_login();

$user = current_user();
$pdo = db();
$flash = get_flash();
$errors = [];
$formOpen = isset($_GET['tambah']) || isset($_GET['edit']);
$formData = [
    'id' => '',
    'drug_code' => '',
    'drug_name' => '',
    'category_id' => '',
    'dosage_form' => '',
    'strength' => '',
    'unit' => '',
    'unit_price' => '0',
    'minimum_stock' => '0',
    'expiry_date' => '',
    'description' => '',
];

function obat_redirect(): never
{
    redirect('master/obat.php');
}

function obat_valid_date(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

try {
    $categories = $pdo->query(
        'SELECT id, category_name, is_active FROM drug_categories ORDER BY is_active DESC, category_name ASC'
    )->fetchAll();
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    exit('Data kategori tidak dapat dibaca. Periksa koneksi database.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        exit('Sesi formulir tidak valid. Muat ulang halaman, lalu coba kembali.');
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'toggle_status') {
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            set_flash('error', 'Data obat tidak valid.');
            obat_redirect();
        }

        try {
            $statement = $pdo->prepare('UPDATE drugs SET is_active = IF(is_active = 1, 0, 1) WHERE id = :id');
            $statement->execute(['id' => $id]);
            set_flash('success', $statement->rowCount() > 0
                ? 'Status obat berhasil diperbarui.'
                : 'Tidak ada perubahan status obat.');
        } catch (PDOException $exception) {
            error_log($exception->getMessage());
            set_flash('error', 'Status obat gagal diperbarui. Silakan coba lagi.');
        }
        obat_redirect();
    }

    if ($action === 'save') {
        $id = trim((string) ($_POST['id'] ?? ''));
        $formData = [
            'id' => $id,
            'drug_code' => strtoupper(trim((string) ($_POST['drug_code'] ?? ''))),
            'drug_name' => trim((string) ($_POST['drug_name'] ?? '')),
            'category_id' => trim((string) ($_POST['category_id'] ?? '')),
            'dosage_form' => trim((string) ($_POST['dosage_form'] ?? '')),
            'strength' => trim((string) ($_POST['strength'] ?? '')),
            'unit' => trim((string) ($_POST['unit'] ?? '')),
            'unit_price' => trim((string) ($_POST['unit_price'] ?? '0')),
            'minimum_stock' => trim((string) ($_POST['minimum_stock'] ?? '0')),
            'expiry_date' => trim((string) ($_POST['expiry_date'] ?? '')),
            'description' => trim((string) ($_POST['description'] ?? '')),
        ];
        $formOpen = true;

        if ($formData['drug_code'] === '' || mb_strlen($formData['drug_code']) > 50) {
            $errors['drug_code'] = 'Kode obat wajib diisi (maksimal 50 karakter).';
        }
        if ($formData['drug_name'] === '' || mb_strlen($formData['drug_name']) > 180) {
            $errors['drug_name'] = 'Nama obat wajib diisi (maksimal 180 karakter).';
        }
        if ($formData['unit'] === '' || mb_strlen($formData['unit']) > 40) {
            $errors['unit'] = 'Satuan wajib diisi (maksimal 40 karakter).';
        }
        if (mb_strlen($formData['dosage_form']) > 80) {
            $errors['dosage_form'] = 'Bentuk sediaan maksimal 80 karakter.';
        }
        if (mb_strlen($formData['strength']) > 80) {
            $errors['strength'] = 'Kekuatan obat maksimal 80 karakter.';
        }
        if ($formData['unit_price'] === '' || !is_numeric($formData['unit_price']) || (float) $formData['unit_price'] < 0) {
            $errors['unit_price'] = 'Harga harus berupa angka nol atau lebih.';
        }
        if ($formData['minimum_stock'] === '' || !is_numeric($formData['minimum_stock']) || (float) $formData['minimum_stock'] < 0) {
            $errors['minimum_stock'] = 'Batas minimum harus berupa angka nol atau lebih.';
        }
        if ($formData['expiry_date'] !== '' && !obat_valid_date($formData['expiry_date'])) {
            $errors['expiry_date'] = 'Tanggal kedaluwarsa tidak valid.';
        }
        if ($formData['category_id'] !== '' && (!ctype_digit($formData['category_id']) || (int) $formData['category_id'] < 1)) {
            $errors['category_id'] = 'Kategori yang dipilih tidak valid.';
        }
        if ($formData['category_id'] !== '' && !isset($errors['category_id'])) {
            $categoryCheck = $pdo->prepare('SELECT COUNT(*) FROM drug_categories WHERE id = :id');
            $categoryCheck->execute(['id' => (int) $formData['category_id']]);
            if ((int) $categoryCheck->fetchColumn() === 0) {
                $errors['category_id'] = 'Kategori tidak ditemukan.';
            }
        }
        if ($id !== '' && (!ctype_digit($id) || (int) $id < 1)) {
            $errors['general'] = 'Identitas obat tidak valid.';
        }

        if (!$errors) {
            $values = [
                'drug_code' => $formData['drug_code'],
                'drug_name' => $formData['drug_name'],
                'category_id' => $formData['category_id'] === '' ? null : (int) $formData['category_id'],
                'dosage_form' => $formData['dosage_form'] === '' ? null : $formData['dosage_form'],
                'strength' => $formData['strength'] === '' ? null : $formData['strength'],
                'unit' => $formData['unit'],
                'unit_price' => (float) $formData['unit_price'],
                'minimum_stock' => (float) $formData['minimum_stock'],
                'expiry_date' => $formData['expiry_date'] === '' ? null : $formData['expiry_date'],
                'description' => $formData['description'] === '' ? null : $formData['description'],
            ];

            try {
                if ($id !== '') {
                    $values['id'] = (int) $id;
                    $sql = 'UPDATE drugs SET
                                drug_code = :drug_code,
                                drug_name = :drug_name,
                                category_id = :category_id,
                                dosage_form = :dosage_form,
                                strength = :strength,
                                unit = :unit,
                                unit_price = :unit_price,
                                minimum_stock = :minimum_stock,
                                expiry_date = :expiry_date,
                                description = :description
                            WHERE id = :id';
                    $pdo->prepare($sql)->execute($values);
                    set_flash('success', 'Informasi obat berhasil diperbarui. Stok tidak diubah.');
                } else {
                    $sql = 'INSERT INTO drugs
                                (drug_code, drug_name, category_id, dosage_form, strength, unit,
                                 unit_price, stock_qty, minimum_stock, expiry_date, description, is_active)
                            VALUES
                                (:drug_code, :drug_name, :category_id, :dosage_form, :strength, :unit,
                                 :unit_price, 0.00, :minimum_stock, :expiry_date, :description, 1)';
                    $pdo->prepare($sql)->execute($values);
                    set_flash('success', 'Obat baru berhasil ditambahkan dengan stok awal 0. Stok masuk dicatat melalui modul persediaan.');
                }
                obat_redirect();
            } catch (PDOException $exception) {
                error_log($exception->getMessage());
                if ($exception->getCode() === '23000') {
                    $errors['drug_code'] = 'Kode obat sudah digunakan. Gunakan kode yang berbeda.';
                } else {
                    $errors['general'] = 'Data obat gagal disimpan. Periksa kembali isian dan coba lagi.';
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['edit'])) {
    $editId = filter_var($_GET['edit'], FILTER_VALIDATE_INT);
    if ($editId && $editId > 0) {
        $editStatement = $pdo->prepare('SELECT * FROM drugs WHERE id = :id LIMIT 1');
        $editStatement->execute(['id' => $editId]);
        $existingDrug = $editStatement->fetch();
        if ($existingDrug) {
            $formData = [
                'id' => (string) $existingDrug['id'],
                'drug_code' => (string) $existingDrug['drug_code'],
                'drug_name' => (string) $existingDrug['drug_name'],
                'category_id' => $existingDrug['category_id'] === null ? '' : (string) $existingDrug['category_id'],
                'dosage_form' => (string) ($existingDrug['dosage_form'] ?? ''),
                'strength' => (string) ($existingDrug['strength'] ?? ''),
                'unit' => (string) $existingDrug['unit'],
                'unit_price' => (string) $existingDrug['unit_price'],
                'minimum_stock' => (string) $existingDrug['minimum_stock'],
                'expiry_date' => (string) ($existingDrug['expiry_date'] ?? ''),
                'description' => (string) ($existingDrug['description'] ?? ''),
            ];
            $formOpen = true;
        } else {
            set_flash('error', 'Data obat yang akan diedit tidak ditemukan.');
            obat_redirect();
        }
    } else {
        set_flash('error', 'Identitas obat tidak valid.');
        obat_redirect();
    }
}

$keyword = trim((string) ($_GET['q'] ?? ''));
$categoryFilter = trim((string) ($_GET['kategori'] ?? ''));
$statusFilter = (string) ($_GET['status'] ?? 'aktif');
$allowedStatuses = ['aktif', 'nonaktif', 'semua', 'stok-rendah', 'kedaluwarsa'];
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'aktif';
}

$where = [];
$params = [];
if ($keyword !== '') {
    $where[] = '(d.drug_code LIKE :keyword_code OR d.drug_name LIKE :keyword_name OR d.strength LIKE :keyword_strength)';
    $like = '%' . $keyword . '%';
    $params['keyword_code'] = $like;
    $params['keyword_name'] = $like;
    $params['keyword_strength'] = $like;
}
if ($categoryFilter !== '' && ctype_digit($categoryFilter)) {
    $where[] = 'd.category_id = :category_id';
    $params['category_id'] = (int) $categoryFilter;
}
if ($statusFilter === 'aktif') {
    $where[] = 'd.is_active = 1';
} elseif ($statusFilter === 'nonaktif') {
    $where[] = 'd.is_active = 0';
} elseif ($statusFilter === 'stok-rendah') {
    $where[] = 'd.is_active = 1 AND d.stock_qty <= d.minimum_stock';
} elseif ($statusFilter === 'kedaluwarsa') {
    $where[] = 'd.is_active = 1 AND d.expiry_date IS NOT NULL AND d.expiry_date < CURDATE()';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$perPage = 12;
$page = max(1, (int) ($_GET['halaman'] ?? 1));
$countStatement = $pdo->prepare("SELECT COUNT(*) FROM drugs d {$whereSql}");
$countStatement->execute($params);
$totalRows = (int) $countStatement->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$listSql = "SELECT d.*, c.category_name
            FROM drugs d
            LEFT JOIN drug_categories c ON c.id = d.category_id
            {$whereSql}
            ORDER BY d.drug_name ASC
            LIMIT :limit OFFSET :offset";
$listStatement = $pdo->prepare($listSql);
foreach ($params as $key => $value) {
    $listStatement->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$listStatement->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStatement->execute();
$drugs = $listStatement->fetchAll();

$stats = $pdo->query(
    'SELECT COUNT(*) AS total,
            SUM(is_active = 1) AS active,
            SUM(is_active = 1 AND stock_qty <= minimum_stock) AS low_stock,
            SUM(is_active = 1 AND expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)) AS near_expiry
     FROM drugs'
)->fetch();

function obat_query_url(array $changes = []): string
{
    $query = array_merge($_GET, $changes);
    unset($query['edit'], $query['tambah']);
    foreach ($query as $key => $value) {
        if ($value === '' || $value === null) {
            unset($query[$key]);
        }
    }
    return 'obat.php' . ($query ? '?' . http_build_query($query) : '');
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f7dbe9">
    <title>Master Obat · <?= e(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/app.css">
    <link rel="stylesheet" href="../assets/css/master.css">
<link rel="stylesheet" href="../assets/css/theme.css?v=1">
</head>
<body class="app-body">
<header class="app-header">
    <a class="brand" href="../dashboard.php">
        <span class="brand-mark"><span></span><i></i></span>
        <span class="brand-name">Eight<span>Medi</span></span>
    </a>

    <nav class="main-nav" aria-label="Navigasi utama">
        <a href="../dashboard.php">Ringkasan</a>
        <a class="active" href="index.php">Master data</a>
        <a href="../pelayanan/index.php">Pelayanan resep</a>
        <a href="../persediaan/index.php">Persediaan</a>
        <a href="../laporan/index.php">Laporan</a>
        <a href="../dashboard.php#status">Status sistem</a>
    </nav>

    <div class="header-account">
        <div class="account-copy">
            <strong><?= e($user['full_name'] ?? 'Pengguna') ?></strong>
            <span>Pengguna farmasi</span>
        </div>
        <div class="avatar"><?= e(mb_strtoupper(mb_substr((string) ($user['full_name'] ?? 'U'), 0, 1))) ?></div>
        <form method="post" action="../auth/logout.php">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <button class="logout-button" type="submit" title="Keluar" aria-label="Keluar">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 17l5-5-5-5M15 12H3M13 3h5a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-5"></path></svg>
            </button>
        </form>
    </div>
</header>

<main class="dashboard-main master-main">
    <?php if ($flash): ?>
        <div class="master-alert <?= ($flash['type'] ?? '') === 'success' ? 'is-success' : 'is-error' ?>"><?= e($flash['message'] ?? '') ?></div>
    <?php endif; ?>

    <div class="master-breadcrumb"><a href="../dashboard.php">Ringkasan</a><span>/</span><a href="index.php">Master data</a><span>/</span><strong>Obat</strong></div>

    <section class="master-title-row">
        <div>
            <p class="section-kicker">MASTER DATA FARMASI</p>
            <h1>Master obat</h1>
            <p class="welcome-description">Kelola identitas, kategori, harga referensi, dan informasi kedaluwarsa obat.</p>
        </div>
        <a class="primary-button" href="obat.php?tambah=1"><span>＋</span> Tambah obat</a>
    </section>

    <section class="master-stats" aria-label="Ringkasan master obat">
        <article class="master-stat"><span>Total obat</span><strong><?= number_format((int) ($stats['total'] ?? 0), 0, ',', '.') ?></strong><small>Seluruh data terdaftar</small></article>
        <article class="master-stat"><span>Obat aktif</span><strong><?= number_format((int) ($stats['active'] ?? 0), 0, ',', '.') ?></strong><small>Dapat digunakan pada pelayanan</small></article>
        <article class="master-stat stat-warning"><span>Stok perlu perhatian</span><strong><?= number_format((int) ($stats['low_stock'] ?? 0), 0, ',', '.') ?></strong><small>Di bawah atau sama dengan minimum</small></article>
        <article class="master-stat stat-expiry"><span>Mendekati kedaluwarsa</span><strong><?= number_format((int) ($stats['near_expiry'] ?? 0), 0, ',', '.') ?></strong><small>Dalam 90 hari ke depan</small></article>
    </section>

    <?php if ($formOpen): ?>
        <section class="drug-form-panel" id="form-obat">
            <div class="drug-form-heading">
                <div>
                    <p class="section-kicker"><?= $formData['id'] !== '' ? 'PEMBARUAN DATA' : 'DATA BARU' ?></p>
                    <h2><?= $formData['id'] !== '' ? 'Edit informasi obat' : 'Tambah obat baru' ?></h2>
                </div>
                <a class="quiet-button" href="obat.php">Tutup</a>
            </div>
            <?php if ($errors): ?>
                <div class="form-errors">
                    <?php foreach ($errors as $message): ?><p><?= e($message) ?></p><?php endforeach; ?>
                </div>
            <?php endif; ?>
            <form method="post" action="obat.php<?= $formData['id'] !== '' ? '?edit=' . e($formData['id']) : '?tambah=1' ?>" class="drug-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= e($formData['id']) ?>">

                <label class="field"><span>Kode obat <b>*</b></span><input name="drug_code" maxlength="50" required value="<?= e($formData['drug_code']) ?>" placeholder="Contoh: OBT-021"><small>Kode harus unik.</small></label>
                <label class="field"><span>Nama obat <b>*</b></span><input name="drug_name" maxlength="180" required value="<?= e($formData['drug_name']) ?>" placeholder="Nama generik atau nama obat"></label>
                <label class="field"><span>Kategori</span><select name="category_id"><option value="">Tanpa kategori</option><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>" <?= (string) $category['id'] === $formData['category_id'] ? 'selected' : '' ?>><?= e($category['category_name']) ?><?= (int) $category['is_active'] === 0 ? ' (nonaktif)' : '' ?></option><?php endforeach; ?></select></label>
                <label class="field"><span>Bentuk sediaan</span><input name="dosage_form" maxlength="80" value="<?= e($formData['dosage_form']) ?>" placeholder="Tablet, kapsul, sirup, krim"></label>
                <label class="field"><span>Kekuatan / konsentrasi</span><input name="strength" maxlength="80" value="<?= e($formData['strength']) ?>" placeholder="Contoh: 500 mg"></label>
                <label class="field"><span>Satuan <b>*</b></span><input name="unit" maxlength="40" required value="<?= e($formData['unit']) ?>" placeholder="tablet, kapsul, botol, tube"></label>
                <label class="field"><span>Harga referensi (Rp) <b>*</b></span><input type="number" name="unit_price" min="0" step="0.01" required value="<?= e($formData['unit_price']) ?>"></label>
                <label class="field"><span>Batas minimum stok <b>*</b></span><input type="number" name="minimum_stock" min="0" step="0.01" required value="<?= e($formData['minimum_stock']) ?>"></label>
                <label class="field"><span>Tanggal kedaluwarsa acuan</span><input type="date" name="expiry_date" value="<?= e($formData['expiry_date']) ?>"><small>Satu tanggal acuan per obat sesuai rancangan demonstrasi.</small></label>
                <label class="field field-wide"><span>Keterangan</span><textarea name="description" rows="3" placeholder="Informasi tambahan (opsional)"><?= e($formData['description']) ?></textarea></label>

                <div class="stock-locked-note field-wide"><span class="lock-mark">↗</span><div><strong>Stok dikelola melalui transaksi persediaan</strong><p>Stok saat ini tidak dapat diedit di halaman master. Obat baru akan memiliki stok awal 0.</p></div></div>
                <div class="form-actions field-wide"><a class="quiet-button" href="obat.php">Batal</a><button class="primary-button" type="submit">Simpan data obat</button></div>
            </form>
        </section>
    <?php endif; ?>

    <section class="drug-list-panel">
        <div class="list-heading">
            <div>
                <p class="section-kicker">KATALOG OBAT</p>
                <h2>Daftar obat</h2>
                <p class="list-subtitle">Data master yang tersimpan di database SIMRS Farmasi.</p>
            </div>
            <span class="result-count"><?= number_format($totalRows, 0, ',', '.') ?> data ditemukan</span>
        </div>

        <form method="get" action="obat.php" class="filter-bar">
            <label class="search-field"><span class="visually-hidden">Cari obat</span><input type="search" name="q" value="<?= e($keyword) ?>" placeholder="Cari kode, nama, atau kekuatan obat..."></label>
            <label class="filter-field"><span class="visually-hidden">Kategori</span><select name="kategori"><option value="">Semua kategori</option><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>" <?= $categoryFilter === (string) $category['id'] ? 'selected' : '' ?>><?= e($category['category_name']) ?></option><?php endforeach; ?></select></label>
            <label class="filter-field"><span class="visually-hidden">Status</span><select name="status"><option value="aktif" <?= $statusFilter === 'aktif' ? 'selected' : '' ?>>Obat aktif</option><option value="nonaktif" <?= $statusFilter === 'nonaktif' ? 'selected' : '' ?>>Obat nonaktif</option><option value="semua" <?= $statusFilter === 'semua' ? 'selected' : '' ?>>Semua status</option><option value="stok-rendah" <?= $statusFilter === 'stok-rendah' ? 'selected' : '' ?>>Stok rendah</option><option value="kedaluwarsa" <?= $statusFilter === 'kedaluwarsa' ? 'selected' : '' ?>>Kedaluwarsa</option></select></label>
            <button class="filter-button" type="submit">Terapkan</button>
            <?php if ($keyword !== '' || $categoryFilter !== '' || $statusFilter !== 'aktif'): ?><a class="reset-filter" href="obat.php">Reset</a><?php endif; ?>
        </form>

        <div class="table-wrap">
            <table class="drug-table">
                <thead><tr><th>Obat</th><th>Kategori</th><th>Stok</th><th>Harga referensi</th><th>Kedaluwarsa</th><th>Status</th><th>Aksi</th></tr></thead>
                <tbody>
                <?php if (!$drugs): ?>
                    <tr><td colspan="7" class="empty-state"><strong>Data tidak ditemukan</strong><span>Coba ubah kata pencarian atau filter yang digunakan.</span></td></tr>
                <?php else: ?>
                    <?php foreach ($drugs as $drug): ?>
                        <?php
                            $expiryLabel = '—';
                            $expiryClass = '';
                            if (!empty($drug['expiry_date'])) {
                                $expiryDate = new DateTimeImmutable((string) $drug['expiry_date']);
                                $today = new DateTimeImmutable('today');
                                $daysLeft = (int) $today->diff($expiryDate)->format('%r%a');
                                $expiryLabel = $expiryDate->format('d/m/Y');
                                if ($daysLeft < 0) {
                                    $expiryClass = 'date-expired';
                                    $expiryLabel .= ' · lewat';
                                } elseif ($daysLeft <= 90) {
                                    $expiryClass = 'date-soon';
                                    $expiryLabel .= ' · segera';
                                }
                            }
                            $isLow = (float) $drug['stock_qty'] <= (float) $drug['minimum_stock'];
                        ?>
                        <tr>
                            <td><div class="drug-name-cell"><strong><?= e($drug['drug_name']) ?></strong><span><?= e($drug['drug_code']) ?><?= $drug['strength'] ? ' · ' . e($drug['strength']) : '' ?><?= $drug['dosage_form'] ? ' · ' . e($drug['dosage_form']) : '' ?></span></div></td>
                            <td><span class="category-label"><?= e($drug['category_name'] ?? 'Tanpa kategori') ?></span></td>
                            <td><div class="stock-cell <?= $isLow ? 'stock-low' : 'stock-ok' ?>"><strong><?= number_format((float) $drug['stock_qty'], 2, ',', '.') ?></strong><span><?= e($drug['unit']) ?> · min <?= number_format((float) $drug['minimum_stock'], 2, ',', '.') ?></span></div></td>
                            <td class="price-cell">Rp <?= number_format((float) $drug['unit_price'], 0, ',', '.') ?></td>
                            <td><span class="expiry-label <?= e($expiryClass) ?>"><?= e($expiryLabel) ?></span></td>
                            <td><span class="status-pill <?= (int) $drug['is_active'] === 1 ? 'status-active' : 'status-inactive' ?>"><?= (int) $drug['is_active'] === 1 ? 'Aktif' : 'Nonaktif' ?></span></td>
                            <td><div class="row-actions"><a class="action-link" href="obat.php?edit=<?= (int) $drug['id'] ?>">Edit</a><form method="post" action="obat.php" onsubmit="return confirm('<?= (int) $drug['is_active'] === 1 ? 'Nonaktifkan' : 'Aktifkan kembali' ?> obat ini?')"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="id" value="<?= (int) $drug['id'] ?>"><button class="action-link action-toggle" type="submit"><?= (int) $drug['is_active'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?></button></form></div></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="pagination"><span>Halaman <?= $page ?> dari <?= $totalPages ?></span><div><?php if ($page > 1): ?><a href="<?= e(obat_query_url(['halaman' => $page - 1])) ?>">← Sebelumnya</a><?php endif; ?><?php if ($page < $totalPages): ?><a href="<?= e(obat_query_url(['halaman' => $page + 1])) ?>">Berikutnya →</a><?php endif; ?></div></div>
        <?php endif; ?>
    </section>

    <div class="master-footnote"><strong>Catatan pengelolaan:</strong> perubahan identitas obat dicatat melalui master data. Stok masuk dan stok keluar akan dicatat pada modul persediaan agar riwayat pergerakan stok tetap terjaga.</div>
    <footer class="dashboard-footer"><span>EightMedi · SIMRS Farmasi</span><span>Master data obat · <?= date('Y') ?></span></footer>
</main>
</body>
</html>
