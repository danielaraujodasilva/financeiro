<?php
declare(strict_types=1);

return [
    'app_name' => 'Financeiro',
    'db_driver' => getenv('FINANCEIRO_DB_DRIVER') ?: 'sqlite',
    'db_path' => getenv('FINANCEIRO_DB_PATH') ?: __DIR__ . '/../data/financeiro.sqlite',
    'db_host' => getenv('FINANCEIRO_DB_HOST') ?: '127.0.0.1',
    'db_port' => getenv('FINANCEIRO_DB_PORT') ?: '3306',
    'db_name' => getenv('FINANCEIRO_DB_NAME') ?: 'financeiro',
    'db_user' => getenv('FINANCEIRO_DB_USER') ?: 'root',
    'db_pass' => getenv('FINANCEIRO_DB_PASS') ?: '',
    'session_name' => 'financeiro_session',
];
