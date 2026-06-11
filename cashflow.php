<?php
require __DIR__ . '/bootstrap.php';

$instanceId = (int) ($_GET['instance_id'] ?? 0);
if (!$instanceId) {
    exit('Instância obrigatória.');
}

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
        if ($dateValue === '') {
            continue;
        }

        $date = new DateTimeImmutable(substr($dateValue, 0, 10));
        if ($date < $start || $date > $end) {
            continue;
        }

        $amount = (float) ($transaction['amount'] ?? 0);
        if (($transaction['type'] ?? '') === 'income') {
            $income += $amount;
        } else {
            $expense += $amount;
        }

        $key = $date->format('Y-m-d');
        $days[$key][] = $transaction;
    }

    ksort($days);

    return [
        'income' => $income,
        'expense' => $expense,
        'balance' => $income - $expense,
        'days' => $days,
    ];
}

$periods = [
    '7 dias' => [$today, $today->modify('+7 days')],
    '30 dias' => [$today, $today->modify('+30 days')],
    '60 dias' => [$today, $today->modify('+60 days')],
    '90 dias' => [$today, $today->modify('+90 days')],
];

$cards = [];
$currentBalance = 0.0;
foreach ($financial->accounts($instanceId) as $account) {
    $currentBalance += (float) $account['current_balance'];
}

foreach ($periods as $label => [$start, $end]) {
    $cards[$label] = cashflow_window($transactions, $start, $end);
}

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
<body>
<div class="wrap">
  <?php financial_nav($instanceId, 'cashflow'); ?>
  <div class="card hero">
    <div class="tag">Fluxo de caixa</div>
    <h1 class="headline">Próximos vencimentos e projeções</h1>
    <p class="muted">Olhe rápido para os próximos 7, 30, 60 e 90 dias para entender entradas, saídas e saldo projetado.</p>
    <div class="actions">
      <a class="btn btn-primary" href="<?= e(base_path('dashboard.php?add=1')) ?>">+ Adicionar</a>
      <a class="btn btn-secondary" href="<?= e(base_path('transactions.php?instance_id=' . $instanceId)) ?>">Lançamentos</a>
    </div>
  </div>

  <div class="grid">
    <?php foreach ($cards as $label => $metrics): ?>
      <div class="card enter">
        <h2 class="mb-1"><?= e($label) ?></h2>
        <div class="statbar">
          <div class="stat"><span class="muted">Entradas previstas</span><strong><?= format_money($metrics['income']) ?></strong></div>
          <div class="stat"><span class="muted">Saídas previstas</span><strong><?= format_money($metrics['expense']) ?></strong></div>
          <div class="stat"><span class="muted">Saldo projetado</span><strong><?= format_money($currentBalance + $metrics['balance']) ?></strong></div>
        </div>
        <details style="margin-top:14px">
          <summary class="tag" style="cursor:pointer">Ver por dia</summary>
          <div class="list" style="margin-top:14px">
            <?php foreach ($metrics['days'] as $day => $items): ?>
              <div class="member">
                <div class="meta">
                  <strong><?= e($day) ?></strong>
                  <span class="muted"><?= count($items) ?> lançamento(s)</span>
                </div>
                <span class="tag"><?= format_money(array_sum(array_map(fn($item) => (float) $item['amount'], $items))) ?></span>
              </div>
            <?php endforeach; ?>
            <?php if (!$metrics['days']): ?><p class="muted mb-0">Sem itens neste período.</p><?php endif; ?>
          </div>
        </details>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="card enter">
    <h2 class="mb-1">Alertas rápidos</h2>
    <div class="list">
      <?php
        $overdue = array_filter($transactions, fn($t) => (($t['status'] ?? '') !== 'paid' && ($t['status'] ?? '') !== 'canceled') && !empty($t['due_date']) && $t['due_date'] < date('Y-m-d'));
        $overdueValue = array_sum(array_map(fn($t) => (float) $t['amount'], $overdue));
      ?>
      <div class="member"><div class="meta"><strong>Vencidos</strong><span class="muted">Tudo que passou do prazo</span></div><span class="tag"><?= format_money($overdueValue) ?></span></div>
      <div class="member"><div class="meta"><strong>Saldo atual</strong><span class="muted">Com base nas contas cadastradas</span></div><span class="tag"><?= format_money($currentBalance) ?></span></div>
      <div class="member"><div class="meta"><strong>Horizonte de atenção</strong><span class="muted">Os próximos 30 dias são o foco principal</span></div><span class="tag"><?= format_money($cards['30 dias']['balance']) ?></span></div>
    </div>
  </div>
</div>
</body>
</html>
