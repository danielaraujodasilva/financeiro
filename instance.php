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
$currentUser = $auth->currentUser();
$pendingInvites = $auth->pendingInvitesForEmail($currentUser['email'] ?? '');
$canManage = $auth->canManageInstance($userId, $instanceId);
$message = $error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'invite' && $canManage) {
            $email = trim($_POST['email'] ?? '');
            if ($email === '') {
                throw new RuntimeException('Informe um email.');
            }
            $auth->createInviteForUser($instanceId, $email, $userId);
            $audit->log($instanceId, $userId, 'invite_create', 'invite', null, [], ['email' => $email]);
            $message = 'Convite criado com sucesso.';
        } elseif ($action === 'role' && $canManage) {
            $targetUserId = (int) ($_POST['target_user_id'] ?? 0);
            $role = (string) ($_POST['role'] ?? 'member');
            $auth->updateMemberRole($instanceId, $targetUserId, $role, $userId);
            $audit->log($instanceId, $userId, 'member_role_update', 'instance_member', (string) $targetUserId, [], ['role' => $role]);
            $message = 'Função atualizada.';
        } elseif ($action === 'remove' && $canManage) {
            $targetUserId = (int) ($_POST['target_user_id'] ?? 0);
            $auth->removeMember($instanceId, $targetUserId, $userId);
            $audit->log($instanceId, $userId, 'member_remove', 'instance_member', (string) $targetUserId);
            $message = 'Membro removido.';
        } elseif ($action === 'resend' && $canManage) {
            $inviteId = (int) ($_POST['invite_id'] ?? 0);
            $auth->resendInvite($inviteId, $userId);
            $audit->log($instanceId, $userId, 'invite_resend', 'invite', (string) $inviteId);
            $message = 'Convite reenviado.';
        } elseif ($action === 'delete_invite' && $canManage) {
            $inviteId = (int) ($_POST['invite_id'] ?? 0);
            $auth->deleteInviteById($inviteId, $userId);
            $audit->log($instanceId, $userId, 'invite_delete', 'invite', (string) $inviteId);
            $message = 'Convite removido.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($instance['name']) ?> - Financeiro</title>
<?= bootstrap_assets() ?>
<link rel="stylesheet" href="<?= e(base_path('assets/ui.css')) ?>">
</head>
<body>
<div class="container py-3 py-lg-4">
  <div class="card shadow-sm border-0 rounded-4 mb-3">
    <div class="card-body p-3 p-lg-4">
      <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start align-items-lg-center gap-3">
        <div class="d-flex align-items-center gap-3">
          <div class="mark"></div>
          <div>
            <span class="badge rounded-pill text-bg-primary-subtle text-primary-emphasis mb-2">Instância ativa</span>
            <h1 class="h2 fw-bold mb-1"><?= e($instance['name']) ?></h1>
            <div class="text-body-secondary">Acesso isolado por instância, com convites e papéis simples para a equipe.</div>
          </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
          <a class="btn btn-outline-primary" href="<?= e(base_path('dashboard.php')) ?>">Voltar</a>
          <a class="btn btn-primary" href="<?= e(base_path('instance-create.php')) ?>">Nova instância</a>
        </div>
      </div>
    </div>
  </div>

  <?php if ($message): ?><div class="alert alert-success rounded-4"><?= e($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger rounded-4"><?= e($error) ?></div><?php endif; ?>

  <div class="row g-3 mb-3">
    <div class="col-12">
      <div class="card shadow-sm border-0 rounded-4">
        <div class="card-body p-3 p-lg-4">
          <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 align-items-start align-items-lg-center">
            <div>
              <span class="badge rounded-pill text-bg-light border mb-2">Resumo</span>
              <h2 class="h3 fw-bold mb-2">Controle compartilhado com autonomia</h2>
              <p class="text-body-secondary mb-0">Cada pessoa entra no que precisa, com papéis simples e separação por instância.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
              <div class="card border-0 bg-body-tertiary rounded-4">
                <div class="card-body py-2 px-3">
                  <div class="text-body-secondary small">Membros</div>
                  <div class="fs-4 fw-bold"><?= count($members) ?></div>
                </div>
              </div>
              <div class="card border-0 bg-body-tertiary rounded-4">
                <div class="card-body py-2 px-3">
                  <div class="text-body-secondary small">Convites</div>
                  <div class="fs-4 fw-bold"><?= count($pendingInvites) ?></div>
                </div>
              </div>
              <div class="card border-0 bg-body-tertiary rounded-4">
                <div class="card-body py-2 px-3">
                  <div class="text-body-secondary small">Slug</div>
                  <div class="fw-semibold text-truncate" style="max-width:180px"><?= e($instance['slug']) ?></div>
                </div>
              </div>
            </div>
          </div>
          <div class="mt-3">
            <a class="btn btn-primary" href="<?= e(base_path('financial.php?instance_id=' . $instanceId)) ?>">Abrir área financeira</a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-12 col-lg-7">
      <div class="card shadow-sm border-0 rounded-4 h-100">
        <div class="card-body p-3 p-lg-4">
          <h2 class="h4 fw-bold mb-3">Membros</h2>
          <div class="list-group list-group-flush rounded-4 overflow-hidden">
            <?php foreach ($members as $member): ?>
              <div class="list-group-item d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
                <div class="d-flex align-items-center gap-3">
                  <div class="avatar"><?= strtoupper(substr($member['name'], 0, 1)) ?></div>
                  <div>
                    <div class="fw-semibold"><?= e($member['name']) ?></div>
                    <div class="text-body-secondary small"><?= e($member['email']) ?></div>
                  </div>
                </div>
                <div class="d-flex flex-wrap gap-2 align-items-center justify-content-end">
                  <span class="badge text-bg-light border rounded-pill text-uppercase"><?= e($member['role']) ?></span>
                  <?php if ($canManage && $member['role'] !== 'owner'): ?>
                    <form method="post" class="d-inline">
                      <input type="hidden" name="action" value="role">
                      <input type="hidden" name="target_user_id" value="<?= (int) $member['id'] ?>">
                      <input type="hidden" name="role" value="owner">
                      <button class="btn btn-outline-secondary btn-sm" type="submit">Tornar dono</button>
                    </form>
                    <form method="post" class="d-inline" onsubmit="return confirm('Remover este membro?');">
                      <input type="hidden" name="action" value="remove">
                      <input type="hidden" name="target_user_id" value="<?= (int) $member['id'] ?>">
                      <button class="btn btn-outline-danger btn-sm" type="submit">Remover</button>
                    </form>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="col-12 col-lg-5">
      <div class="accordion" id="instanceAccordion">
        <?php if ($canManage): ?>
          <div class="accordion-item border-0 shadow-sm rounded-4 overflow-hidden mb-3">
            <h2 class="accordion-header" id="headingInvite">
              <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapseInvite" aria-expanded="true" aria-controls="collapseInvite">
                Convidar membro
              </button>
            </h2>
            <div id="collapseInvite" class="accordion-collapse collapse show" aria-labelledby="headingInvite" data-bs-parent="#instanceAccordion">
              <div class="accordion-body">
                <p class="text-body-secondary">Convite por e-mail com token único.</p>
                <form method="post" class="vstack gap-3">
                  <input type="hidden" name="action" value="invite">
                  <div>
                    <label class="form-label">Email do convidado</label>
                    <input type="email" name="email" class="form-control" placeholder="email@dominio.com" required>
                  </div>
                  <button class="btn btn-primary" type="submit">Enviar convite</button>
                </form>
              </div>
            </div>
          </div>

          <div class="accordion-item border-0 shadow-sm rounded-4 overflow-hidden">
            <h2 class="accordion-header" id="headingInvites">
              <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseInvites" aria-expanded="false" aria-controls="collapseInvites">
                Convites pendentes
              </button>
            </h2>
            <div id="collapseInvites" class="accordion-collapse collapse" aria-labelledby="headingInvites" data-bs-parent="#instanceAccordion">
              <div class="accordion-body">
                <?php $stmt = $pdo->prepare('SELECT id, email, token, status, created_at FROM invites WHERE instance_id = ? AND status = "pending" ORDER BY created_at DESC'); $stmt->execute([$instanceId]); $instanceInvites = $stmt->fetchAll(); ?>
                <div class="list-group list-group-flush">
                  <?php foreach ($instanceInvites as $invite): ?>
                    <div class="list-group-item d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
                      <div>
                        <div class="fw-semibold"><?= e($invite['email']) ?></div>
                        <div class="text-body-secondary small">Criado em <?= e($invite['created_at']) ?></div>
                      </div>
                      <div class="d-flex gap-2">
                        <form method="post">
                          <input type="hidden" name="action" value="resend">
                          <input type="hidden" name="invite_id" value="<?= (int) $invite['id'] ?>">
                          <button class="btn btn-outline-primary btn-sm" type="submit">Reenviar</button>
                        </form>
                        <form method="post" onsubmit="return confirm('Excluir este convite?');">
                          <input type="hidden" name="action" value="delete_invite">
                          <input type="hidden" name="invite_id" value="<?= (int) $invite['id'] ?>">
                          <button class="btn btn-outline-danger btn-sm" type="submit">Excluir</button>
                        </form>
                      </div>
                    </div>
                  <?php endforeach; ?>
                  <?php if (!$instanceInvites): ?>
                    <div class="text-body-secondary">Nenhum convite pendente.</div>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
</body>
</html>
