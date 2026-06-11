<?php
require __DIR__ . '/bootstrap.php';

$instanceId = (int) ($_GET['instance_id'] ?? 0);
if (!$instanceId) exit('Instância obrigatória.');
$auth->requireInstanceAccess($instanceId);
$transactions = $financial->transactions($instanceId, 200);
$filter = (string) ($_GET['filter'] ?? 'all');
$filtered = array_values(array_filter($transactions, function (array $t) use ($filter) {
    return match ($filter) {
        'income' => $t['type'] === 'income',
        'expense' => $t['type'] === 'expense',
        'card' => $t['payment_method'] === 'credit_card',
        'overdue' => $t['status'] === 'overdue',
        'month' => substr((string) $t['transaction_date'], 0, 7) === date('Y-m'),
        default => true,
    };
}));
?>
<!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Lançamentos</title><?= bootstrap_assets() ?><link rel="stylesheet" href="<?= e(base_path('assets/ui.css')) ?>"></head><body><div class="wrap"><?php financial_nav($instanceId,'transactions'); ?><div class="card hero"><div class="d-flex justify-content-between align-items-start flex-wrap gap-3"><div><div class="tag">Extrato simples</div><h1 class="headline">Lançamentos</h1><p class="muted mb-0">Veja o que entrou, saiu e o que está vencendo sem abrir formulário gigante.</p></div><div class="actions"><a class="btn btn-primary" href="<?= e(base_path('dashboard.php?add=1')) ?>">+ Adicionar</a><a class="btn btn-secondary" href="<?= e(base_path('dashboard.php')) ?>">Início</a></div></div><div class="d-flex flex-wrap gap-2 mt-3"><?php foreach (['all' => 'Todos', 'income' => 'Entradas', 'expense' => 'Gastos', 'card' => 'Cartão', 'overdue' => 'Vencidos', 'month' => 'Este mês'] as $k => $label): ?><a class="btn <?= $filter === $k ? 'btn-primary' : 'btn-secondary' ?>" href="<?= e(base_path('transactions.php?instance_id=' . $instanceId . '&filter=' . $k)) ?>"><?= e($label) ?></a><?php endforeach; ?></div></div><div class="card"><div class="list"><?php foreach($filtered as $t): ?><div class="member"><div class="meta"><strong><?= e($t['description']) ?></strong><span class="muted"><?= e($t['transaction_date']) ?> · <?= financial_type_label((string)$t['type']) ?> · <?= financial_status_label((string)$t['status']) ?></span></div><span class="tag"><?= format_money((float)$t['amount']) ?></span></div><?php endforeach; ?><?php if(!$filtered): ?><p class="muted">Nenhum lançamento encontrado.</p><?php endif; ?></div></div></div></body></html>
