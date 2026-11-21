<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_login();

if (!isset($entityConfig) || !is_array($entityConfig)) {
    http_response_code(500);
    exit('Konfigurasi modul tidak ditemukan.');
}

$pdo = db();
$user = current_user();
$flash = get_flash();
$errors = [];
$config = $entityConfig;
$table = $config['table'];
$fields = $config['fields'];
$formOpen = isset($_GET['tambah']) || isset($_GET['edit']);
$formData = [];
foreach ($fields as $field) {
    $formData[$field['name']] = $field['default'] ?? '';
}
$formData['id'] = '';

function entity_redirect(): never
{
    redirect('master/' . (string) ($GLOBALS['config']['slug'] ?? 'index') . '.php');
}

function entity_value(array $row, string $key): string
{
    $value = $row[$key] ?? '';
    return $value === null || $value === '' ? '—' : (string) $value;
}

// Load select options declared by a trusted local module configuration.
$optionsByField = [];
foreach ($fields as $field) {
    if (($field['type'] ?? '') === 'select' && !empty($field['options_sql'])) {
        $optionsByField[$field['name']] = $pdo->query($field['options_sql'])->fetchAll();
    }
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
            set_flash('error', 'Identitas data tidak valid.');
            entity_redirect();
        }
        try {
            $statement = $pdo->prepare("UPDATE `{$table}` SET is_active = IF(is_active = 1, 0, 1) WHERE id = :id");
            $statement->execute(['id' => $id]);
            set_flash('success', $statement->rowCount() > 0 ? 'Status data berhasil diperbarui.' : 'Tidak ada perubahan status data.');
        } catch (PDOException $exception) {
            error_log($exception->getMessage());
            set_flash('error', 'Status data gagal diperbarui.');
        }
        entity_redirect();
    }

    if ($action === 'save') {
        $id = trim((string) ($_POST['id'] ?? ''));
        $formData['id'] = $id;
        $formOpen = true;
        $values = [];

        foreach ($fields as $field) {
            $name = $field['name'];
            $value = trim((string) ($_POST[$name] ?? ''));
            $formData[$name] = $value;
            $label = $field['label'];
            $max = (int) ($field['max'] ?? 255);
            $required = (bool) ($field['required'] ?? false);

            if ($required && $value === '') {
                $errors[$name] = $label . ' wajib diisi.';
                continue;
            }
            if (mb_strlen($value, 'UTF-8') > $max) {
                $errors[$name] = $label . ' maksimal ' . $max . ' karakter.';
                continue;
            }
            if (($field['type'] ?? '') === 'email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors[$name] = 'Format email tidak valid.';
                continue;
            }
            if (($field['type'] ?? '') === 'select' && $value !== '') {
                // Select values are checked against options from the database.
                // This supports both numeric foreign keys and string ENUM values.
                $validIds = array_map(static fn(array $option): string => (string) $option['id'], $optionsByField[$name] ?? []);
                if (!in_array($value, $validIds, true)) {
                    $errors[$name] = 'Pilihan ' . strtolower($label) . ' tidak ditemukan.';
                    continue;
                }
            }
            if (($field['type'] ?? '') === 'date' && $value !== '') {
                $date = DateTime::createFromFormat('!Y-m-d', $value);
                if (!$date || $date->format('Y-m-d') !== $value) {
                    $errors[$name] = 'Format ' . strtolower($label) . ' tidak valid.';
                    continue;
                }
            }
            $values[$name] = $value === '' ? null : $value;
        }

        if ($id !== '' && (!ctype_digit($id) || (int) $id < 1)) {
            $errors['general'] = 'Identitas data tidak valid.';
        }

        if (!$errors) {
            try {
                if ($id !== '') {
                    $setParts = [];
                    foreach ($fields as $field) {
                        $name = $field['name'];
                        $setParts[] = "`{$name}` = :{$name}";
                    }
                    $values['id'] = (int) $id;
                    $sql = "UPDATE `{$table}` SET " . implode(', ', $setParts) . ' WHERE id = :id';
                    $pdo->prepare($sql)->execute($values);
                    set_flash('success', $config['singular'] . ' berhasil diperbarui.');
                } else {
                    $columnNames = array_map(static fn(array $field): string => '`' . $field['name'] . '`', $fields);
                    $placeholders = array_map(static fn(array $field): string => ':' . $field['name'], $fields);
                    $columnNames[] = '`is_active`';
                    $placeholders[] = '1';
                    if (!empty($config['auto_created_by'])) {
                        $columnNames[] = '`created_by`';
                        $placeholders[] = ':_auto_created_by';
                        $values['_auto_created_by'] = isset($user['id']) ? (int) $user['id'] : null;
                    }
                    $sql = "INSERT INTO `{$table}` (" . implode(', ', $columnNames) . ') VALUES (' . implode(', ', $placeholders) . ')';
                    $pdo->prepare($sql)->execute($values);
                    set_flash('success', $config['singular'] . ' baru berhasil ditambahkan.');
                }
                entity_redirect();
            } catch (PDOException $exception) {
                error_log($exception->getMessage());
                if ($exception->getCode() === '23000') {
                    $errors['general'] = 'Data tidak dapat disimpan karena ada nilai yang sudah digunakan atau terkait dengan data lain.';
                } else {
                    $errors['general'] = 'Data gagal disimpan. Periksa kembali isian dan struktur database.';
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['edit'])) {
    $editId = filter_var($_GET['edit'], FILTER_VALIDATE_INT);
    if ($editId && $editId > 0) {
        $statement = $pdo->prepare("SELECT * FROM `{$table}` WHERE id = :id LIMIT 1");
        $statement->execute(['id' => $editId]);
        $existing = $statement->fetch();
        if ($existing) {
            $formData['id'] = (string) $existing['id'];
            foreach ($fields as $field) {
                $name = $field['name'];
                $formData[$name] = (string) ($existing[$name] ?? '');
            }
            $formOpen = true;
        } else {
            set_flash('error', 'Data yang akan diedit tidak ditemukan.');
            entity_redirect();
        }
    } else {
        set_flash('error', 'Identitas data tidak valid.');
        entity_redirect();
    }
}

$keyword = trim((string) ($_GET['q'] ?? ''));
$statusFilter = (string) ($_GET['status'] ?? 'aktif');
if (!in_array($statusFilter, ['aktif', 'nonaktif', 'semua'], true)) {
    $statusFilter = 'aktif';
}
$where = [];
$params = [];
if ($keyword !== '') {
    $searchParts = [];
    foreach ($config['search_columns'] as $index => $column) {
        $parameter = 'search_' . $index;
        $searchParts[] = $column . ' LIKE :' . $parameter;
        $params[$parameter] = '%' . $keyword . '%';
    }
    $where[] = '(' . implode(' OR ', $searchParts) . ')';
}
if ($statusFilter === 'aktif') {
    $where[] = 'e.is_active = 1';
} elseif ($statusFilter === 'nonaktif') {
    $where[] = 'e.is_active = 0';
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$statsStatement = $pdo->query("SELECT COUNT(*) AS total, COALESCE(SUM(is_active = 1), 0) AS active, COALESCE(SUM(is_active = 0), 0) AS inactive FROM `{$table}`");
$stats = $statsStatement->fetch();
$countStatement = $pdo->prepare('SELECT COUNT(*) ' . $config['from_sql'] . $whereSql);
$countStatement->execute($params);
$totalRows = (int) $countStatement->fetchColumn();
$perPage = 12;
$page = max(1, (int) ($_GET['halaman'] ?? 1));
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$listSql = $config['select_sql'] . $whereSql . ' ORDER BY ' . $config['order_by'] . ' LIMIT :limit OFFSET :offset';
$listStatement = $pdo->prepare($listSql);
foreach ($params as $key => $value) {
    $listStatement->bindValue(':' . $key, $value, PDO::PARAM_STR);
}
$listStatement->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStatement->execute();
$rows = $listStatement->fetchAll();

function entity_query_url(array $changes = []): string
{
    $query = array_merge($_GET, $changes);
    unset($query['edit'], $query['tambah']);
    foreach ($query as $key => $value) {
        if ($value === '' || $value === null) unset($query[$key]);
    }
    $slug = (string) ($GLOBALS['config']['slug'] ?? 'index');
    return $slug . '.php' . ($query ? '?' . http_build_query($query) : '');
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f7dbe9">
    <title><?= e($config['title']) ?> · <?= e(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/app.css">
    <link rel="stylesheet" href="../assets/css/master.css">
    <link rel="stylesheet" href="../assets/css/theme.css?v=1">
</head>
<body class="app-body">
<header class="app-header">
    <a class="brand" href="../dashboard.php"><span class="brand-mark"><span></span><i></i></span><span class="brand-name">Eight<span>Medi</span></span></a>
    <nav class="main-nav" aria-label="Navigasi utama">
        <a href="../dashboard.php">Ringkasan</a>
        <a class="active" href="index.php">Master data</a>
        <a href="../pelayanan/index.php">Pelayanan resep</a>
        <a href="../persediaan/index.php">Persediaan</a>
        <a href="../laporan/index.php">Laporan</a><?php if (($user['role'] ?? '') === 'ADMIN'): ?><a href="../auth/users.php">Pengguna</a><?php endif; ?>
        <a href="../dashboard.php#status">Status sistem</a>
    </nav>
    <div class="header-account">
        <div class="account-copy"><strong><?= e($user['full_name'] ?? 'Pengguna') ?></strong><span><a href="../auth/change-password.php">Ubah password</a> · Pengguna farmasi</span></div>
        <div class="avatar"><?= e(mb_strtoupper(mb_substr((string) ($user['full_name'] ?? 'U'), 0, 1))) ?></div>
        <form method="post" action="../auth/logout.php"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button class="logout-button" type="submit" title="Keluar" aria-label="Keluar"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 17l5-5-5-5M15 12H3M13 3h5a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-5"></path></svg></button></form>
    </div>
</header>
<main class="dashboard-main master-main">
    <?php if ($flash): ?><div class="master-alert <?= ($flash['type'] ?? '') === 'success' ? 'is-success' : 'is-error' ?>"><?= e($flash['message'] ?? '') ?></div><?php endif; ?>
    <div class="master-breadcrumb"><a href="../dashboard.php">Ringkasan</a><span>/</span><a href="index.php">Master data</a><span>/</span><strong><?= e($config['title']) ?></strong></div>
    <section class="master-title-row"><div><p class="section-kicker">MASTER DATA FARMASI</p><h1><?= e($config['title']) ?></h1><p class="welcome-description"><?= e($config['description']) ?></p></div><a class="primary-button" href="<?= e($config['slug']) ?>.php?tambah=1"><span>＋</span> Tambah <?= e(strtolower($config['singular'])) ?></a></section>
    <section class="master-stats" aria-label="Ringkasan <?= e($config['title']) ?>">
        <article class="master-stat"><span>Total data</span><strong><?= number_format((int) $stats['total'], 0, ',', '.') ?></strong><small>Seluruh data terdaftar</small></article>
        <article class="master-stat"><span>Data aktif</span><strong><?= number_format((int) $stats['active'], 0, ',', '.') ?></strong><small>Dapat digunakan</small></article>
        <article class="master-stat stat-warning"><span>Data nonaktif</span><strong><?= number_format((int) $stats['inactive'], 0, ',', '.') ?></strong><small>Diarsipkan, tidak dihapus</small></article>
    </section>

    <?php if ($formOpen): ?>
    <section class="drug-form-panel" id="form-data">
        <div class="drug-form-heading"><div><p class="section-kicker"><?= $formData['id'] !== '' ? 'PEMBARUAN DATA' : 'DATA BARU' ?></p><h2><?= $formData['id'] !== '' ? 'Edit ' . e(strtolower($config['singular'])) : 'Tambah ' . e(strtolower($config['singular'])) . ' baru' ?></h2></div><a class="quiet-button" href="<?= e($config['slug']) ?>.php">Tutup</a></div>
        <?php if ($errors): ?><div class="form-errors"><?php foreach ($errors as $message): ?><p><?= e($message) ?></p><?php endforeach; ?></div><?php endif; ?>
        <form method="post" action="<?= e($config['slug']) ?>.php<?= $formData['id'] !== '' ? '?edit=' . e($formData['id']) : '?tambah=1' ?>" class="drug-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= e($formData['id']) ?>">
            <?php foreach ($fields as $field): $name = $field['name']; $type = $field['type'] ?? 'text'; $wide = $type === 'textarea' || !empty($field['wide']); ?>
                <label class="field <?= $wide ? 'field-wide' : '' ?>"><span><?= e($field['label']) ?><?= !empty($field['required']) ? ' <b>*</b>' : '' ?></span>
                <?php if ($type === 'textarea'): ?>
                    <textarea name="<?= e($name) ?>" rows="3" maxlength="<?= (int) ($field['max'] ?? 255) ?>" placeholder="<?= e($field['placeholder'] ?? '') ?>"><?= e($formData[$name]) ?></textarea>
                <?php elseif ($type === 'select'): ?>
                    <select name="<?= e($name) ?>" <?= !empty($field['required']) ? 'required' : '' ?>><option value=""><?= e($field['empty_label'] ?? 'Pilih') ?></option><?php foreach (($optionsByField[$name] ?? []) as $option): ?><option value="<?= e((string) $option['id']) ?>" <?= (string) $option['id'] === $formData[$name] ? 'selected' : '' ?>><?= e($option[$field['option_label'] ?? 'name']) ?><?= isset($option['is_active']) && (int) $option['is_active'] === 0 ? ' (nonaktif)' : '' ?></option><?php endforeach; ?></select>
                <?php else: ?>
                    <input type="<?= e($type) ?>" name="<?= e($name) ?>" maxlength="<?= (int) ($field['max'] ?? 255) ?>" value="<?= e($formData[$name]) ?>" placeholder="<?= e($field['placeholder'] ?? '') ?>" <?= !empty($field['required']) ? 'required' : '' ?>>
                <?php endif; ?>
                <?php if (!empty($field['help'])): ?><small><?= e($field['help']) ?></small><?php endif; ?>
                </label>
            <?php endforeach; ?>
            <div class="form-actions field-wide"><a class="quiet-button" href="<?= e($config['slug']) ?>.php">Batal</a><button class="primary-button" type="submit">Simpan data</button></div>
        </form>
    </section>
    <?php endif; ?>

    <section class="drug-list-panel">
        <div class="list-heading"><div><p class="section-kicker">DAFTAR DATA</p><h2><?= e($config['plural']) ?></h2><p class="list-subtitle">Data master yang tersimpan di database SIMRS Farmasi.</p></div><span class="result-count"><?= number_format($totalRows, 0, ',', '.') ?> data ditemukan</span></div>
        <form method="get" action="<?= e($config['slug']) ?>.php" class="filter-bar">
            <label class="search-field"><span class="visually-hidden">Cari data</span><input type="search" name="q" value="<?= e($keyword) ?>" placeholder="<?= e($config['search_placeholder']) ?>"></label>
            <label class="filter-field"><span class="visually-hidden">Status</span><select name="status"><option value="aktif" <?= $statusFilter === 'aktif' ? 'selected' : '' ?>>Data aktif</option><option value="nonaktif" <?= $statusFilter === 'nonaktif' ? 'selected' : '' ?>>Data nonaktif</option><option value="semua" <?= $statusFilter === 'semua' ? 'selected' : '' ?>>Semua status</option></select></label>
            <button class="filter-button" type="submit">Terapkan</button><?php if ($keyword !== '' || $statusFilter !== 'aktif'): ?><a class="reset-filter" href="<?= e($config['slug']) ?>.php">Reset</a><?php endif; ?>
        </form>
        <div class="table-wrap"><table class="drug-table"><thead><tr><?php foreach ($config['columns'] as $column): ?><th><?= e($column['label']) ?></th><?php endforeach; ?><th>Status</th><th>Aksi</th></tr></thead><tbody>
        <?php if (!$rows): ?><tr><td colspan="<?= count($config['columns']) + 2 ?>" class="empty-state"><strong>Data tidak ditemukan</strong><span>Coba ubah kata pencarian atau filter yang digunakan.</span></td></tr>
        <?php else: foreach ($rows as $row): ?><tr>
            <?php foreach ($config['columns'] as $index => $column): $value = $row[$column['key']] ?? null; ?>
                <td><?php if ($index === 0): ?><div class="drug-name-cell"><strong><?= e(entity_value($row, $column['key'])) ?></strong><?php if (!empty($column['sub'])): ?><span><?= e(entity_value($row, $column['sub'])) ?></span><?php endif; ?></div><?php else: ?><span class="category-label"><?= e(entity_value($row, $column['key'])) ?></span><?php endif; ?></td>
            <?php endforeach; ?>
            <td><span class="status-pill <?= (int) $row['is_active'] === 1 ? 'status-active' : 'status-inactive' ?>"><?= (int) $row['is_active'] === 1 ? 'Aktif' : 'Nonaktif' ?></span></td>
            <td><div class="row-actions"><a class="action-link" href="<?= e($config['slug']) ?>.php?edit=<?= (int) $row['id'] ?>">Edit</a><form method="post" action="<?= e($config['slug']) ?>.php" onsubmit="return confirm('<?= (int) $row['is_active'] === 1 ? 'Nonaktifkan' : 'Aktifkan kembali' ?> data ini?')"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="action-link action-toggle" type="submit"><?= (int) $row['is_active'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?></button></form></div></td>
        </tr><?php endforeach; endif; ?>
        </tbody></table></div>
        <?php if ($totalPages > 1): ?><div class="pagination"><span>Halaman <?= $page ?> dari <?= $totalPages ?></span><div><?php if ($page > 1): ?><a href="<?= e(entity_query_url(['halaman' => $page - 1])) ?>">← Sebelumnya</a><?php endif; ?><?php if ($page < $totalPages): ?><a href="<?= e(entity_query_url(['halaman' => $page + 1])) ?>">Berikutnya →</a><?php endif; ?></div></div><?php endif; ?>
    </section>
    <div class="master-footnote"><strong>Catatan pengelolaan:</strong> data yang dinonaktifkan tetap tersimpan di database dan dapat diaktifkan kembali. Data yang sudah dipakai pada transaksi tidak dihapus permanen.</div>
    <footer class="dashboard-footer"><span>EightMedi · SIMRS Farmasi</span><span>Master data · <?= date('Y') ?></span></footer>
</main>
</body>
</html>
