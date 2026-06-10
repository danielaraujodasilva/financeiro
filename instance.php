<?php
require __DIR__ . '/bootstrap.php';

$instanceId = (int) ($_GET['id'] ?? 0);
if (!$instanceId) {
    http_response_code(404);
    exit('Instância inválida.');
}

$userId = $auth->requireInstanceAccess($instanceId);
$instance = $auth->instanceById($instanceId);
if (!$instance) {
    http_response_code(404);
    exit('Instância não encontrada.');
}

$members = $auth->instanceMembers($instanceId);
$canManage = $auth->canManageInstance($userId, $instanceId);
$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    $email = trim($_POST['email'] ?? '');
    if ($email === '') {
        $error = 'Informe um email.';
    } else {
        try {
            $auth->inviteMember($instanceId, $email);
            $message = 'Convite criado com sucesso.';
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($instance['name']) ?> - Financeiro</title>
<style>
body{margin:0;font-family:Arial,sans-serif;background:#0b1120;color:#e5e7eb}
.wrap{max-width:1000px;margin:0 auto;padding:32px}
.card{background:#111827;border:1px solid #1f2937;border-radius:18px;padding:22px;margin:16px 0}
input,button{padding:10px 12px;border-radius:10px;border:1px solid #334155;background:#0f172a;color:#e2e8f0}
button{background:#38bdf8;color:#082f49;font-weight:700}
a{color:#7dd3fc}
.ok{color:#86efac}.err{color:#fca5a5}
</style>
</head>
<body>
<div class="wrap">
  <p><a href="/dashboard.php">Voltar</a></p>
  <div class="card">
    <h1><?= e($instance['name']) ?></h1>
    <p>Slug: <?= e($instance['slug']) ?></p>
    <p>Você está dentro desta instância. A partir daqui, todo dado do sistema financeiro vai ser filtrado por ela.</p>
  </div>

  <div class="card">
    <h2>Membros</h2>
    <ul>
      <?php foreach ($members as $member): ?>
        <li><?= e($member['name']) ?> (<?= e($member['email']) ?>) - <?= e($member['role']) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>

  <?php if ($canManage): ?>
  <div class="card">
    <h2>Convidar membro</h2>
    <?php if ($message): ?><p class="ok"><?= e($message) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="err"><?= e($error) ?></p><?php endif; ?>
    <form method="post">
      <input type="email" name="email" placeholder="email@dominio.com" required>
      <button type="submit">Enviar convite</button>
    </form>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
