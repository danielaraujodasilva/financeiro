<?php
require __DIR__ . '/bootstrap.php';

$instanceId = (int) ($_GET['instance_id'] ?? 0);
if (!$instanceId) exit('Instância obrigatória.');
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
$reserve = array_sum(array_map(fn($a) => (($a['type'] ?? '') === 'investment') ? (float) $a['current_balance'] : 0.0, $accounts));
$overdue = $due7 = $due30 = $incomePaid = $expensePaid = 0.0;
foreach ($transactions as $transaction) {
    $status = (string) ($transaction['status'] ?? '');
    $type = (string) ($transaction['type'] ?? '');
    $amount = (float) ($transaction['amount'] ?? 0);
    $date = (string) ($transaction['transaction_date'] ?? '');
    $dueDate = (string) ($transaction['due_date'] ?? '');
    if ($date >= $monthStart && $date <= $monthEnd) {
        if ($type === 'income' && $status === 'paid') $incomePaid += $amount;
        if ($type === 'expense' && $status === 'paid') $expensePaid += $amount;
    }
    if ($type === 'expense' && !in_array($status, ['paid', 'canceled'], true) && $dueDate !== '' && $dueDate < $today) $overdue += $amount;
    if ($dueDate !== '' && $dueDate >= $today && $dueDate <= $plus7 && !in_array($status, ['paid', 'canceled'], true)) $due7 += $amount;
    if ($dueDate !== '' && $dueDate >= $today && $dueDate <= $plus30 && !in_array($status, ['paid', 'canceled'], true)) $due30 += $amount;
}
$cardBills = $financial->bills($instanceId);
$openCardCommitment = array_sum(array_map(fn($bill) => in_array($bill['status'], ['open', 'overdue'], true) ? (float) $bill['total_amount'] : 0.0, $cardBills));
$projected = $currentBalance + $incomePaid - $expensePaid - $overdue - $openCardCommitment;
$risk = 'baixo';
if ($projected < 0 || $overdue > 0) $risk = 'alto'; elseif ($openCardCommitment > 0 || $due30 > 0) $risk = 'médio';
$insights = [
    ['title' => 'Você tem ' . format_money($overdue) . ' vencidos.', 'label' => 'Ver vencidos', 'href' => base_path('transactions.php?instance_id=' . $instanceId . '&filter=overdue')],
    ['title' => 'Há ' . format_money($due7) . ' vencendo nos próximos 7 dias.', 'label' => 'Abrir fluxo', 'href' => base_path('cashflow.php?instance_id=' . $instanceId)],
    ['title' => 'Seu cartão já comprometeu ' . format_money($openCardCommitment) . ' do mês.', 'label' => 'Ver cartões', 'href' => base_path('cards.php?instance_id=' . $instanceId)],
    ['title' => 'Previsão até o fim do mês: ' . format_money($projected) . '.', 'label' => 'Abrir fluxo', 'href' => base_path('cashflow.php?instance_id=' . $instanceId)],
    ['title' => 'Sua reserva cobre ' . ($expensePaid > 0 ? number_format(($currentBalance + $reserve) / max($expensePaid, 1), 1, ',', '.') : '0') . ' meses no ritmo atual.', 'label' => 'Criar meta', 'href' => base_path('financial.php?instance_id=' . $instanceId)],
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
<body class="bg-body-tertiary">
<div class="container py-3 py-lg-4">
  <?php financial_nav($instanceId, 'insights'); ?>
  <div class="card shadow-sm border-0 rounded-4 mb-3">
    <div class="card-body p-3 p-lg-4">
      <span class="badge rounded-pill text-bg-primary-subtle text-primary-emphasis mb-2">Insights</span>
      <h1 class="h2 fw-bold mb-2">Alertas e ações rápidas</h1>
      <p class="text-body-secondary mb-0">Os principais sinais já aparecem aqui para você agir sem caçar informação em várias páginas.</p>
    </div>
  </div>
  <div class="row g-3">
    <?php foreach ($insights as $insight): ?>
      <div class="col-12 col-lg-6">
        <div class="card shadow-sm border-0 rounded-4 h-100">
          <div class="card-body p-3 p-lg-4">
            <h2 class="h5 fw-bold mb-3"><?= e($insight['title']) ?></h2>
            <a class="btn btn-primary" href="<?= e($insight['href']) ?>"><?= e($insight['label']) ?></a>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="card shadow-sm border-0 rounded-4 mt-3">
    <div class="card-body p-3 p-lg-4">
      <h2 class="h4 fw-bold mb-3">Resumo técnico</h2>
      <div class="row g-2">
        <div class="col-12 col-md-4"><div class="card border-0 bg-body-tertiary rounded-4"><div class="card-body"><div class="small text-body-secondary">Saldo atual</div><div class="fw-bold"><?= format_money($currentBalance) ?></div></div></div></div>
        <div class="col-12 col-md-4"><div class="card border-0 bg-body-tertiary rounded-4"><div class="card-body"><div class="small text-body-secondary">Reserva</div><div class="fw-bold"><?= format_money($reserve) ?></div></div></div></div>
        <div class="col-12 col-md-4"><div class="card border-0 bg-body-tertiary rounded-4"><div class="card-body"><div class="small text-body-secondary">Risco</div><div class="fw-bold"><?= e(ucfirst($risk)) ?></div></div></div></div>
      </div>
    </div>
  </div>
</div>
</body>
</html>
