<?php
declare(strict_types=1);

session_name((require __DIR__ . '/app/config.php')['session_name']);
session_start();

$config = require __DIR__ . '/app/config.php';

require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/Auth.php';
require_once __DIR__ . '/app/Schema.php';
require_once __DIR__ . '/app/helpers.php';

$pdo = Database::connection($config);
Schema::migrate($pdo);
$auth = new Auth($pdo);
