<?php
require __DIR__ . '/bootstrap.php';

session_destroy();
header('Location: ' . base_path('login.php'));
exit;
