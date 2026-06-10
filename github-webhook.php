<?php
declare(strict_types=1);

/**
 * GitHub webhook receiver for auto-syncing this local site.
 *
 * Configure these values before using:
 * - GITHUB_WEBHOOK_SECRET: same secret configured in the GitHub webhook
 * - GITHUB_REPO_PATH: local path where the git repo lives
 * - GITHUB_BRANCH: branch to pull, usually "main"
 */

$secret = getenv('GITHUB_WEBHOOK_SECRET') ?: 'Luna*123';
$repoPath = getenv('GITHUB_REPO_PATH') ?: '';
$branch = getenv('GITHUB_BRANCH') ?: 'main';
$logFile = __DIR__ . DIRECTORY_SEPARATOR . 'webhook-sync.log';

function detect_git_root(string $startDir): string
{
    $cmd = sprintf('cd /d "%s" && git rev-parse --show-toplevel 2>NUL', str_replace('"', '""', $startDir));
    $output = [];
    $exitCode = 0;
    exec($cmd, $output, $exitCode);
    if ($exitCode === 0 && !empty($output[0])) {
        return trim($output[0]);
    }

    return $startDir;
}

function write_log(string $message): void
{
    global $logFile;
    $line = sprintf("[%s] %s%s", date('Y-m-d H:i:s'), $message, PHP_EOL);
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

function fail(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, 'Use POST');
}

$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';
if ($event !== 'push') {
    fail(202, 'Ignored event: ' . ($event ?: 'unknown'));
}

$payload = file_get_contents('php://input') ?: '';
if ($payload === '') {
    fail(400, 'Empty payload');
}

if ($secret !== '') {
    $signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
    $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
    if (!hash_equals($expected, $signature)) {
        write_log('Invalid signature');
        fail(401, 'Invalid signature');
    }
}

if ($repoPath === '') {
    $repoPath = detect_git_root(__DIR__);
}

if (!is_dir($repoPath)) {
    fail(500, 'Repo path not found: ' . $repoPath);
}

$branchPattern = '/^[A-Za-z0-9._\/-]+$/';
if (!preg_match($branchPattern, $branch)) {
    fail(500, 'Invalid branch name');
}

$safeRepoPath = str_replace('"', '""', $repoPath);
$cmd = sprintf(
    'cd /d "%s" && git fetch origin %s 2>&1 && git reset --hard origin/%s 2>&1',
    $safeRepoPath,
    $branch,
    $branch
);

$output = [];
$exitCode = 0;
exec($cmd, $output, $exitCode);

write_log(sprintf(
    'Event=%s branch=%s exit=%d output=%s',
    $event,
    $branch,
    $exitCode,
    implode(' | ', $output)
));

if ($exitCode !== 0) {
    fail(500, 'Sync failed');
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => true,
    'message' => 'Repository synced',
    'branch' => $branch,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
