<?php
require __DIR__ . '/bootstrap.php';

$instanceId = (int) ($_GET['instance_id'] ?? 0);
if (!$instanceId) {
    exit('Instância obrigatória.');
}

$auth->requireInstanceAccess($instanceId);
$instance = $auth->instanceById($instanceId);
$today = date('Y-m-d');
$plus7 = date('Y-m-d', strtotime('+7 days'));
$plus30 = date('Y-m-d', strtotime('+30 days'));
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');
$transactions = $financial->transactions($instanceId, 500);
$accounts = $financial->accounts($instanceId);

$currentBalance = array_sum(array_map(fn($a) => (float) $a['current_balance'], $accounts));
$reserve = 0.0;
foreach ($accounts as $account) {
    if (($account['type'] ?? '') === 'investment') {
        $reserve += (float) $account['current_balance'];
    }
}

$insights = [];
$overdue = 0.0;
$due7 = 0.0;
$due30 = 0.0;
$incomePaid = 0.0;
$expensePaid = 0.0;

foreach ($transactions as $transaction) {
    $status = (string) ($transaction['status'] ?? '');
    $type = (string) ($transaction['type'] ?? '');
    $amount = (float) ($transaction['amount'] ?? 0);
    $date = (string) ($transaction['transaction_date'] ?? '');
    $dueDate = (string) ($transaction['due_date'] ?? '');

    if ($date >= $monthStart && $date <= $monthEnd) {
        if ($type === 'income' && $status === 'paid') {
            $incomePaid += $amount;
        }
        if ($type === 'expense' && $status === 'paid') {
            $expensePaid += $amount;
        }
    }

    if ($type === 'expense' && !in_array($status, ['paid', 'canceled'], true) && $dueDate !== '' && $dueDate < $today) {
        $overdue += $amount;
    }

    if ($dueDate !== '' && $dueDate >= $today && $dueDate <= $plus7 && !in_array($status, ['paid', 'canceled'], true)) {
        $due7 += $amount;
    }

    if ($dueDate !== '' && $dueDate >= $today && $dueDate <= $plus30 && !in_array($status, ['paid', 'canceled'], true)) {
        $due30 += $amount;
    }
}

$cardBills = $financial->bills($instanceId);
$openCardCommitment = array_sum(array_map(fn($bill) => in_array($bill['status'], ['open', 'overdue'], true) ? (float) $bill['total_amount'] : 0.0, $cardBills));
$projected = $currentBalance + $incomePaid - $expensePaid - $overdue - $openCardCommitment;
$risk = 'baixo';
if ($projected < 0 || $overdue > 0) {
    $risk = 'alto';
} elseif ($openCardCommitment > 0 || $due30 > 0) {
    $risk = 'médio';
}

$insights[] = [
    'title' => 'Você tem ' . format_money($overdue) . ' vencidos.',
    'action' => ['Ver vencidos', base_path('transactions.php?instance_id=' . $instanceId . '&filter=overdue')],
];
$insights[] = [
    'title' => 'Há ' . format_money($due7) . ' vencendo nos próximos 7 dias.',
    'action' => ['Abrir fluxo', base_path('cashflow.php?instance_id=' . $instanceId)],
];
$insights[] = [
    'title' => 'Seu cartão já comprometeu ' . format_money($openCardCommitment) . ' do mês.',
    'action' => ['Ver cartões', base_path('cards.php?instance_id=' . $instanceId)],
];
$insights[] = [
    'title' => 'Previsão até o fim do mês: ' . format_money($projected) . '.',
    'action' => ['Abrir fluxo', base_path('cashflow.php?instance_id=' . $instanceId)],
];
$insights[] = [
    'title' => 'Sua reserva cobre ' . ($expensePaid > 0 ? number_format(($currentBalance + $reserve) / max($expensePaid, 1), 1, ',', '.') : '0') . ' meses no ritmo atual.',
    'action' => ['Criar meta', base_path('financial.php?instance_id=' . $instanceId)],
];

?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Insights - <?= e($instance['name']) ?></title>
<?= bootstrap_assets() ?>
<link rel="stylesheet" href="<?= e(base_path('assets/ui.css')) ?>">
</head>
<body>
<div class="wrap">
  <?php financial_nav($instanceId, 'insights'); ?>
  <div class="card hero">
    <div class="tag">Insights</div>
    <h1 class="headline">Alertas e ações rápidas</h1>
    <p class="muted">Os principais sinais já aparecem aqui para você agir sem caçar informação em várias páginas.</p>
  </div>

  <div class="grid">
    <?php foreach ($insights as $insight): ?>
      <div class="card enter">
        <h2 class="mb-2"><?= e($insight['title']) ?></h2>
        <a class="btn btn-primary" href="<?= e($insight['action'][1]) ?>"><?= e($insight['action'][0]) ?></a>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="card enter">
    <h2 class="mb-1">Resumo técnico</h2>
    <div class="statbar">
      <div class="stat"><span class="muted">Saldo atual</span><strong><?= format_money($currentBalance) ?></strong></div>
      <div class="stat"><span class="muted">Reserva</span><strong><?= format_money($reserve) ?></strong></div>
      <div class="stat"><span class="muted">Risco</span><strong><?= risk_label($risk) ?></strong></div>
    </div>
  </div>
</div>
</body>
</html>
