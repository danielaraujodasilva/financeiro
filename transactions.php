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
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Lançamentos</title>
<?= bootstrap_assets() ?>
<link rel="stylesheet" href="<?= e(base_path('assets/ui.css')) ?>">
</head>
<body class="bg-body-tertiary">
<div class="container py-3 py-lg-4">
  <?php financial_nav($instanceId,'transactions'); ?>
  <div class="card shadow-sm border-0 rounded-4 mb-3">
    <div class="card-body p-3 p-lg-4">
      <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start gap-3">
        <div>
          <span class="badge rounded-pill text-bg-primary-subtle text-primary-emphasis mb-2">Extrato simples</span>
          <h1 class="h2 fw-bold mb-2">Lançamentos</h1>
          <p class="text-body-secondary mb-0">Veja o que entrou, saiu e o que está vencendo sem abrir formulário gigante.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
          <a class="btn btn-primary" href="<?= e(base_path('dashboard.php?add=1')) ?>">+ Adicionar</a>
          <a class="btn btn-outline-secondary" href="<?= e(base_path('dashboard.php')) ?>">Início</a>
        </div>
      </div>
      <div class="d-flex flex-wrap gap-2 mt-3">
        <?php foreach (['all' => 'Todos', 'income' => 'Entradas', 'expense' => 'Gastos', 'card' => 'Cartão', 'overdue' => 'Vencidos', 'month' => 'Este mês'] as $k => $label): ?>
          <a class="btn <?= $filter === $k ? 'btn-primary' : 'btn-outline-primary' ?>" href="<?= e(base_path('transactions.php?instance_id=' . $instanceId . '&filter=' . $k)) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="card shadow-sm border-0 rounded-4">
    <div class="card-body p-0">
      <div class="list-group list-group-flush">
        <?php foreach ($filtered as $t): ?>
          <div class="list-group-item d-flex justify-content-between align-items-start gap-3">
            <div>
              <div class="fw-semibold"><?= e($t['description']) ?></div>
              <div class="text-body-secondary small"><?= e($t['transaction_date']) ?> · <?= financial_type_label((string)$t['type']) ?> · <?= financial_status_label((string)$t['status']) ?></div>
            </div>
            <span class="badge text-bg-light border rounded-pill"><?= format_money((float)$t['amount']) ?></span>
          </div>
        <?php endforeach; ?>
        <?php if(!$filtered): ?><div class="list-group-item text-body-secondary">Nenhum lançamento encontrado.</div><?php endif; ?>
      </div>
    </div>
  </div>
</div>
</body>
</html>
