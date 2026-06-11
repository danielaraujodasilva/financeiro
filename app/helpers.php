<?php
declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function base_path(string $path = ''): string
{
    static $basePath = null;

    if ($basePath === null) {
        $config = require __DIR__ . '/config.php';
        $basePath = rtrim((string) ($config['base_path'] ?? ''), '/');
        if ($basePath === '') {
            $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
            $basePath = rtrim($scriptDir, '/');
        }
        if ($basePath === '') {
            $basePath = '';
        }
    }

    return $basePath . '/' . ltrim($path, '/');
}

function bootstrap_assets(): string
{
    return <<<HTML
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
HTML;
}
