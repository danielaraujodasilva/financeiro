<?php
require __DIR__ . '/bootstrap.php';

$userId = $auth->requireLogin();
$token = trim($_GET['token'] ?? '');
if ($token === '') {
    http_response_code(400);
    exit('Token inválido.');
}

$message = null;
$error = null;

try {
    $auth->acceptInvite($token, $userId);
    $message = 'Convite aceito com sucesso.';
} catch (Throwable $e) {
    $error = $e->getMessage();
}
?>
<!doctype html>
<html lang="pt-br">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Aceitar convite</title></head>
<body>
<?php if ($message): ?>
  <p><?= e($message) ?></p>
  <p><a href="/dashboard.php">Ir para o dashboard</a></p>
<?php else: ?>
  <p><?= e($error ?? 'Falha ao processar convite.') ?></p>
  <p><a href="/dashboard.php">Voltar</a></p>
<?php endif; ?>
</body>
</html>
