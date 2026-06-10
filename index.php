<?php
require __DIR__ . '/bootstrap.php';

if ($auth->userId()) {
    header('Location: ' . base_path('dashboard.php'));
    exit;
}

header('Location: ' . base_path('login.php'));
exit;
