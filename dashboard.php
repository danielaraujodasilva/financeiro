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
<link rel="stylesheet" href="<?= e(base_path('assets/ui.css')) ?>">
</head>
<body>
<div class="wrap">
  <div class="topbar fade-in">
    <div class="brand">
      <div class="mark"></div>
      <div>
        <div class="tag">Financeiro · Multi-instância</div>
        <h1 class="headline">Bem-vindo, <?= e($user['name']) ?></h1>
      </div>
    </div>
    <div class="actions">
      <a class="btn btn-secondary" href="<?= e(base_path('logout.php')) ?>">Sair</a>
      <a class="btn btn-primary" href="<?= e(base_path('instance-create.php')) ?>">Nova instância</a>
    </div>
  </div>

  <div class="card hero enter">
    <h2>Seu centro de comando financeiro</h2>
    <p class="muted">Cada instância fica isolada, com acesso controlado por membros e convites. Essa base já está pronta para o sistema crescer sem confundir dados entre contas.</p>
    <div class="statbar">
      <div class="stat"><span class="muted">Instâncias</span><strong><?= count($instances) ?></strong></div>
      <div class="stat"><span class="muted">Convites pendentes</span><strong><?= count($auth->pendingInvitesForEmail($user['email'])) ?></strong></div>
      <div class="stat"><span class="muted">Conta</span><strong><?= e($user['email']) ?></strong></div>
    </div>
  </div>

  <div class="grid">
    <div class="card enter">
      <h2>Suas instâncias</h2>
      <div class="list stagger">
        <?php foreach ($instances as $instance): ?>
          <div class="member">
            <div class="meta">
              <strong><?= e($instance['name']) ?></strong>
              <span class="muted">Função: <?= e($instance['role']) ?> · Slug: <?= e($instance['slug']) ?></span>
            </div>
            <a class="btn btn-primary" href="<?= e(base_path('instance.php?id=' . (int) $instance['id'])) ?>">Abrir</a>
          </div>
        <?php endforeach; ?>
        <?php if (!$instances): ?>
          <p class="muted">Você ainda não tem instâncias. Crie a primeira para começar.</p>
        <?php endif; ?>
      </div>
    </div>

    <div class="card enter">
      <h2>Convites pendentes</h2>
      <?php $invites = $auth->pendingInvitesForEmail($user['email']); ?>
      <?php if (!$invites): ?>
        <p class="muted">Nenhum convite pendente.</p>
      <?php else: ?>
        <div class="list">
          <?php foreach ($invites as $invite): ?>
            <div class="member">
              <div class="meta">
                <strong><?= e($invite['instance_name']) ?></strong>
                <span class="muted">Convite em aberto</span>
              </div>
              <a class="btn btn-good" href="<?= e(base_path('accept-invite.php?token=' . $invite['token'])) ?>">Aceitar</a>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
