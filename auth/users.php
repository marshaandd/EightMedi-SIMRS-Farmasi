<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_role('ADMIN');

$pdo = db();
$errors = [];
$flash = get_flash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        exit('Sesi formulir tidak valid. Muat ulang halaman lalu coba kembali.');
    }

    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'create') {
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $role = strtoupper((string) ($_POST['role'] ?? 'PETUGAS'));

            if ($fullName === '' || mb_strlen($fullName, 'UTF-8') > 120) {
                throw new RuntimeException('Nama lengkap wajib diisi dan maksimal 120 karakter.');
            }
            if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $username)) {
                throw new RuntimeException('Username harus 3–60 karakter dan hanya boleh berisi huruf, angka, titik, garis bawah, atau tanda hubung.');
            }
            if (!in_array($role, ['ADMIN', 'PETUGAS'], true)) {
                throw new RuntimeException('Peran pengguna tidak valid.');
            }
            $passwordError = password_policy_error($password);
            if ($passwordError !== null) {
                throw new RuntimeException($passwordError);
            }

            $statement = $pdo->prepare(
                'INSERT INTO users (full_name, username, password_hash, role, must_change_password, is_active)
                 VALUES (:full_name, :username, :password_hash, :role, 1, 1)'
            );
            $statement->execute([
                'full_name' => $fullName,
                'username' => $username,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => $role,
            ]);
            set_flash('success', 'Akun berhasil dibuat. Pengguna wajib mengganti password saat login pertama.');
            redirect('auth/users.php');
        }

        if ($action === 'update') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $username = trim((string) ($_POST['username'] ?? ''));
            $role = strtoupper((string) ($_POST['role'] ?? 'PETUGAS'));

            if (!$id || $id < 1) {
                throw new RuntimeException('Identitas pengguna tidak valid.');
            }
            if ($fullName === '' || mb_strlen($fullName, 'UTF-8') > 120) {
                throw new RuntimeException('Nama lengkap wajib diisi dan maksimal 120 karakter.');
            }
            if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $username)) {
                throw new RuntimeException('Format username tidak valid.');
            }
            if (!in_array($role, ['ADMIN', 'PETUGAS'], true)) {
                throw new RuntimeException('Peran pengguna tidak valid.');
            }

            $targetQuery = $pdo->prepare('SELECT id, role, is_active FROM users WHERE id = :id FOR UPDATE');
            $pdo->beginTransaction();
            $targetQuery->execute(['id' => $id]);
            $target = $targetQuery->fetch();
            if (!$target) {
                throw new RuntimeException('Akun yang akan diperbarui tidak ditemukan.');
            }

            $currentId = (int) (current_user()['id'] ?? 0);
            if ($id === $currentId && $role !== 'ADMIN') {
                throw new RuntimeException('Anda tidak dapat menurunkan peran akun sendiri.');
            }
            if ($target['role'] === 'ADMIN' && $role !== 'ADMIN' && (int) $target['is_active'] === 1) {
                $activeAdmins = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'ADMIN' AND is_active = 1")->fetchColumn();
                if ($activeAdmins <= 1) {
                    throw new RuntimeException('Minimal harus ada satu administrator aktif.');
                }
            }

            $update = $pdo->prepare('UPDATE users SET full_name = :full_name, username = :username, role = :role WHERE id = :id');
            $update->execute([
                'full_name' => $fullName,
                'username' => $username,
                'role' => $role,
                'id' => $id,
            ]);
            $pdo->commit();
            set_flash('success', 'Data akun berhasil diperbarui.');
            redirect('auth/users.php');
        }

        if ($action === 'toggle_status') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id || $id < 1) {
                throw new RuntimeException('Identitas pengguna tidak valid.');
            }
            if ($id === (int) (current_user()['id'] ?? 0)) {
                throw new RuntimeException('Anda tidak dapat menonaktifkan akun yang sedang digunakan.');
            }

            $pdo->beginTransaction();
            $targetQuery = $pdo->prepare('SELECT id, role, is_active FROM users WHERE id = :id FOR UPDATE');
            $targetQuery->execute(['id' => $id]);
            $target = $targetQuery->fetch();
            if (!$target) {
                throw new RuntimeException('Akun tidak ditemukan.');
            }
            if ($target['role'] === 'ADMIN' && (int) $target['is_active'] === 1) {
                $activeAdmins = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'ADMIN' AND is_active = 1")->fetchColumn();
                if ($activeAdmins <= 1) {
                    throw new RuntimeException('Administrator aktif terakhir tidak boleh dinonaktifkan.');
                }
            }
            $newStatus = (int) $target['is_active'] === 1 ? 0 : 1;
            $update = $pdo->prepare('UPDATE users SET is_active = :status WHERE id = :id');
            $update->execute(['status' => $newStatus, 'id' => $id]);
            $pdo->commit();
            set_flash('success', $newStatus === 1 ? 'Akun berhasil diaktifkan.' : 'Akun berhasil dinonaktifkan.');
            redirect('auth/users.php');
        }

        if ($action === 'reset_password') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            $password = (string) ($_POST['temporary_password'] ?? '');
            if (!$id || $id < 1) {
                throw new RuntimeException('Identitas pengguna tidak valid.');
            }
            $passwordError = password_policy_error($password);
            if ($passwordError !== null) {
                throw new RuntimeException($passwordError);
            }
            $update = $pdo->prepare(
                'UPDATE users SET password_hash = :hash, must_change_password = 1 WHERE id = :id'
            );
            $update->execute([
                'hash' => password_hash($password, PASSWORD_DEFAULT),
                'id' => $id,
            ]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Password tidak berhasil diperbarui.');
            }
            set_flash('success', 'Password sementara berhasil diatur. Pengguna wajib menggantinya saat login berikutnya.');
            redirect('auth/users.php');
        }

        throw new RuntimeException('Aksi tidak dikenali.');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($exception instanceof PDOException && $exception->getCode() === '23000') {
            $errors[] = 'Username sudah digunakan. Pilih username lain.';
        } elseif ($exception instanceof RuntimeException) {
            $errors[] = $exception->getMessage();
        } else {
            error_log($exception->getMessage());
            $errors[] = 'Perubahan akun gagal disimpan. Periksa log aplikasi.';
        }
    }
}

$users = $pdo->query(
    'SELECT id, full_name, username, role, is_active, must_change_password, last_login_at, created_at
     FROM users ORDER BY role ASC, full_name ASC'
)->fetchAll();

$user = current_user();
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f7dbe9">
    <title>Kelola pengguna · <?= e(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/app.css">
    <link rel="stylesheet" href="../assets/css/theme.css?v=1">
    <link rel="stylesheet" href="../assets/css/security.css">
</head>
<body class="app-body">
<header class="app-header">
    <a class="brand" href="../dashboard.php"><span class="brand-mark"><span></span><i></i></span><span class="brand-name">Eight<span>Medi</span></span></a>
    <nav class="main-nav" aria-label="Navigasi utama">
        <a href="../dashboard.php">Ringkasan</a>
        <a href="../master/index.php">Master data</a>
        <a href="../pelayanan/index.php">Pelayanan resep</a>
        <a href="../persediaan/index.php">Persediaan</a>
        <a href="../laporan/index.php">Laporan</a>
        <a class="active" href="users.php">Pengguna</a>
    </nav>
    <div class="header-account">
        <div class="account-copy"><strong><?= e($user['full_name'] ?? 'Pengguna') ?></strong><span><a href="change-password.php">Ubah password</a> · Administrator</span></div>
        <div class="avatar"><?= e(mb_strtoupper(mb_substr((string) ($user['full_name'] ?? 'U'), 0, 1))) ?></div>
        <form method="post" action="logout.php"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button class="logout-button" type="submit" title="Keluar" aria-label="Keluar">Keluar</button></form>
    </div>
</header>
<main class="dashboard-main security-main">
    <div class="security-breadcrumb"><a href="../dashboard.php">Ringkasan</a><span>/</span><strong>Pengguna</strong></div>
    <section class="security-title">
        <div><p class="section-kicker">PENGATURAN AKSES</p><h1>Kelola pengguna</h1><p>Kelola akun administrator dan petugas. Seluruh pengguna tetap menggunakan modul operasional yang sama.</p></div>
        <span class="security-chip">Akses administrator</span>
    </section>

    <?php if ($flash): ?><div class="security-alert success"><?= e($flash['message'] ?? '') ?></div><?php endif; ?>
    <?php if ($errors): ?><div class="security-alert error"><?php foreach ($errors as $error): ?><p><?= e($error) ?></p><?php endforeach; ?></div><?php endif; ?>

    <section class="security-card">
        <div class="security-card-heading"><div><p class="section-kicker">AKUN BARU</p><h2>Tambah pengguna</h2></div></div>
        <form method="post" class="security-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="create">
            <label><span>Nama lengkap</span><input type="text" name="full_name" maxlength="120" required></label>
            <label><span>Username</span><input type="text" name="username" maxlength="60" pattern="[A-Za-z0-9._-]{3,60}" required></label>
            <label><span>Peran</span><select name="role"><option value="PETUGAS">Petugas farmasi</option><option value="ADMIN">Administrator</option></select></label>
            <label><span>Password sementara</span><input type="password" name="password" minlength="12" autocomplete="new-password" required><small>Minimal 12 karakter, huruf besar/kecil, angka, dan simbol.</small></label>
            <div class="security-form-actions"><button class="primary-button" type="submit">Buat akun</button></div>
        </form>
    </section>

    <section class="security-card">
        <div class="security-card-heading"><div><p class="section-kicker">AKUN TERDAFTAR</p><h2>Daftar pengguna</h2></div><span class="security-count"><?= count($users) ?> akun</span></div>
        <div class="security-table-wrap">
            <table class="security-table">
                <thead><tr><th>Pengguna</th><th>Peran</th><th>Status</th><th>Login terakhir</th><th>Pengaturan</th></tr></thead>
                <tbody>
                <?php foreach ($users as $account): ?>
                    <tr>
                        <td><strong><?= e($account['full_name']) ?></strong><small>@<?= e($account['username']) ?><?= (int) $account['must_change_password'] === 1 ? ' · wajib ganti password' : '' ?></small></td>
                        <td><?= $account['role'] === 'ADMIN' ? 'Administrator' : 'Petugas farmasi' ?></td>
                        <td><span class="security-status <?= (int) $account['is_active'] === 1 ? 'active' : 'inactive' ?>"><?= (int) $account['is_active'] === 1 ? 'Aktif' : 'Nonaktif' ?></span></td>
                        <td><?= $account['last_login_at'] ? e(date('d/m/Y H:i', strtotime($account['last_login_at']))) : 'Belum pernah' ?></td>
                        <td>
                            <details class="security-actions">
                                <summary>Kelola</summary>
                                <div class="security-action-panel">
                                    <form method="post" class="security-inline-form">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= (int) $account['id'] ?>">
                                        <label>Nama<input name="full_name" maxlength="120" value="<?= e($account['full_name']) ?>" required></label>
                                        <label>Username<input name="username" maxlength="60" pattern="[A-Za-z0-9._-]{3,60}" value="<?= e($account['username']) ?>" required></label>
                                        <label>Peran<select name="role"><option value="PETUGAS" <?= $account['role'] === 'PETUGAS' ? 'selected' : '' ?>>Petugas</option><option value="ADMIN" <?= $account['role'] === 'ADMIN' ? 'selected' : '' ?>>Administrator</option></select></label>
                                        <button type="submit" class="quiet-button">Simpan profil</button>
                                    </form>
                                    <form method="post" class="security-inline-form">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="reset_password"><input type="hidden" name="id" value="<?= (int) $account['id'] ?>">
                                        <label>Password sementara<input type="password" name="temporary_password" minlength="12" autocomplete="new-password" required></label>
                                        <button type="submit" class="quiet-button">Reset password</button>
                                    </form>
                                    <?php if ((int) $account['id'] !== (int) ($user['id'] ?? 0)): ?>
                                    <form method="post" onsubmit="return confirm('<?= (int) $account['is_active'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?> akun ini?')">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="id" value="<?= (int) $account['id'] ?>">
                                        <button type="submit" class="quiet-button"><?= (int) $account['is_active'] === 1 ? 'Nonaktifkan akun' : 'Aktifkan akun' ?></button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$users): ?><tr><td colspan="5">Belum ada akun pengguna.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
    <footer class="dashboard-footer"><span>EightMedi · SIMRS Farmasi</span><span>Pengaturan pengguna</span></footer>
</main>
</body>
</html>
