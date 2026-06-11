<?php
require __DIR__ . '/bootstrap.php';

$userId = $auth->requireLogin();
$user = $auth->currentUser();
$instances = $auth->instancesForUser($userId);
$interfaceMode = $auth->interfaceMode($userId);
$forceDashboard = (string) ($_GET['view'] ?? '') === 'chooser';

if (!$forceDashboard && count($instances) === 1 && (string) ($_GET['add'] ?? '') !== '1') {
    header('Location: ' . base_path('financial.php?instance_id=' . (int) $instances[0]['id']));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_mode') {
    $auth->setInterfaceMode($userId, (string) ($_POST['mode'] ?? 'simple'));
    header('Location: ' . base_path('dashboard.php?view=chooser'));
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

    $cardStmt = $pdo->prepare('SELECT COALESCE(SUM(total_amount),0) FROM credit_card_bills WHERE instance_id = ? AND status IN ("open", "overdue")');
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

$overall = ['current_balance' => 0,'income_received' => 0,'income_planned' => 0,'expense_paid' => 0,'expense_planned' => 0,'overdue_amount' => 0,'due_next7' => 0,'reserve' => 0,'open_bills' => 0];
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
<body class="bg-body-tertiary">
<div class="container py-3 py-lg-4">
  <div class="card shadow-sm border-0 rounded-4 mb-3">
    <div class="card-body p-3 p-lg-4">
      <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start align-items-lg-center gap-3">
        <div>
          <span class="badge rounded-pill text-bg-primary-subtle text-primary-emphasis mb-2">Financeiro · Multi-instância</span>
          <h1 class="h2 fw-bold mb-1">Bem-vindo, <?= e($user['name']) ?></h1>
          <div class="text-body-secondary">Seu centro de comando financeiro, sem complicação.</div>
        </div>
        <div class="d-flex flex-wrap gap-2">
          <a class="btn btn-outline-secondary" href="<?= e(base_path('logout.php')) ?>">Sair</a>
          <a class="btn btn-primary" href="<?= e(base_path('instance-create.php')) ?>">Nova instância</a>
        </div>
      </div>
    </div>
  </div>

  <div class="card shadow-sm border-0 rounded-4 mb-3">
    <div class="card-body p-3 p-lg-4">
      <div class="row g-3 align-items-start">
        <div class="col-12 col-lg-6">
          <span class="badge rounded-pill text-bg-light border mb-2">Modo básico primeiro</span>
          <h2 class="h3 fw-bold mb-2">Resumo da sua vida financeira</h2>
          <p class="text-body-secondary mb-0">O sistema mostra primeiro o essencial: saldo, risco, entradas e saídas.</p>
        </div>
        <div class="col-12 col-lg-6">
          <div class="row g-2">
            <div class="col-6 col-md-4">
              <div class="card border-0 bg-body-tertiary rounded-4 h-100">
                <div class="card-body py-3 px-3">
                  <div class="small text-body-secondary">Saldo atual</div>
                  <div class="fs-5 fw-bold">R$ <?= number_format($overall['current_balance'], 2, ',', '.') ?></div>
                </div>
              </div>
            </div>
            <div class="col-6 col-md-4">
              <div class="card border-0 bg-body-tertiary rounded-4 h-100">
                <div class="card-body py-3 px-3">
                  <div class="small text-body-secondary">Projetado</div>
                  <div class="fs-5 fw-bold">R$ <?= number_format($overall['projected'], 2, ',', '.') ?></div>
                </div>
              </div>
            </div>
            <div class="col-6 col-md-4">
              <div class="card border-0 bg-body-tertiary rounded-4 h-100">
                <div class="card-body py-3 px-3">
                  <div class="small text-body-secondary">Risco</div>
                  <div class="fs-5 fw-bold"><?= e(ucfirst($overallRisk)) ?></div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="d-flex flex-wrap gap-2 mt-3">
        <a class="btn btn-primary" href="<?= e(base_path('transactions.php?instance_id=' . (int) ($instances[0]['id'] ?? 0))) ?>">Novo lançamento</a>
        <a class="btn btn-outline-primary" href="<?= e(base_path('cards.php?instance_id=' . (int) ($instances[0]['id'] ?? 0))) ?>">Cartões</a>
        <a class="btn btn-outline-secondary" href="<?= e(base_path('financial.php?instance_id=' . (int) ($instances[0]['id'] ?? 0))) ?>">Configurações</a>
      </div>

      <div class="d-flex flex-wrap gap-2 mt-3">
        <form method="post" class="d-flex flex-wrap gap-2 align-items-center">
          <input type="hidden" name="action" value="set_mode">
          <button class="btn <?= $interfaceMode === 'simple' ? 'btn-primary' : 'btn-outline-primary' ?>" name="mode" value="simple" type="submit">Modo simples</button>
          <button class="btn <?= $interfaceMode === 'advanced' ? 'btn-primary' : 'btn-outline-primary' ?>" name="mode" value="advanced" type="submit">Modo avançado</button>
        </form>
        <span class="badge rounded-pill text-bg-light border align-self-center">Padrão atual: <?= e(ucfirst($interfaceMode)) ?></span>
      </div>
    </div>
  </div>

  <?php if (!$onboardingCompleted): ?>
    <div class="alert alert-warning border-0 rounded-4 shadow-sm">
      <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
        <div>
          <div class="fw-semibold">Primeiro acesso</div>
          <div class="text-body-secondary">Quer deixar tudo pronto em poucos passos?</div>
        </div>
        <a class="btn btn-warning" href="<?= e(base_path('onboarding.php')) ?>">Começar onboarding</a>
      </div>
    </div>
  <?php endif; ?>

  <div class="row g-3 mb-3">
    <div class="col-12 col-lg-8">
      <div class="card shadow-sm border-0 rounded-4 h-100">
        <div class="card-body p-3 p-lg-4">
          <h2 class="h4 fw-bold mb-3">Resumo rápido</h2>
          <div class="row g-2">
            <div class="col-12 col-md-4">
              <div class="card border-0 bg-body-tertiary rounded-4 h-100">
                <div class="card-body">
                  <div class="text-body-secondary small">Entradas</div>
                  <div class="fs-5 fw-bold">R$ <?= number_format($overall['income_received'] + $overall['income_planned'], 2, ',', '.') ?></div>
                </div>
              </div>
            </div>
            <div class="col-12 col-md-4">
              <div class="card border-0 bg-body-tertiary rounded-4 h-100">
                <div class="card-body">
                  <div class="text-body-secondary small">Saídas</div>
                  <div class="fs-5 fw-bold">R$ <?= number_format($overall['expense_paid'] + $overall['expense_planned'], 2, ',', '.') ?></div>
                </div>
              </div>
            </div>
            <div class="col-12 col-md-4">
              <div class="card border-0 bg-body-tertiary rounded-4 h-100">
                <div class="card-body">
                  <div class="text-body-secondary small">Vencido</div>
                  <div class="fs-5 fw-bold">R$ <?= number_format($overall['overdue_amount'], 2, ',', '.') ?></div>
                </div>
              </div>
            </div>
          </div>
          <details class="mt-3">
            <summary class="fw-semibold">Ver detalhamento mensal</summary>
            <div class="row g-2 mt-2">
              <div class="col-12 col-md-4"><div class="card border-0 bg-body-tertiary rounded-4"><div class="card-body"><div class="text-body-secondary small">Entradas recebidas</div><div class="fw-semibold">R$ <?= number_format($overall['income_received'], 2, ',', '.') ?></div></div></div></div>
              <div class="col-12 col-md-4"><div class="card border-0 bg-body-tertiary rounded-4"><div class="card-body"><div class="text-body-secondary small">Entradas previstas</div><div class="fw-semibold">R$ <?= number_format($overall['income_planned'], 2, ',', '.') ?></div></div></div></div>
              <div class="col-12 col-md-4"><div class="card border-0 bg-body-tertiary rounded-4"><div class="card-body"><div class="text-body-secondary small">Saídas previstas</div><div class="fw-semibold">R$ <?= number_format($overall['expense_planned'], 2, ',', '.') ?></div></div></div></div>
            </div>
          </details>
        </div>
      </div>
    </div>

    <div class="col-12 col-lg-4">
      <div class="card shadow-sm border-0 rounded-4 h-100">
        <div class="card-body p-3 p-lg-4">
          <h2 class="h4 fw-bold mb-3">Instâncias</h2>
          <div class="list-group list-group-flush rounded-4 overflow-hidden">
            <?php foreach ($instanceSummaries as $instance): ?>
              <div class="list-group-item d-flex flex-column gap-2">
                <div class="d-flex justify-content-between align-items-start gap-3">
                  <div>
                    <div class="fw-semibold"><?= e($instance['name']) ?></div>
                    <div class="text-body-secondary small">Função: <?= e($instance['role']) ?> · Risco: <?= e($instance['risk']) ?></div>
                  </div>
                  <span class="badge text-bg-light border">R$ <?= number_format((float) $instance['projected'], 2, ',', '.') ?></span>
                </div>
                <div class="d-flex flex-wrap gap-2">
                  <a class="btn btn-outline-secondary btn-sm" href="<?= e(base_path('instance.php?id=' . (int) $instance['id'])) ?>">Abrir</a>
                  <a class="btn btn-primary btn-sm" href="<?= e(base_path('financial.php?instance_id=' . (int) $instance['id'])) ?>">Financeiro</a>
                </div>
              </div>
            <?php endforeach; ?>
            <?php if (!$instances): ?>
              <div class="text-body-secondary">Você ainda não tem instâncias. Crie a primeira para começar.</div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="card shadow-sm border-0 rounded-4">
    <div class="card-body p-3 p-lg-4">
      <h2 class="h4 fw-bold mb-3">Convites pendentes</h2>
      <?php $invites = $auth->pendingInvitesForEmail($user['email']); ?>
      <?php if (!$invites): ?>
        <div class="text-body-secondary">Nenhum convite pendente.</div>
      <?php else: ?>
        <div class="list-group list-group-flush rounded-4 overflow-hidden">
          <?php foreach ($invites as $invite): ?>
            <div class="list-group-item d-flex justify-content-between align-items-center gap-3">
              <div>
                <div class="fw-semibold"><?= e($invite['instance_name']) ?></div>
                <div class="text-body-secondary small">Convite em aberto</div>
              </div>
              <a class="btn btn-success btn-sm" href="<?= e(base_path('accept-invite.php?token=' . $invite['token'])) ?>">Aceitar</a>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <?= $quickAddInstanceId ? quick_add_modal($quickAddInstanceId, $quickAddAccounts, $quickAddCenters, $quickAddCategories, $quickAddCards) : '' ?>
  <?php if ($quickAddInstanceId): ?>
    <a class="btn btn-primary floating-add d-md-none" href="<?= e(base_path('dashboard.php?add=1&quick_instance_id=' . $quickAddInstanceId)) ?>">+ Adicionar</a>
    <div class="bottom-nav nav-mobile">
      <a class="bottom-nav-item active" href="<?= e(base_path('dashboard.php')) ?>">Início</a>
      <a class="bottom-nav-item" href="<?= e(base_path('dashboard.php?add=1&quick_instance_id=' . $quickAddInstanceId)) ?>">Adicionar</a>
      <a class="bottom-nav-item" href="<?= e(base_path('transactions.php?instance_id=' . $quickAddInstanceId)) ?>">Lançamentos</a>
      <a class="bottom-nav-item" href="<?= e(base_path('cards.php?instance_id=' . $quickAddInstanceId)) ?>">Cartões</a>
      <a class="bottom-nav-item" href="<?= e(base_path('financial.php?instance_id=' . $quickAddInstanceId)) ?>">Mais</a>
    </div>
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
