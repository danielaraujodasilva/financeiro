<?php
require __DIR__ . '/bootstrap.php';

$userId = $auth->requireLogin();
$user = $auth->currentUser();
$instances = $auth->instancesForUser($userId);
$interfaceMode = $auth->interfaceMode($userId);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_mode') {
    $auth->setInterfaceMode($userId, (string) ($_POST['mode'] ?? 'simple'));
    header('Location: ' . base_path('dashboard.php'));
    exit;
}

$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-t');
$plus7 = date('Y-m-d', strtotime('+7 days'));

function finance_instance_summary(PDO $pdo, int $instanceId, string $monthStart, string $monthEnd, string $today, string $plus7): array
{
    $summaryStmt = $pdo->prepare('
        SELECT
            COALESCE(SUM(CASE WHEN type = "income" AND status = "paid" AND transaction_date BETWEEN ? AND ? THEN amount ELSE 0 END), 0) AS income_received,
            COALESCE(SUM(CASE WHEN type = "income" AND status IN ("planned", "pending") AND transaction_date BETWEEN ? AND ? THEN amount ELSE 0 END), 0) AS income_planned,
            COALESCE(SUM(CASE WHEN type = "expense" AND status = "paid" AND transaction_date BETWEEN ? AND ? THEN amount ELSE 0 END), 0) AS expense_paid,
            COALESCE(SUM(CASE WHEN type = "expense" AND status IN ("planned", "pending") AND transaction_date BETWEEN ? AND ? THEN amount ELSE 0 END), 0) AS expense_planned,
            COALESCE(SUM(CASE WHEN due_date < ? AND status NOT IN ("paid", "canceled") AND type = "expense" THEN amount ELSE 0 END), 0) AS overdue_amount,
            COALESCE(SUM(CASE WHEN due_date BETWEEN ? AND ? AND status NOT IN ("paid", "canceled") AND type IN ("income", "expense") THEN amount ELSE 0 END), 0) AS due_next7,
            COALESCE(SUM(CASE WHEN status = "paid" AND type = "income" AND transaction_date BETWEEN ? AND ? THEN amount ELSE 0 END), 0) -
            COALESCE(SUM(CASE WHEN status = "paid" AND type = "expense" AND transaction_date BETWEEN ? AND ? THEN amount ELSE 0 END), 0) AS net_month,
            COALESCE(SUM(CASE WHEN status IN ("planned", "pending") AND type = "expense" AND transaction_date BETWEEN ? AND ? THEN amount ELSE 0 END), 0) AS future_expense_month
        FROM financial_transactions
        WHERE instance_id = ?
    ');
    $summaryStmt->execute([
        $monthStart, $monthEnd,
        $monthStart, $monthEnd,
        $monthStart, $monthEnd,
        $monthStart, $monthEnd,
        $today,
        $today, $plus7,
        $monthStart, $monthEnd,
        $monthStart, $monthEnd,
        $monthStart, $monthEnd,
        $instanceId,
    ]);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $reserveStmt = $pdo->prepare('SELECT COALESCE(SUM(current_balance),0) FROM financial_accounts WHERE instance_id = ? AND type = "investment"');
    $reserveStmt->execute([$instanceId]);
    $reserve = (float) $reserveStmt->fetchColumn();

    $balanceStmt = $pdo->prepare('SELECT COALESCE(SUM(current_balance),0) FROM financial_accounts WHERE instance_id = ?');
    $balanceStmt->execute([$instanceId]);
    $currentBalance = (float) $balanceStmt->fetchColumn();

    $cardStmt = $pdo->prepare('
        SELECT COALESCE(SUM(total_amount),0)
        FROM credit_card_bills
        WHERE instance_id = ? AND status IN ("open", "overdue")
    ');
    $cardStmt->execute([$instanceId]);
    $openBills = (float) $cardStmt->fetchColumn();

    $projected = $currentBalance + (float) ($summary['net_month'] ?? 0) - (float) ($summary['future_expense_month'] ?? 0) - $openBills;

    $risk = 'baixo';
    if ($projected < 0 || (float) ($summary['overdue_amount'] ?? 0) > 0) {
        $risk = 'alto';
    } elseif ($openBills > 0 || (float) ($summary['expense_planned'] ?? 0) > 0) {
        $risk = 'médio';
    }

    return [
        'current_balance' => $currentBalance,
        'income_received' => (float) ($summary['income_received'] ?? 0),
        'income_planned' => (float) ($summary['income_planned'] ?? 0),
        'expense_paid' => (float) ($summary['expense_paid'] ?? 0),
        'expense_planned' => (float) ($summary['expense_planned'] ?? 0),
        'overdue_amount' => (float) ($summary['overdue_amount'] ?? 0),
        'due_next7' => (float) ($summary['due_next7'] ?? 0),
        'projected' => $projected,
        'reserve' => $reserve,
        'open_bills' => $openBills,
        'risk' => $risk,
    ];
}

$instanceSummaries = [];
foreach ($instances as $inst) {
    $instanceSummaries[] = array_merge($inst, finance_instance_summary($pdo, (int) $inst['id'], $monthStart, $monthEnd, $today, $plus7));
}

$quickAddInstanceId = (int) ($_GET['quick_instance_id'] ?? ($instances[0]['id'] ?? 0));
$quickAddMode = (string) ($_GET['add'] ?? '') === '1';
$quickAddCenters = $quickAddInstanceId ? $financial->centers($quickAddInstanceId) : [];
$quickAddCategories = $quickAddInstanceId ? $financial->categories($quickAddInstanceId) : [];
$quickAddAccounts = $quickAddInstanceId ? $financial->accounts($quickAddInstanceId) : [];
$quickAddCards = $quickAddInstanceId ? $financial->cards($quickAddInstanceId) : [];

$overall = [
    'current_balance' => 0,
    'income_received' => 0,
    'income_planned' => 0,
    'expense_paid' => 0,
    'expense_planned' => 0,
    'overdue_amount' => 0,
    'due_next7' => 0,
    'reserve' => 0,
    'open_bills' => 0,
];
foreach ($instanceSummaries as $summary) {
    foreach ($overall as $key => $value) {
        $overall[$key] += (float) ($summary[$key] ?? 0);
    }
}

$overall['projected'] = $overall['current_balance'] + $overall['income_received'] + $overall['income_planned'] - $overall['expense_paid'] - $overall['expense_planned'] - $overall['overdue_amount'] - $overall['open_bills'];

$overallRisk = 'baixo';
if ($overall['projected'] < 0 || $overall['overdue_amount'] > 0) {
    $overallRisk = 'alto';
} elseif ($overall['open_bills'] > 0 || $overall['expense_planned'] > 0) {
    $overallRisk = 'médio';
}
$onboardingCompleted = (int) ($user['onboarding_completed'] ?? 0) === 1;
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dashboard - Financeiro</title>
<?= bootstrap_assets() ?>
<link rel="stylesheet" href="<?= e(base_path('assets/ui.css')) ?>">
</head>
<body>
<div class="wrap">
  <div class="topbar fade-in">
    <div class="brand">
      <div class="mark"></div>
      <div>
        <div class="tag">Financeiro · Multi-instância</div>
        <h1 class="headline">Bem-vindo, <?= e($user['name']) ?></h1>
      </div>
    </div>
    <div class="actions">
      <a class="btn btn-secondary" href="<?= e(base_path('logout.php')) ?>">Sair</a>
      <a class="btn btn-primary" href="<?= e(base_path('instance-create.php')) ?>">Nova instância</a>
    </div>
  </div>

  <div class="card hero enter">
    <div class="split">
      <div>
        <div class="tag">Modo básico primeiro</div>
        <h2>Seu centro de comando financeiro, sem complicação</h2>
        <p class="muted">O sistema mostra primeiro o essencial: saldo, risco, entradas e saídas. As ferramentas mais avançadas continuam disponíveis, mas ficam organizadas em camadas para não assustar ninguém.</p>
      </div>
      <div>
        <div class="tag">Risco geral: <?= e($overallRisk) ?></div>
        <div class="statbar">
          <div class="stat"><span class="muted">Saldo atual</span><strong>R$ <?= number_format($overall['current_balance'], 2, ',', '.') ?></strong></div>
          <div class="stat"><span class="muted">Projetado</span><strong>R$ <?= number_format($overall['projected'], 2, ',', '.') ?></strong></div>
          <div class="stat"><span class="muted">Reserva</span><strong>R$ <?= number_format($overall['reserve'], 2, ',', '.') ?></strong></div>
      </div>
    </div>
    <div class="actions" style="margin-top:16px">
      <a class="btn btn-primary" href="<?= e(base_path('transactions.php?instance_id=' . (int) ($instances[0]['id'] ?? 0))) ?>">Novo lançamento</a>
      <a class="btn btn-secondary" href="<?= e(base_path('cards.php?instance_id=' . (int) ($instances[0]['id'] ?? 0))) ?>">Cartões</a>
      <a class="btn btn-secondary" href="<?= e(base_path('financial.php?instance_id=' . (int) ($instances[0]['id'] ?? 0))) ?>">Ver base</a>
    </div>
    <div class="d-flex flex-wrap gap-2 mt-3 align-items-center">
      <form method="post" class="d-flex gap-2 align-items-center">
        <input type="hidden" name="action" value="set_mode">
        <button class="btn <?= $interfaceMode === 'simple' ? 'btn-primary' : 'btn-secondary' ?>" name="mode" value="simple" type="submit">Modo simples</button>
        <button class="btn <?= $interfaceMode === 'advanced' ? 'btn-primary' : 'btn-secondary' ?>" name="mode" value="advanced" type="submit">Modo avançado</button>
      </form>
      <span class="tag">Padrão atual: <?= e(ucfirst($interfaceMode)) ?></span>
    </div>
  </div>

  <?php if (!$onboardingCompleted): ?>
    <div class="card enter">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
          <div class="tag">Primeiro acesso</div>
          <h2 class="mb-1">Quer deixar tudo pronto em poucos passos?</h2>
          <p class="muted mb-0">O onboarding ajuda a escolher o foco, criar áreas e cadastrar contas e gastos fixos iniciais.</p>
        </div>
        <a class="btn btn-primary" href="<?= e(base_path('onboarding.php')) ?>">Começar onboarding</a>
      </div>
    </div>
  <?php endif; ?>
    <div class="statbar">
      <div class="stat"><span class="muted">Instâncias</span><strong><?= count($instances) ?></strong></div>
      <div class="stat"><span class="muted">Convites pendentes</span><strong><?= count($auth->pendingInvitesForEmail($user['email'])) ?></strong></div>
      <div class="stat"><span class="muted">Conta</span><strong><?= e($user['email']) ?></strong></div>
    </div>
  </div>

  <div class="grid">
    <div class="card enter">
      <h2>Resumo rápido</h2>
      <div class="statbar">
        <div class="stat"><span class="muted">Entradas</span><strong>R$ <?= number_format($overall['income_received'] + $overall['income_planned'], 2, ',', '.') ?></strong></div>
        <div class="stat"><span class="muted">Saídas</span><strong>R$ <?= number_format($overall['expense_paid'] + $overall['expense_planned'], 2, ',', '.') ?></strong></div>
        <div class="stat"><span class="muted">Saldo projetado</span><strong>R$ <?= number_format($overall['projected'], 2, ',', '.') ?></strong></div>
      </div>
      <details style="margin-top:14px">
        <summary class="tag" style="cursor:pointer">Ver detalhamento mensal</summary>
        <div class="statbar" style="margin-top:14px">
          <div class="stat"><span class="muted">Entradas recebidas</span><strong>R$ <?= number_format($overall['income_received'], 2, ',', '.') ?></strong></div>
          <div class="stat"><span class="muted">Entradas previstas</span><strong>R$ <?= number_format($overall['income_planned'], 2, ',', '.') ?></strong></div>
          <div class="stat"><span class="muted">Saídas pagas</span><strong>R$ <?= number_format($overall['expense_paid'], 2, ',', '.') ?></strong></div>
          <div class="stat"><span class="muted">Saídas previstas</span><strong>R$ <?= number_format($overall['expense_planned'], 2, ',', '.') ?></strong></div>
          <div class="stat"><span class="muted">Vencido</span><strong>R$ <?= number_format($overall['overdue_amount'], 2, ',', '.') ?></strong></div>
          <div class="stat"><span class="muted">Faturas abertas</span><strong>R$ <?= number_format($overall['open_bills'], 2, ',', '.') ?></strong></div>
        </div>
      </details>
    </div>

    <div class="card enter">
      <h2>Suas instâncias</h2>
      <div class="list stagger">
        <?php foreach ($instanceSummaries as $instance): ?>
          <div class="member">
            <div class="meta">
              <strong><?= e($instance['name']) ?></strong>
              <span class="muted">Função: <?= e($instance['role']) ?> · Risco: <?= e($instance['risk']) ?></span>
            </div>
            <div class="actions">
              <a class="btn btn-secondary" href="<?= e(base_path('instance.php?id=' . (int) $instance['id'])) ?>">Abrir</a>
              <a class="btn btn-primary" href="<?= e(base_path('financial.php?instance_id=' . (int) $instance['id'])) ?>">Financeiro</a>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if (!$instances): ?>
          <p class="muted">Você ainda não tem instâncias. Crie a primeira para começar.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="card enter">
    <h2>Convites pendentes</h2>
    <?php $invites = $auth->pendingInvitesForEmail($user['email']); ?>
    <?php if (!$invites): ?>
      <p class="muted">Nenhum convite pendente.</p>
    <?php else: ?>
      <div class="list">
        <?php foreach ($invites as $invite): ?>
          <div class="member">
            <div class="meta">
              <strong><?= e($invite['instance_name']) ?></strong>
              <span class="muted">Convite em aberto</span>
            </div>
            <a class="btn btn-good" href="<?= e(base_path('accept-invite.php?token=' . $invite['token'])) ?>">Aceitar</a>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
  <?= $quickAddInstanceId ? quick_add_modal($quickAddInstanceId, $quickAddAccounts, $quickAddCenters, $quickAddCategories, $quickAddCards) : '' ?>
  <?php if ($quickAddInstanceId): ?>
    <a class="btn btn-primary floating-add d-md-none" href="<?= e(base_path('dashboard.php?add=1&quick_instance_id=' . $quickAddInstanceId)) ?>">+ Adicionar</a>
  <?php endif; ?>
</div>
<?php if ($quickAddMode): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var modal = document.getElementById('quickAddModal');
  if (modal && window.bootstrap) {
    new bootstrap.Modal(modal).show();
  }
});
</script>
<?php endif; ?>
</body>
</html>
