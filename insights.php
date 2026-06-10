<?php
require __DIR__ . '/bootstrap.php';
$instanceId = (int)($_GET['instance_id'] ?? 0);
if (!$instanceId) exit('Instância obrigatória.');
$auth->requireInstanceAccess($instanceId);
$instance = $auth->instanceById($instanceId);
$today = date('Y-m-d');
$plus7 = date('Y-m-d', strtotime('+7 days'));
$plus30 = date('Y-m-d', strtotime('+30 days'));
$tx = $financial->transactions($instanceId, 500);
$insights = [];
$overdue = 0; $next7 = 0; $expensePaid=0; $incomePaid=0; $monthCount=0;
foreach ($tx as $t) {
    if ($t['type'] === 'expense' && !in_array($t['status'], ['paid','canceled'], true) && !empty($t['due_date']) && $t['due_date'] < $today) $overdue += (float)$t['amount'];
    if (!empty($t['due_date']) && $t['due_date'] >= $today && $t['due_date'] <= $plus7 && !in_array($t['status'], ['paid','canceled'], true)) $next7 += (float)$t['amount'];
    if ($t['transaction_date'] >= date('Y-m-01') && $t['transaction_date'] <= date('Y-m-t')) {
        $monthCount++;
        if ($t['type'] === 'expense' && $t['status'] === 'paid') $expensePaid += (float)$t['amount'];
        if ($t['type'] === 'income' && $t['status'] === 'paid') $incomePaid += (float)$t['amount'];
    }
}
$accounts = $financial->accounts($instanceId);
$currentBalance = array_sum(array_map(fn($a)=>(float)$a['current_balance'],$accounts));
$fixedExpenses = 0;
foreach ($tx as $t) {
    if ($t['type']==='expense' && $t['status']!=='canceled' && in_array($t['center_id'] ?? null, [], true)) {}
}
$reserve = 0;
foreach ($accounts as $a) if ($a['type']==='investment') $reserve += (float)$a['current_balance'];
$survivalMonths = $expensePaid > 0 ? max(0, floor(($currentBalance + $reserve) / max($expensePaid, 1))) : null;
if ($overdue > 0) $insights[] = "Você tem R$ " . number_format($overdue,2,',','.') . " vencidos.";
if ($next7 > 0) $insights[] = "Há R$ " . number_format($next7,2,',','.') . " vencendo nos próximos 7 dias.";
if ($incomePaid > 0 && $expensePaid > 0 && $expensePaid > $incomePaid) $insights[] = "Seu mês está mais caro que a receita recebida.";
if ($survivalMonths !== null) $insights[] = "Sua reserva + saldo cobrem cerca de " . $survivalMonths . " mês(es) no ritmo atual de despesas pagas.";
if (!$insights) $insights[] = "Sem alertas críticos agora.";
?><!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Insights</title><link rel="stylesheet" href="<?= e(base_path('assets/ui.css')) ?>"></head><body><div class="wrap"><?php financial_nav($instanceId,'insights'); ?><div class="card hero"><h1 class="headline">Insights automáticos</h1><p class="muted">Análises simples com base nos lançamentos e contas da instância.</p><div class="list"><?php foreach($insights as $i): ?><div class="member"><div class="meta"><strong><?= e($i) ?></strong><span class="muted">Análise automática</span></div></div><?php endforeach; ?></div></div></div></body></html>
