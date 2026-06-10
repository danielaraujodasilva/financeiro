<?php
require __DIR__ . '/bootstrap.php';

if ($auth->userId()) {
    header('Location: /dashboard.php');
    exit;
}

header('Location: /login.php');
exit;
