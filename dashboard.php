<?php
require __DIR__ . '/bootstrap.php';

$userId = $auth->requireLogin();
$user = $auth->currentUser();
$instances = $auth->instancesForUser($userId);
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dashboard - Financeiro</title>
<style>
body{margin:0;font-family:Arial,sans-serif;background:linear-gradient(135deg,#0f172a,#1e293b);color:#e2e8f0}
.wrap{max-width:1100px;margin:0 auto;padding:32px}
.card{background:rgba(15,23,42,.82);border:1px solid rgba(148,163,184,.2);border-radius:20px;padding:24px;margin:16px 0;box-shadow:0 12px 40px rgba(0,0,0,.25)}
a,button{background:#38bdf8;color:#082f49;border:none;border-radius:12px;padding:10px 14px;text-decoration:none;font-weight:700;display:inline-block}
.muted{color:#94a3b8}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px}
table{width:100%;border-collapse:collapse}
td,th{padding:10px;border-bottom:1px solid rgba(148,163,184,.15);text-align:left}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>Olá, <?= e($user['name']) ?></h1>
    <p class="muted"><?= e($user['email']) ?></p>
    <p>
      <a href="/instance-create.php">Nova instância</a>
      <a href="/logout.php" style="background:#334155;color:#e2e8f0">Sair</a>
    </p>
  </div>

  <div class="grid">
    <div class="card">
      <h2>Suas instâncias</h2>
      <table>
        <thead><tr><th>Nome</th><th>Função</th><th>Ação</th></tr></thead>
        <tbody>
        <?php foreach ($instances as $instance): ?>
          <tr>
            <td><?= e($instance['name']) ?></td>
            <td><?= e($instance['role']) ?></td>
            <td><a href="/instance.php?id=<?= (int) $instance['id'] ?>">Abrir</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="card">
      <h2>Convites pendentes</h2>
      <?php $invites = $auth->pendingInvitesForEmail($user['email']); ?>
      <?php if (!$invites): ?>
        <p class="muted">Nenhum convite pendente.</p>
      <?php else: ?>
        <ul>
          <?php foreach ($invites as $invite): ?>
            <li>
              <?= e($invite['instance_name']) ?>
              <a href="/accept-invite.php?token=<?= e($invite['token']) ?>">Aceitar</a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
