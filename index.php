<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

if (current_user()) {
    redirect('dashboard.php');
}

redirect('auth/login.php');
