<?php
require __DIR__ . '/bootstrap.php';

if ($auth->userId()) {
    $instances = $auth->instancesForUser($auth->userId());
    if (count($instances) === 1) {
        header('Location: ' . base_path('financial.php?instance_id=' . (int) $instances[0]['id']));
    } else {
        header('Location: ' . base_path('dashboard.php'));
    }
    exit;
}

header('Location: ' . base_path('login.php'));
exit;
