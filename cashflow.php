<?php
require __DIR__ . '/bootstrap.php';

$instanceId = (int) ($_GET['instance_id'] ?? 0);
if (!$instanceId) exit('Instância obrigatória.');
$auth->requireInstanceAccess($instanceId);
$instance = $auth->instanceById($instanceId);
$transactions = $financial->transactions($instanceId, 500);
$today = new DateTimeImmutable(date('Y-m-d'));

function cashflow_window(array $transactions, DateTimeImmutable $start, DateTimeImmutable $end): array
{
    $income = 0.0;
    $expense = 0.0;
    $days = [];
    foreach ($transactions as $transaction) {
        $dateValue = (string) ($transaction['due_date'] ?: $transaction['transaction_date']);
        if ($dateValue === '') continue;
        $date = new DateTimeImmutable(substr($dateValue, 0, 10));
        if ($date < $start || $date > $end) continue;
        $amount = (float) ($transaction['amount'] ?? 0);
        if (($transaction['type'] ?? '') === 'income') $income += $amount; else $expense += $amount;
        $days[$date->format('Y-m-d')][] = $transaction;
    }
    ksort($days);
    return ['income' => $income, 'expense' => $expense, 'balance' => $income - $expense, 'days' => $days];
}

$periods = [
    '7 dias' => [$today, $today->modify('+7 days')],
    '30 dias' => [$today, $today->modify('+30 days')],
    '60 dias' => [$today, $today->modify('+60 days')],
    '90 dias' => [$today, $today->modify('+90 days')],
];

$currentBalance = array_sum(array_map(fn($a) => (float) $a['current_balance'], $financial->accounts($instanceId)));
$cards = [];
foreach ($periods as $label => [$start, $end]) $cards[$label] = cashflow_window($transactions, $start, $end);
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Fluxo de Caixa - <?= e($instance['name']) ?></title>
<?= bootstrap_assets() ?>
<link rel="stylesheet" href="<?= e(base_path('assets/ui.css')) ?>">
</head>
<body class="bg-body-tertiary">
<div class="container py-3 py-lg-4">
  <?php financial_nav($instanceId, 'cashflow'); ?>
  <div class="card shadow-sm border-0 rounded-4 mb-3">
    <div class="card-body p-3 p-lg-4">
      <span class="badge rounded-pill text-bg-primary-subtle text-primary-emphasis mb-2">Fluxo de caixa</span>
      <h1 class="h2 fw-bold mb-2">Próximos vencimentos e projeções</h1>
      <p class="text-body-secondary mb-3">Olhe rápido para os próximos 7, 30, 60 e 90 dias para entender entradas, saídas e saldo projetado.</p>
      <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-primary" href="<?= e(base_path('dashboard.php?add=1')) ?>">+ Adicionar</a>
        <a class="btn btn-outline-secondary" href="<?= e(base_path('transactions.php?instance_id=' . $instanceId)) ?>">Lançamentos</a>
      </div>
    </div>
  </div>
  <div class="row g-3">
    <?php foreach ($cards as $label => $metrics): ?>
      <div class="col-12 col-lg-6">
        <div class="card shadow-sm border-0 rounded-4 h-100">
          <div class="card-body p-3 p-lg-4">
            <h2 class="h4 fw-bold mb-3"><?= e($label) ?></h2>
            <div class="row g-2">
              <div class="col-12 col-md-4"><div class="card border-0 bg-body-tertiary rounded-4"><div class="card-body"><div class="small text-body-secondary">Entradas previstas</div><div class="fw-bold"><?= format_money($metrics['income']) ?></div></div></div></div>
              <div class="col-12 col-md-4"><div class="card border-0 bg-body-tertiary rounded-4"><div class="card-body"><div class="small text-body-secondary">Saídas previstas</div><div class="fw-bold"><?= format_money($metrics['expense']) ?></div></div></div></div>
              <div class="col-12 col-md-4"><div class="card border-0 bg-body-tertiary rounded-4"><div class="card-body"><div class="small text-body-secondary">Saldo projetado</div><div class="fw-bold"><?= format_money($currentBalance + $metrics['balance']) ?></div></div></div></div>
            </div>
            <details class="mt-3">
              <summary class="fw-semibold">Ver por dia</summary>
              <div class="list-group list-group-flush mt-2">
                <?php foreach ($metrics['days'] as $day => $items): ?>
                  <div class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                      <div class="fw-semibold"><?= e($day) ?></div>
                      <div class="small text-body-secondary"><?= count($items) ?> lançamento(s)</div>
                    </div>
                    <span class="badge text-bg-light border"><?= format_money(array_sum(array_map(fn($item) => (float) $item['amount'], $items))) ?></span>
                  </div>
                <?php endforeach; ?>
                <?php if (!$metrics['days']): ?><div class="list-group-item text-body-secondary">Sem itens neste período.</div><?php endif; ?>
              </div>
            </details>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
</body>
</html>
