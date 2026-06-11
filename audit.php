<?php
require __DIR__ . '/bootstrap.php';

$instanceId = (int) ($_GET['instance_id'] ?? 0);
if (!$instanceId) exit('Instância obrigatória.');
$auth->requireInstanceAccess($instanceId);
$userId = $auth->userId();
if (!$auth->canManageInstance($userId, $instanceId)) { http_response_code(403); exit('Somente o dono da instância pode ver a auditoria.'); }

$actionFilter = trim((string) ($_GET['action'] ?? ''));
$entityFilter = trim((string) ($_GET['entity_type'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));

$sql = 'SELECT a.*, u.name AS user_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE a.instance_id = ?';
$params = [$instanceId];
if ($actionFilter !== '') { $sql .= ' AND a.action = ?'; $params[] = $actionFilter; }
if ($entityFilter !== '') { $sql .= ' AND a.entity_type = ?'; $params[] = $entityFilter; }
if ($dateFrom !== '') { $sql .= ' AND date(a.created_at) >= date(?)'; $params[] = $dateFrom; }
if ($dateTo !== '') { $sql .= ' AND date(a.created_at) <= date(?)'; $params[] = $dateTo; }
$sql .= ' ORDER BY a.id DESC LIMIT 100';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();
?>
<!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Auditoria</title><?= bootstrap_assets() ?><link rel="stylesheet" href="<?= e(base_path('assets/ui.css')) ?>"></head><body><div class="wrap"><?php financial_nav($instanceId, 'audit'); ?><div class="card hero"><div class="d-flex justify-content-between align-items-start flex-wrap gap-2"><div><div class="tag">Segurança</div><h1 class="headline">Auditoria</h1><p class="muted mb-0">Eventos sensíveis em uma leitura simples, com filtros quando necessário.</p></div></div></div><div class="card"><details open><summary class="tag" style="cursor:pointer">Filtros rápidos</summary><form method="get" class="split mt-3"><input type="hidden" name="instance_id" value="<?= $instanceId ?>"><label>Ação<input type="text" name="action" value="<?= e($actionFilter) ?>" placeholder="financial_transaction_create"></label><label>Entidade<input type="text" name="entity_type" value="<?= e($entityFilter) ?>" placeholder="credit_cards"></label><label>De<input type="date" name="date_from" value="<?= e($dateFrom) ?>"></label><label>Até<input type="date" name="date_to" value="<?= e($dateTo) ?>"></label><button class="btn btn-primary" type="submit">Filtrar</button></form></details></div><div class="card enter"><details open><summary class="tag" style="cursor:pointer">Últimos eventos</summary><div class="list mt-3"><?php foreach ($logs as $log): ?><div class="member"><div class="meta"><strong><?= e($log['action']) ?> · <?= e($log['entity_type']) ?></strong><span class="muted"><?= e((string) ($log['user_name'] ?? 'sistema')) ?> · <?= e($log['created_at']) ?> · <?= e((string) $log['ip_address']) ?></span><?php if (!empty($log['entity_id'])): ?><span class="muted">ID: <?= e((string) $log['entity_id']) ?></span><?php endif; ?></div><span class="tag"><?= e((string) $log['entity_type']) ?></span></div><?php endforeach; ?><?php if (!$logs): ?><p class="muted">Nenhum evento registrado ainda.</p><?php endif; ?></div></details></div></div></body></html>
