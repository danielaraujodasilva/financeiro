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
  <?php $instances = $auth->instancesForUser($userId); $target = count($instances) === 1 ? base_path('financial.php?instance_id=' . (int) $instances[0]['id']) : base_path('dashboard.php'); ?>
  <p><a href="<?= e($target) ?>">Continuar</a></p>
<?php else: ?>
  <p><?= e($error ?? 'Falha ao processar convite.') ?></p>
  <p><a href="<?= e(base_path('dashboard.php')) ?>">Voltar</a></p>
<?php endif; ?>
</body>
</html>
