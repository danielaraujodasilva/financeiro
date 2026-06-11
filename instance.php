<?php
require __DIR__ . '/bootstrap.php';

$instanceId = (int) ($_GET['id'] ?? 0);
if (!$instanceId) { http_response_code(404); exit('Instância inválida.'); }
$userId = $auth->requireInstanceAccess($instanceId);
$instance = $auth->instanceById($instanceId);
if (!$instance) { http_response_code(404); exit('Instância não encontrada.'); }

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
            if ($email === '') throw new RuntimeException('Informe um email.');
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
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
?>
<!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($instance['name']) ?> - Financeiro</title><?= bootstrap_assets() ?><link rel="stylesheet" href="<?= e(base_path('assets/ui.css')) ?>"></head><body><div class="wrap"><div class="topbar fade-in"><div class="brand"><div class="mark"></div><div><div class="tag">Instância ativa</div><h1 class="headline"><?= e($instance['name']) ?></h1></div></div><div class="actions"><a class="btn btn-secondary" href="<?= e(base_path('dashboard.php')) ?>">Voltar</a><a class="btn btn-primary" href="<?= e(base_path('instance-create.php')) ?>">Nova instância</a></div></div><div class="card hero enter"><div class="d-flex justify-content-between align-items-start flex-wrap gap-3"><div><div class="tag">Resumo</div><h2>Controle compartilhado com autonomia</h2><p class="muted mb-0">Acesso isolado por instância, com convites e papéis simples para a equipe.</p></div><div class="statbar"><div class="stat"><span class="muted">Membros</span><strong><?= count($members) ?></strong></div><div class="stat"><span class="muted">Convites</span><strong><?= count($pendingInvites) ?></strong></div><div class="stat"><span class="muted">Slug</span><strong><?= e($instance['slug']) ?></strong></div></div></div><div class="actions mt-3"><a class="btn btn-primary" href="<?= e(base_path('financial.php?instance_id=' . $instanceId)) ?>">Abrir área financeira</a></div></div><?php if ($message): ?><div class="toast good"><?= e($message) ?></div><?php endif; ?><?php if ($error): ?><div class="toast bad"><?= e($error) ?></div><?php endif; ?><div class="grid"><div class="card enter"><h2>Membros</h2><div class="list stagger"><?php foreach ($members as $member): ?><div class="member"><div class="brand" style="gap:10px"><div class="avatar"><?= strtoupper(substr($member['name'], 0, 1)) ?></div><div class="meta"><strong><?= e($member['name']) ?></strong><span class="muted"><?= e($member['email']) ?></span></div></div><div class="actions" style="justify-content:flex-end"><span class="tag"><?= e($member['role']) ?></span><?php if ($canManage): ?><?php if ($member['role'] !== 'owner'): ?><form method="post"><input type="hidden" name="action" value="role"><input type="hidden" name="target_user_id" value="<?= (int) $member['id'] ?>"><input type="hidden" name="role" value="owner"><button class="btn btn-secondary" type="submit">Tornar dono</button></form><?php endif; ?><?php if ($member['role'] !== 'owner'): ?><form method="post"><input type="hidden" name="action" value="remove"><input type="hidden" name="target_user_id" value="<?= (int) $member['id'] ?>"><button class="btn btn-danger" type="submit">Remover</button></form><?php endif; ?><?php endif; ?></div></div><?php endforeach; ?></div></div><div class="shell"><?php if ($canManage): ?><div class="card enter"><details open><summary class="tag" style="cursor:pointer">Convidar membro</summary><div style="margin-top:14px"><p class="note">Convite por e-mail com token único.</p><form method="post"><input type="hidden" name="action" value="invite"><label>Email do convidado<input type="email" name="email" placeholder="email@dominio.com" required></label><button class="btn btn-primary" type="submit">Enviar convite</button></form></div></details></div><div class="card enter"><details><summary class="tag" style="cursor:pointer">Convites pendentes</summary><div style="margin-top:14px"><?php $stmt = $pdo->prepare('SELECT id, email, token, status, created_at FROM invites WHERE instance_id = ? AND status = "pending" ORDER BY created_at DESC');$stmt->execute([$instanceId]);$instanceInvites = $stmt->fetchAll(); ?><div class="list"><?php foreach ($instanceInvites as $invite): ?><div class="member"><div class="meta"><strong><?= e($invite['email']) ?></strong><span class="muted">Criado em <?= e($invite['created_at']) ?></span></div><div class="actions"><form method="post"><input type="hidden" name="action" value="resend"><input type="hidden" name="invite_id" value="<?= (int) $invite['id'] ?>"><button class="btn btn-good" type="submit">Reenviar</button></form><form method="post"><input type="hidden" name="action" value="delete_invite"><input type="hidden" name="invite_id" value="<?= (int) $invite['id'] ?>"><button class="btn btn-danger" type="submit">Excluir</button></form></div></div><?php endforeach; ?><?php if (!$instanceInvites): ?><p class="muted">Nenhum convite pendente.</p><?php endif; ?></div></div></details></div><?php endif; ?></div></div></body></html>
