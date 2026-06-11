<?php
require __DIR__ . '/bootstrap.php';

$userId = $auth->requireLogin();
$user = $auth->currentUser();
$instances = $auth->instancesForUser($userId);
$interfaceMode = $auth->interfaceMode($userId);
$forceDashboard = (string) ($_GET['view'] ?? '') === 'chooser';

$monthOptionsStmt = $pdo->prepare('
    SELECT DISTINCT substr(transaction_date, 1, 7) AS month_key
    FROM financial_transactions
    WHERE instance_id IN (' . implode(',', array_fill(0, max(1, count($instances)), '?')) . ')
    ORDER BY month_key DESC
');
$monthOptionParams = $instances ? array_map(fn($i) => (int) $i['id'], $instances) : [0];
$monthOptionsStmt->execute($monthOptionParams);
$availableMonths = array_column($monthOptionsStmt->fetchAll(PDO::FETCH_ASSOC), 'month_key');
if (!$availableMonths) {
    $availableMonths = [date('Y-m')];
}

$selectedMonth = (string) ($_GET['month'] ?? $availableMonths[0]);
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth) || !in_array($selectedMonth, $availableMonths, true)) {
    $selectedMonth = $availableMonths[0];
}
$monthStart = $selectedMonth . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_mode') {
    $auth->setInterfaceMode($userId, (string) ($_POST['mode'] ?? 'simple'));
    header('Location: ' . base_path('dashboard.php?view=chooser'));
    exit;
}

$today = date('Y-m-d');
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
$progressBase = max(1, $overall['current_balance'] + $overall['income_received'] + $overall['income_planned'] + $overall['expense_paid'] + $overall['expense_planned'] + $overall['open_bills'] + $overall['overdue_amount']);
$progressCovered = (int) min(100, max(0, round((max(0, $progressBase - ($overall['expense_paid'] + $overall['expense_planned'] + $overall['open_bills'] + $overall['overdue_amount'])) / $progressBase) * 100)));
$recommendations = [];
if ($overall['overdue_amount'] > 0) {
    $recommendations[] = ['title' => 'Registrar contas vencidas', 'meta' => 'Há valores em atraso para resolver agora.', 'tone' => 'danger'];
}
if ($overall['due_next7'] > 0) {
    $recommendations[] = ['title' => 'Revisar vencimentos dos próximos 7 dias', 'meta' => 'Alguns compromissos estão chegando.', 'tone' => 'warning'];
}
if ($overall['projected'] >= 0) {
    $recommendations[] = ['title' => 'Manter o ritmo atual', 'meta' => 'O mês está sob controle no momento.', 'tone' => 'success'];
} else {
    $recommendations[] = ['title' => 'Reduzir gastos não essenciais', 'meta' => 'A previsão até o fim do mês está negativa.', 'tone' => 'danger'];
}
if ($overall['open_bills'] > 0) {
    $recommendations[] = ['title' => 'Acompanhar faturas abertas', 'meta' => 'Existe valor de cartão comprometido.', 'tone' => 'info'];
}
if (!$recommendations) {
    $recommendations[] = ['title' => 'Nenhuma ação urgente', 'meta' => 'Continue acompanhando o resumo diário.', 'tone' => 'success'];
}
$onboardingCompleted = (int) ($user['onboarding_completed'] ?? 0) === 1;

$graphStmt = $pdo->prepare('
    SELECT
        substr(transaction_date, 1, 10) AS day_key,
        SUM(CASE WHEN type = "income" AND status = "paid" THEN amount ELSE 0 END) AS income_amount,
        SUM(CASE WHEN type = "expense" AND status = "paid" THEN amount ELSE 0 END) AS expense_amount
    FROM financial_transactions
    WHERE instance_id IN (' . implode(',', array_fill(0, max(1, count($instances)), '?')) . ')
      AND transaction_date BETWEEN ? AND ?
    GROUP BY substr(transaction_date, 1, 10)
    ORDER BY day_key ASC
');
$graphParams = $instances ? array_map(fn($i) => (int) $i['id'], $instances) : [0];
$graphParams[] = $monthStart;
$graphParams[] = $monthEnd;
$graphStmt->execute($graphParams);
$graphRows = $graphStmt->fetchAll(PDO::FETCH_ASSOC);

$monthLabelMap = [
    '01' => 'Janeiro', '02' => 'Fevereiro', '03' => 'Março', '04' => 'Abril',
    '05' => 'Maio', '06' => 'Junho', '07' => 'Julho', '08' => 'Agosto',
    '09' => 'Setembro', '10' => 'Outubro', '11' => 'Novembro', '12' => 'Dezembro',
];
$selectedMonthLabel = $monthLabelMap[substr($selectedMonth, 5, 2)] . ' de ' . substr($selectedMonth, 0, 4);
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dashboard - Financeiro</title>
<?= bootstrap_assets() ?>
<link rel="stylesheet" href="<?= e(base_path('assets/ui.css')) ?>">
<style>
.dashboard-shell{display:grid; grid-template-columns:120px minmax(0,1fr); gap:15px}
.dashboard-sidebar{
  position:sticky; top:14px; align-self:start;
  min-height:calc(100vh - 28px);
  padding:16px 13px;
  border-radius:26px;
  border:1px solid rgba(15,23,42,.06);
  background:rgba(255,255,255,.92);
  box-shadow:0 16px 40px rgba(15,23,42,.06);
  backdrop-filter:blur(16px);
}
.dashboard-brand{
  width:42px; height:42px; border-radius:13px;
  background:linear-gradient(135deg,#1d4ed8,#60a5fa);
  box-shadow:0 12px 30px rgba(37,99,235,.20);
}
.dashboard-nav{display:grid; gap:6px; margin-top:24px}
.dashboard-nav a{
  display:flex; align-items:center; gap:10px;
  padding:10px 10px; border-radius:14px;
  color:#39475f; font-weight:700; font-size:.88rem;
}
.dashboard-nav a.active{background:rgba(37,99,235,.08); color:#1d4ed8; box-shadow:inset 3px 0 0 #1d4ed8}
.dashboard-footer{display:grid; gap:9px; position:absolute; bottom:14px; left:13px; right:13px}
.mini-icon{
  width:40px; height:40px; border-radius:13px; display:grid; place-items:center;
  background:rgba(37,99,235,.08); color:#1d4ed8; font-weight:800;
}
.metric-card{
  border:1px solid rgba(15,23,42,.06);
  border-radius:20px;
  background:rgba(255,255,255,.98);
  box-shadow:0 9px 20px rgba(15,23,42,.045);
}
.hero-card{
  border:1px solid rgba(15,23,42,.06);
  border-radius:26px;
  background:
    radial-gradient(circle at 18% 30%, rgba(37,99,235,.10), transparent 22%),
    linear-gradient(180deg, rgba(255,255,255,.98), rgba(247,249,252,.95));
  box-shadow:0 10px 24px rgba(15,23,42,.045);
}
.health-ring{
  width:226px; height:226px; border-radius:50%;
  background:conic-gradient(#1d4ed8 <?= $progressCovered ?>%, #dbe4f1 0);
  padding:20px;
  margin:0 auto;
}
.health-ring-inner{
  width:100%; height:100%; border-radius:50%;
  background:linear-gradient(180deg,#f8fbff,#eef4fb);
  display:grid; place-items:center;
}
.health-percent{font-size:clamp(2.7rem,5vw,4rem); line-height:1; font-weight:800; color:#1e3a8a}
.health-sub{color:#4b5f7a; font-weight:600; font-size:.95rem}
.chart-wrap{
  height:198px;
  border-radius:22px;
  background:linear-gradient(180deg, #fbfdff, #f3f7fc);
  border:1px solid rgba(15,23,42,.05);
  position:relative;
  overflow:hidden;
}
.chart-grid{
  position:absolute; inset:18px 18px 38px 54px;
  background-image:
    linear-gradient(to top, rgba(148,163,184,.18) 1px, transparent 1px),
    linear-gradient(to right, rgba(148,163,184,.14) 1px, transparent 1px);
  background-size:100% 25%, 20% 100%;
}
.chart-line{
  position:absolute; inset:18px 18px 38px 54px;
}
.action-item{
  display:flex; align-items:center; gap:14px;
  padding:14px; border-radius:17px;
  border:1px solid rgba(15,23,42,.06);
  background:#fff;
}
.action-arrow{color:#94a3b8; font-size:1.3rem}
.section-title{display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:11px}
.section-title h2,.section-title h3{margin:0}
@media (max-width: 992px){
  .dashboard-shell{grid-template-columns:1fr}
  .dashboard-sidebar{position:relative; min-height:auto}
  .dashboard-footer{position:static; margin-top:18px}
}
@media (max-width: 768px){
  .health-ring{width:220px; height:220px}
}
</style>
</head>
<body>
<div class="container-fluid py-3 py-lg-4" style="max-width:1380px">
  <div class="dashboard-shell">
    <aside class="dashboard-sidebar d-none d-lg-block">
      <div class="d-flex justify-content-center">
        <div class="dashboard-brand"></div>
      </div>
      <nav class="dashboard-nav">
        <a class="active" href="<?= e(base_path('dashboard.php')) ?>">Início</a>
        <a href="<?= e(base_path('transactions.php?instance_id=' . (int) ($instances[0]['id'] ?? 0))) ?>">Lançamentos</a>
        <a href="<?= e(base_path('cards.php?instance_id=' . (int) ($instances[0]['id'] ?? 0))) ?>">Cartões</a>
        <a href="<?= e(base_path('financial.php?instance_id=' . (int) ($instances[0]['id'] ?? 0))) ?>">Relatórios</a>
        <a href="<?= e(base_path('dashboard.php?view=chooser')) ?>">Mais</a>
      </nav>
      <div class="dashboard-footer">
        <a class="btn btn-primary w-100" href="<?= e(base_path('instance-create.php')) ?>">Nova instância</a>
        <a class="btn btn-outline-secondary w-100" href="<?= e(base_path('logout.php')) ?>">Sair</a>
      </div>
    </aside>

    <main class="min-vw-0">
      <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start align-items-lg-center gap-3 mb-3">
        <div>
          <h1 class="fw-bold mb-1" style="font-size:clamp(2rem,2.8vw,2.8rem); letter-spacing:-0.03em;">Bom dia, <?= e($user['name']) ?>! 👋</h1>
          <div class="text-body-secondary" style="font-size:1rem;">Aqui está a saúde das suas finanças hoje.</div>
        </div>
        <div class="d-flex flex-wrap gap-2">
          <div class="dropdown">
            <button class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" type="button"><?= e($selectedMonthLabel) ?></button>
            <ul class="dropdown-menu dropdown-menu-end">
              <?php foreach ($availableMonths as $monthKey): ?>
                <?php $label = $monthLabelMap[substr($monthKey, 5, 2)] . ' de ' . substr($monthKey, 0, 4); ?>
                <li><a class="dropdown-item <?= $monthKey === $selectedMonth ? 'active' : '' ?>" href="<?= e(base_path('dashboard.php?month=' . $monthKey)) ?>"><?= e($label) ?></a></li>
              <?php endforeach; ?>
            </ul>
          </div>
          <button class="btn btn-outline-secondary" type="button">Filtros</button>
          <a class="btn btn-primary" href="<?= e(base_path('dashboard.php?add=1&quick_instance_id=' . (int) ($instances[0]['id'] ?? 0))) ?>">+ Adicionar</a>
        </div>
      </div>

      <?php if (!$onboardingCompleted): ?>
        <div class="alert alert-warning border-0 rounded-4 shadow-sm mb-3">
          <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
            <div>
              <div class="fw-semibold">Primeiro acesso</div>
              <div class="text-body-secondary">Quer deixar tudo pronto em poucos passos?</div>
            </div>
            <a class="btn btn-warning" href="<?= e(base_path('onboarding.php')) ?>">Começar onboarding</a>
          </div>
        </div>
      <?php endif; ?>

      <section class="hero-card p-3 p-lg-4 mb-3">
        <div class="row g-3 align-items-center">
          <div class="col-12 col-xl-5 text-center">
            <div class="health-ring">
              <div class="health-ring-inner px-3">
                <div class="health-percent"><?= $progressCovered ?>%</div>
                <div class="health-sub">do mês coberto</div>
                <span class="badge text-bg-success-subtle text-success-emphasis mt-3 px-3 py-2">Você está no controle.</span>
              </div>
            </div>
          </div>
          <div class="col-12 col-xl-7">
            <div class="mb-2">
              <h2 class="fw-bold mb-2" style="font-size:clamp(1.4rem,1.8vw,2rem);">Você está indo muito bem!</h2>
              <div class="text-body-secondary" style="font-size:1rem;">Resumo de <?= e($selectedMonthLabel) ?> para você enxergar o mês com clareza.</div>
            </div>
            <div class="row g-2 mt-1">
              <div class="col-12 col-md-6">
                <div class="metric-card p-3 h-100" style="min-height:92px;">
                  <div class="d-flex align-items-center gap-3">
                    <div class="mini-icon">R$</div>
                    <div>
                    <div class="text-body-secondary small" style="font-size:.92rem;">Saldo disponível</div>
                      <div class="fw-bold" style="font-size:1.15rem;">R$ <?= number_format($overall['current_balance'], 2, ',', '.') ?></div>
                    </div>
                  </div>
                </div>
              </div>
              <div class="col-12 col-md-6">
                <div class="metric-card p-3 h-100" style="min-height:92px;">
                  <div class="d-flex align-items-center gap-3">
                    <div class="mini-icon">!</div>
                    <div>
                    <div class="text-body-secondary small" style="font-size:.92rem;">Quanto falta para empatar o mês</div>
                      <div class="fw-bold" style="font-size:1.15rem;">R$ <?= number_format(max(0, -$overall['projected']), 2, ',', '.') ?></div>
                    </div>
                  </div>
                </div>
              </div>
              <div class="col-12 col-md-6">
                <div class="metric-card p-3 h-100" style="min-height:92px;">
                  <div class="d-flex align-items-center gap-3">
                    <div class="mini-icon">↑</div>
                    <div>
                      <div class="text-body-secondary small" style="font-size:.92rem;">Previsão no fim do mês</div>
                      <div class="fw-bold" style="font-size:1.15rem;">R$ <?= number_format($overall['projected'], 2, ',', '.') ?></div>
                    </div>
                  </div>
                </div>
              </div>
              <div class="col-12 col-md-6">
                <div class="metric-card p-3 h-100" style="min-height:92px;">
                  <div class="d-flex align-items-center gap-3">
                    <div class="mini-icon">⏰</div>
                    <div>
                      <div class="text-body-secondary small" style="font-size:.92rem;">Contas vencendo em 7 dias</div>
                      <div class="fw-bold text-danger" style="font-size:1.15rem;">R$ <?= number_format($overall['due_next7'], 2, ',', '.') ?></div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <div class="row g-3 mb-3">
        <div class="col-12 col-md-6 col-xxl-3">
          <div class="metric-card p-3 h-100" style="min-height:140px;">
            <div class="d-flex align-items-center gap-3">
              <div class="mini-icon bg-success-subtle text-success">↓</div>
              <div>
                <div class="text-body-secondary small" style="font-size:.92rem;">Tenho hoje</div>
                <div class="fw-bold text-success" style="font-size:1.65rem; line-height:1.1;">R$ <?= number_format($overall['current_balance'], 2, ',', '.') ?></div>
                <div class="text-body-secondary" style="font-size:.95rem;">Saldo disponível</div>
              </div>
            </div>
          </div>
        </div>
        <div class="col-12 col-md-6 col-xxl-3">
          <div class="metric-card p-3 h-100" style="min-height:140px;">
            <div class="d-flex align-items-center gap-3">
              <div class="mini-icon bg-primary-subtle text-primary">↗</div>
              <div>
                <div class="text-body-secondary small" style="font-size:.92rem;">Vou receber</div>
                <div class="fw-bold text-primary" style="font-size:1.65rem; line-height:1.1;">R$ <?= number_format($overall['income_received'] + $overall['income_planned'], 2, ',', '.') ?></div>
                <div class="text-body-secondary" style="font-size:.95rem;">Em entradas do mês</div>
              </div>
            </div>
          </div>
        </div>
        <div class="col-12 col-md-6 col-xxl-3">
          <div class="metric-card p-3 h-100" style="min-height:140px;">
            <div class="d-flex align-items-center gap-3">
              <div class="mini-icon bg-warning-subtle text-warning">↓</div>
              <div>
                <div class="text-body-secondary small" style="font-size:.92rem;">Vou gastar</div>
                <div class="fw-bold text-warning" style="font-size:1.65rem; line-height:1.1;">R$ <?= number_format($overall['expense_paid'] + $overall['expense_planned'] + $overall['open_bills'], 2, ',', '.') ?></div>
                <div class="text-body-secondary" style="font-size:.95rem;">Gastos, contas e cartões</div>
              </div>
            </div>
          </div>
        </div>
        <div class="col-12 col-md-6 col-xxl-3">
          <div class="metric-card p-3 h-100" style="min-height:140px;">
            <div class="d-flex align-items-center gap-3">
              <div class="mini-icon bg-purple-subtle text-purple" style="background:rgba(147,51,234,.09); color:#7c3aed;">=</div>
              <div>
                <div class="text-body-secondary small" style="font-size:.92rem;">Sobra prevista</div>
                <div class="fw-bold" style="font-size:1.65rem; line-height:1.1; color:#7c3aed;">R$ <?= number_format($overall['projected'], 2, ',', '.') ?></div>
                <div class="text-body-secondary" style="font-size:.95rem;">No fim do mês</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-12 col-xl-7">
          <div class="metric-card p-3 p-lg-4 h-100">
            <div class="section-title">
              <div>
                <h2 class="fw-bold mb-1" style="font-size:1.2rem;">Entradas x Saídas do mês</h2>
                <div class="text-body-secondary small" style="font-size:.93rem;">Resumo visual do comportamento de <?= e($selectedMonthLabel) ?></div>
              </div>
              <span class="badge rounded-pill text-bg-light border">Resultado: R$ <?= number_format($overall['projected'], 2, ',', '.') ?></span>
            </div>
            <?php
              $daysInMonth = max(1, (int) date('t', strtotime($monthStart)));
              $incomeSeries = array_fill(1, $daysInMonth, 0.0);
              $expenseSeries = array_fill(1, $daysInMonth, 0.0);
              foreach ($graphRows as $row) {
                  $day = (int) substr($row['day_key'], 8, 2);
                  if ($day >= 1 && $day <= $daysInMonth) {
                      $incomeSeries[$day] = (float) ($row['income_amount'] ?? 0);
                      $expenseSeries[$day] = (float) ($row['expense_amount'] ?? 0);
                  }
              }
              $incomeCumulative = [];
              $expenseCumulative = [];
              $runningIncome = 0;
              $runningExpense = 0;
              foreach (range(1, $daysInMonth) as $day) {
                  $runningIncome += $incomeSeries[$day];
                  $runningExpense += $expenseSeries[$day];
                  $incomeCumulative[$day] = $runningIncome;
                  $expenseCumulative[$day] = $runningExpense;
              }
              $maxGraph = max(1, max($incomeCumulative ?: [0]), max($expenseCumulative ?: [0]));
              $buildPath = function (array $series) use ($daysInMonth, $maxGraph): string {
                  $points = [];
                  foreach (range(1, $daysInMonth) as $day) {
                      $x = (($day - 1) / max(1, $daysInMonth - 1)) * 100;
                      $y = 100 - (($series[$day] ?? 0) / $maxGraph) * 100;
                      $points[] = sprintf('%.2f,%.2f', $x, $y);
                  }
                  return 'M' . implode(' L', $points);
              };
            ?>
            <div class="row g-3 align-items-center">
              <div class="col-12 col-md-4">
                <div class="d-grid gap-3">
                  <div>
                    <div class="small text-body-secondary">Entradas</div>
                    <div class="fw-bold fs-5 text-success">R$ <?= number_format($overall['income_received'] + $overall['income_planned'], 2, ',', '.') ?></div>
                  </div>
                  <div>
                    <div class="small text-body-secondary">Saídas</div>
                    <div class="fw-bold fs-5 text-primary">R$ <?= number_format($overall['expense_paid'] + $overall['expense_planned'], 2, ',', '.') ?></div>
                  </div>
                  <div class="metric-card p-3" style="box-shadow:none;">
                    <div class="small text-body-secondary">Resultado</div>
                    <div class="fw-bold fs-5 text-success">R$ <?= number_format($overall['projected'], 2, ',', '.') ?></div>
                  </div>
                </div>
              </div>
              <div class="col-12 col-md-8">
                <div class="chart-wrap">
                  <div class="chart-grid"></div>
                  <svg class="chart-line" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
                    <path d="<?= e($buildPath($incomeCumulative)) ?>" fill="none" stroke="#16a34a" stroke-width="2.8" stroke-linecap="round"/>
                    <path d="<?= e($buildPath($expenseCumulative)) ?>" fill="none" stroke="#2563eb" stroke-width="2.8" stroke-linecap="round"/>
                  </svg>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="col-12 col-xl-5">
          <div class="metric-card p-3 p-lg-4 h-100">
            <div class="section-title">
              <div>
                <h2 class="fw-bold mb-1" style="font-size:1.2rem;">O que fazer agora</h2>
                <div class="text-body-secondary small" style="font-size:.93rem;">Próximas ações sugeridas</div>
              </div>
              <span class="badge rounded-pill text-bg-light border"><?= e(ucfirst($overallRisk)) ?></span>
            </div>
            <div class="d-grid gap-2">
              <?php foreach ($recommendations as $recommendation): ?>
                <div class="action-item">
                  <div class="mini-icon" style="<?= $recommendation['tone'] === 'danger' ? 'background:rgba(225,29,72,.09);color:#be123c;' : ($recommendation['tone'] === 'warning' ? 'background:rgba(245,158,11,.09);color:#d97706;' : ($recommendation['tone'] === 'success' ? 'background:rgba(22,163,74,.09);color:#16a34a;' : 'background:rgba(37,99,235,.09);color:#2563eb;')) ?>">•</div>
                  <div class="flex-grow-1">
                    <div class="fw-semibold"><?= e($recommendation['title']) ?></div>
                    <div class="text-body-secondary small"><?= e($recommendation['meta']) ?></div>
                  </div>
                  <div class="action-arrow">›</div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-12 col-lg-6">
          <div class="metric-card p-3 p-lg-4 h-100">
            <div class="section-title">
              <h2 class="fw-bold mb-0" style="font-size:1.15rem;">Cartões</h2>
              <a href="<?= e(base_path('cards.php?instance_id=' . (int) ($instances[0]['id'] ?? 0))) ?>">Ver cartões</a>
            </div>
            <div class="d-grid gap-3">
              <?php foreach (array_slice($instanceSummaries, 0, 1) as $instance): ?>
                <div class="metric-card p-3">
                  <div class="d-flex justify-content-between align-items-start gap-3">
                    <div>
                      <div class="fw-semibold"><?= e($instance['name']) ?></div>
                      <div class="text-body-secondary small">Comprometimento futuro</div>
                    </div>
                    <span class="badge text-bg-light border">R$ <?= number_format((float) $instance['open_bills'], 2, ',', '.') ?></span>
                  </div>
                  <div class="progress mt-3" style="height:8px;">
                    <div class="progress-bar" style="width: <?= min(100, max(0, (int) round(($overall['open_bills'] / max(1, $overall['current_balance'] ?: 1)) * 100))) ?>%"></div>
                  </div>
                </div>
              <?php endforeach; ?>
              <?php if (!$instances): ?><div class="text-body-secondary">Nenhuma instância disponível.</div><?php endif; ?>
            </div>
          </div>
        </div>
        <div class="col-12 col-lg-6">
          <div class="metric-card p-3 p-lg-4 h-100">
            <div class="section-title">
              <h2 class="fw-bold mb-0" style="font-size:1.15rem;">Recursos avançados</h2>
              <span class="badge rounded-pill text-bg-light border">Ocultos para simplificar</span>
            </div>
            <div class="row g-2">
              <?php
              $advancedLinks = [
              ['label' => 'Fluxo de caixa', 'href' => base_path('cashflow.php?instance_id=' . (int) ($instances[0]['id'] ?? 0))],
              ['label' => 'Orçamentos', 'href' => base_path('budgets.php?instance_id=' . (int) ($instances[0]['id'] ?? 0))],
              ['label' => 'Metas', 'href' => base_path('goals.php?instance_id=' . (int) ($instances[0]['id'] ?? 0))],
              ['label' => 'Planejamento', 'href' => base_path('calendar.php?instance_id=' . (int) ($instances[0]['id'] ?? 0))],
                ['label' => 'Conteúdo', 'href' => base_path('conteudo.php?instance_id=' . (int) ($instances[0]['id'] ?? 0))],
              ];
              foreach ($advancedLinks as $link):
              ?>
                <div class="col-6 col-xl-4">
                  <a class="metric-card p-3 text-center d-block h-100" href="<?= e($link['href']) ?>">
                    <div class="mini-icon mx-auto mb-2">+</div>
                    <div class="fw-semibold"><?= e($link['label']) ?></div>
                  </a>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-12 col-lg-4">
          <div class="metric-card p-3 p-lg-4 h-100">
            <div class="section-title">
              <h2 class="fw-bold mb-0" style="font-size:1.05rem;">Instâncias</h2>
              <span class="badge rounded-pill text-bg-light border"><?= count($instances) ?></span>
            </div>
            <div class="list-group list-group-flush rounded-4 overflow-hidden">
              <?php foreach ($instanceSummaries as $instance): ?>
                <div class="list-group-item d-flex justify-content-between align-items-center">
                  <div>
                    <div class="fw-semibold"><?= e($instance['name']) ?></div>
                    <div class="text-body-secondary small">Risco: <?= e($instance['risk']) ?></div>
                  </div>
                  <a class="btn btn-sm btn-outline-primary" href="<?= e(base_path('instance.php?id=' . (int) $instance['id'])) ?>">Abrir</a>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <div class="col-12 col-lg-8">
          <div class="metric-card p-3 p-lg-4 h-100">
            <div class="section-title">
              <h2 class="fw-bold mb-0" style="font-size:1.05rem;">Convites pendentes</h2>
              <span class="badge rounded-pill text-bg-light border">Acessos compartilhados</span>
            </div>
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
    </main>
  </div>
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
