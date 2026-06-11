<?php
require __DIR__ . '/bootstrap.php';

$instanceId = (int) ($_GET['instance_id'] ?? $_POST['instance_id'] ?? 0);
if (!$instanceId) { http_response_code(400); exit('Instância obrigatória.'); }
$userId = $auth->requireInstanceAccess($instanceId);
$instance = $auth->instanceById($instanceId);
if (!$instance) { http_response_code(404); exit('Instância não encontrada.'); }

function dt_now(): string { return date('Y-m-d H:i:s'); }
function post_value(string $key, mixed $default = null): mixed { return $_POST[$key] ?? $default; }

$message = $error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post_value('action', '');
    try {
        switch ($action) {
            case 'create_center':
                $pdo->prepare('INSERT INTO financial_centers (instance_id, name, type, active, created_at, updated_at) VALUES (?, ?, ?, 1, ?, ?)')->execute([$instanceId, trim((string) post_value('name')), trim((string) post_value('type', 'personal')), dt_now(), dt_now()]);
                $message = 'Área criada.';
                break;
            case 'create_category':
                $parentId = post_value('parent_id'); $parentId = $parentId === '' || $parentId === null ? null : (int) $parentId;
                $pdo->prepare('INSERT INTO financial_categories (instance_id, name, type, parent_id, active, created_at, updated_at) VALUES (?, ?, ?, ?, 1, ?, ?)')->execute([$instanceId, trim((string) post_value('name')), trim((string) post_value('type', 'expense')), $parentId, dt_now(), dt_now()]);
                $message = 'Tipo criado.';
                break;
            case 'create_account':
                $pdo->prepare('INSERT INTO financial_accounts (instance_id, name, type, bank_name, initial_balance, current_balance, credit_limit, closing_day, due_day, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)')->execute([$instanceId, trim((string) post_value('name')), trim((string) post_value('type', 'cash')), trim((string) post_value('bank_name', '')), (float) post_value('initial_balance', 0), (float) post_value('current_balance', 0), (float) post_value('credit_limit', 0), post_value('closing_day') === '' ? null : (int) post_value('closing_day'), post_value('due_day') === '' ? null : (int) post_value('due_day'), dt_now(), dt_now()]);
                $message = 'Conta criada.';
                break;
            case 'create_transaction':
                if ((int) post_value('center_id', 0) === 0) throw new RuntimeException('Não permitir lançamento sem área.');
                $pdo->prepare('INSERT INTO financial_transactions (instance_id, transaction_date, due_date, paid_date, description, amount, type, status, account_id, destination_account_id, center_id, category_id, payment_method, responsible_person, client_id, lead_id, appointment_id, notes, source, external_provider, external_account_id, external_transaction_id, sync_status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$instanceId, trim((string) post_value('transaction_date')), post_value('due_date') ?: null, post_value('paid_date') ?: null, trim((string) post_value('description')), (float) post_value('amount', 0), trim((string) post_value('type', 'expense')), trim((string) post_value('status', 'planned')), (int) post_value('account_id'), post_value('destination_account_id') === '' ? null : (int) post_value('destination_account_id'), (int) post_value('center_id'), (int) post_value('category_id'), trim((string) post_value('payment_method', 'other')), trim((string) post_value('responsible_person', '')), null, null, null, trim((string) post_value('notes', '')), trim((string) post_value('source', 'manual')), null, null, null, 'not_synced', dt_now(), dt_now()]);
                $message = 'Lançamento criado.';
                break;
            case 'create_recurring':
                $pdo->prepare('INSERT INTO financial_recurring (instance_id, description, amount, type, frequency, due_day, start_date, end_date, account_id, center_id, category_id, payment_method, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)')->execute([$instanceId, trim((string) post_value('description')), (float) post_value('amount', 0), trim((string) post_value('type', 'expense')), trim((string) post_value('frequency', 'monthly')), post_value('due_day') === '' ? null : (int) post_value('due_day'), trim((string) post_value('start_date')), post_value('end_date') ?: null, (int) post_value('account_id'), (int) post_value('center_id'), (int) post_value('category_id'), trim((string) post_value('payment_method', 'other')), dt_now(), dt_now()]);
                $message = 'Recorrência criada.';
                break;
            case 'create_budget':
                $pdo->prepare('INSERT INTO financial_budgets (instance_id, month, year, center_id, category_id, planned_amount, alert_percent, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$instanceId, (int) post_value('month'), (int) post_value('year'), (int) post_value('center_id'), post_value('category_id') === '' ? null : (int) post_value('category_id'), (float) post_value('planned_amount', 0), (int) post_value('alert_percent', 80), dt_now(), dt_now()]);
                $message = 'Orçamento criado.';
                break;
            case 'create_goal':
                $pdo->prepare('INSERT INTO financial_goals (instance_id, name, target_amount, current_amount, deadline, center_id, priority, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)')->execute([$instanceId, trim((string) post_value('name')), (float) post_value('target_amount', 0), (float) post_value('current_amount', 0), post_value('deadline') ?: null, (int) post_value('center_id'), (int) post_value('priority', 3), dt_now(), dt_now()]);
                $message = 'Meta criada.';
                break;
            case 'create_rule':
                $pdo->prepare('INSERT INTO financial_rules (instance_id, match_text, match_type, transaction_type, center_id, category_id, account_id, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)')->execute([$instanceId, trim((string) post_value('match_text')), trim((string) post_value('match_type', 'contains')), post_value('transaction_type') ?: null, (int) post_value('center_id'), (int) post_value('category_id'), post_value('account_id') === '' ? null : (int) post_value('account_id'), dt_now(), dt_now()]);
                $message = 'Regra criada.';
                break;
            case 'create_card':
                $pdo->prepare('INSERT INTO credit_cards (instance_id, account_id, name, bank_name, credit_limit, closing_day, due_day, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)')->execute([$instanceId, (int) post_value('card_account_id'), trim((string) post_value('card_name')), trim((string) post_value('card_bank_name', '')), (float) post_value('card_credit_limit', 0), post_value('card_closing_day') === '' ? null : (int) post_value('card_closing_day'), post_value('card_due_day') === '' ? null : (int) post_value('card_due_day'), dt_now(), dt_now()]);
                $message = 'Cartão criado.';
                break;
            case 'create_card_purchase':
                $installments = max(1, (int) post_value('installments_count', 1));
                $purchaseDate = (string) post_value('purchase_date', date('Y-m-d'));
                $totalAmount = (float) post_value('purchase_total_amount', 0);
                $pdo->prepare('INSERT INTO credit_card_purchases (instance_id, card_id, description, total_amount, purchase_date, installments_count, center_id, category_id, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$instanceId, (int) post_value('purchase_card_id'), trim((string) post_value('purchase_description')), $totalAmount, $purchaseDate, $installments, (int) post_value('purchase_center_id'), (int) post_value('purchase_category_id'), trim((string) post_value('purchase_notes', '')), dt_now(), dt_now()]);
                $purchaseId = (int) $pdo->lastInsertId();
                $cardStmt = $pdo->prepare('SELECT due_day FROM credit_cards WHERE id = ? AND instance_id = ?'); $cardStmt->execute([(int) post_value('purchase_card_id'), $instanceId]); $dueDay = max(1, (int) ($cardStmt->fetchColumn() ?: 1));
                $installmentAmount = round($totalAmount / $installments, 2);
                $insStmt = $pdo->prepare('INSERT INTO credit_card_installments (purchase_id, installment_number, due_date, amount, status, transaction_id, created_at, updated_at) VALUES (?, ?, ?, ?, "planned", NULL, ?, ?)');
                for ($i = 1; $i <= $installments; $i++) { $due = new DateTime($purchaseDate); $due->modify('first day of next month'); $due->modify('+' . ($i - 1) . ' month'); $due->setDate((int) $due->format('Y'), (int) $due->format('m'), min($dueDay, (int) $due->format('t'))); $insStmt->execute([$purchaseId, $i, $due->format('Y-m-d'), $installmentAmount, dt_now(), dt_now()]); }
                $message = 'Compra do cartão criada com parcelas.';
                break;
        }
    } catch (Throwable $e) { $error = $e->getMessage(); }
}

$centers = $pdo->query('SELECT * FROM financial_centers WHERE instance_id = ' . (int) $instanceId . ' ORDER BY name')->fetchAll();
$categories = $pdo->query('SELECT * FROM financial_categories WHERE instance_id = ' . (int) $instanceId . ' ORDER BY type, name')->fetchAll();
$accounts = $pdo->query('SELECT * FROM financial_accounts WHERE instance_id = ' . (int) $instanceId . ' ORDER BY name')->fetchAll();
$transactions = $pdo->query('SELECT * FROM financial_transactions WHERE instance_id = ' . (int) $instanceId . ' ORDER BY transaction_date DESC, id DESC LIMIT 20')->fetchAll();
$recurring = $pdo->query('SELECT * FROM financial_recurring WHERE instance_id = ' . (int) $instanceId . ' ORDER BY id DESC')->fetchAll();
$budgets = $pdo->query('SELECT * FROM financial_budgets WHERE instance_id = ' . (int) $instanceId . ' ORDER BY year DESC, month DESC')->fetchAll();
$goals = $pdo->query('SELECT * FROM financial_goals WHERE instance_id = ' . (int) $instanceId . ' ORDER BY priority ASC, id DESC')->fetchAll();
$rules = $pdo->query('SELECT * FROM financial_rules WHERE instance_id = ' . (int) $instanceId . ' ORDER BY id DESC')->fetchAll();
$cards = $pdo->query('SELECT * FROM credit_cards WHERE instance_id = ' . (int) $instanceId . ' ORDER BY id DESC')->fetchAll();
$cardPurchases = $pdo->query('SELECT p.*, c.name AS card_name FROM credit_card_purchases p INNER JOIN credit_cards c ON c.id = p.card_id WHERE p.instance_id = ' . (int) $instanceId . ' ORDER BY p.purchase_date DESC, p.id DESC')->fetchAll();
$cardBills = $pdo->query('SELECT b.*, c.name AS card_name FROM credit_card_bills b INNER JOIN credit_cards c ON c.id = b.card_id WHERE b.instance_id = ' . (int) $instanceId . ' ORDER BY b.reference_year DESC, b.reference_month DESC')->fetchAll();

$cardStatsStmt = $pdo->prepare('SELECT c.name, c.credit_limit, COALESCE((SELECT SUM(p.total_amount) FROM credit_card_purchases p WHERE p.card_id = c.id), 0) AS spent_total, COALESCE((SELECT SUM(ci.amount) FROM credit_card_installments ci INNER JOIN credit_card_purchases p ON p.id = ci.purchase_id WHERE p.card_id = c.id AND ci.status IN ("planned", "pending")), 0) AS future_commitment FROM credit_cards c WHERE c.instance_id = ? ORDER BY c.id DESC');
$cardStatsStmt->execute([$instanceId]);
$cardStats = $cardStatsStmt->fetchAll();
$cardInstallmentsStmt = $pdo->prepare('SELECT ci.*, c.name AS card_name, p.description AS purchase_description FROM credit_card_installments ci INNER JOIN credit_card_purchases p ON p.id = ci.purchase_id INNER JOIN credit_cards c ON c.id = p.card_id WHERE p.instance_id = ? ORDER BY ci.due_date ASC, ci.id ASC LIMIT 50');
$cardInstallmentsStmt->execute([$instanceId]);
$cardInstallments = $cardInstallmentsStmt->fetchAll();
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Configurações financeiras - <?= e($instance['name']) ?></title>
<?= bootstrap_assets() ?>
<link rel="stylesheet" href="<?= e(base_path('assets/ui.css')) ?>">
</head>
<body class="bg-body-tertiary">
<div class="container py-3 py-lg-4">
  <div class="card shadow-sm border-0 rounded-4 mb-3">
    <div class="card-body p-3 p-lg-4 d-flex flex-column flex-lg-row justify-content-between gap-3 align-items-start align-items-lg-center">
      <div>
        <span class="badge rounded-pill text-bg-primary-subtle text-primary-emphasis mb-2">Configurações · manutenção avançada</span>
        <h1 class="h2 fw-bold mb-1"><?= e($instance['name']) ?></h1>
        <div class="text-body-secondary">Área administrativa, configuração inicial e acesso avançado.</div>
      </div>
      <a class="btn btn-outline-secondary" href="<?= e(base_path('instance.php?id=' . $instanceId)) ?>">Voltar para a instância</a>
    </div>
  </div>

  <?php if ($message): ?><div class="alert alert-success rounded-4"><?= e($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger rounded-4"><?= e($error) ?></div><?php endif; ?>

  <div class="row g-3 mb-3">
    <div class="col-12 col-lg-8">
      <div class="card shadow-sm border-0 rounded-4 h-100">
        <div class="card-body p-3 p-lg-4">
          <span class="badge rounded-pill text-bg-light border mb-2">Modo básico</span>
          <h2 class="h4 fw-bold mb-2">Abra só o que precisa</h2>
          <p class="text-body-secondary mb-3">Se você só quiser operar o essencial, use os lançamentos, cartões e visão rápida. O restante continua disponível abaixo.</p>
          <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-primary" href="<?= e(base_path('transactions.php?instance_id=' . $instanceId)) ?>">Criar lançamento</a>
            <a class="btn btn-outline-primary" href="<?= e(base_path('cards.php?instance_id=' . $instanceId)) ?>">Ver cartões</a>
            <a class="btn btn-outline-secondary" href="<?= e(base_path('dashboard.php')) ?>">Voltar ao início</a>
          </div>
        </div>
      </div>
    </div>
    <div class="col-12 col-lg-4">
      <div class="card shadow-sm border-0 rounded-4 h-100">
        <div class="card-body p-3 p-lg-4">
          <h2 class="h5 fw-bold mb-3">Acesso rápido</h2>
          <div class="list-group list-group-flush rounded-4 overflow-hidden">
            <a class="list-group-item list-group-item-action" href="<?= e(base_path('transactions.php?instance_id=' . $instanceId)) ?>">Novo lançamento</a>
            <a class="list-group-item list-group-item-action" href="<?= e(base_path('cards.php?instance_id=' . $instanceId)) ?>">Cartões</a>
            <a class="list-group-item list-group-item-action" href="<?= e(base_path('cashflow.php?instance_id=' . $instanceId)) ?>">Fluxo de caixa</a>
            <a class="list-group-item list-group-item-action" href="<?= e(base_path('open-finance.php?instance_id=' . $instanceId)) ?>">Open Finance</a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="accordion mb-3" id="finAcc">
    <div class="accordion-item shadow-sm border-0 rounded-4 overflow-hidden mb-3">
      <h2 class="accordion-header"><button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#a1">Áreas e tipos</button></h2>
      <div id="a1" class="accordion-collapse collapse show" data-bs-parent="#finAcc"><div class="accordion-body">
        <form method="post" class="row g-3 mb-3"><input type="hidden" name="action" value="create_center"><input type="hidden" name="instance_id" value="<?= $instanceId ?>"><div class="col-12 col-md-7"><label class="form-label">Nome</label><input type="text" name="name" class="form-control" required></div><div class="col-12 col-md-5"><label class="form-label">Tipo</label><select name="type" class="form-select"><option value="personal">personal</option><option value="business">business</option><option value="reserve">reserve</option><option value="liability">liability</option><option value="tax">tax</option><option value="project">project</option></select></div><div class="col-12"><button class="btn btn-primary" type="submit">Criar área</button></div></form>
        <div class="list-group list-group-flush rounded-4 overflow-hidden"><?php foreach ($centers as $center): ?><div class="list-group-item d-flex justify-content-between align-items-center"><div><div class="fw-semibold"><?= e($center['name']) ?></div><div class="small text-body-secondary"><?= e($center['type']) ?></div></div><span class="badge text-bg-light border"><?= $center['active'] ? 'ativo' : 'inativo' ?></span></div><?php endforeach; ?></div>
      </div></div>
    </div>

    <div class="accordion-item shadow-sm border-0 rounded-4 overflow-hidden mb-3">
      <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a2">Tipos de gasto e entrada</button></h2>
      <div id="a2" class="accordion-collapse collapse" data-bs-parent="#finAcc"><div class="accordion-body">
        <form method="post" class="row g-3 mb-3"><input type="hidden" name="action" value="create_category"><input type="hidden" name="instance_id" value="<?= $instanceId ?>"><div class="col-12 col-md-6"><label class="form-label">Nome</label><input type="text" name="name" class="form-control" required></div><div class="col-12 col-md-6"><label class="form-label">Tipo</label><select name="type" class="form-select"><option value="income">entrada</option><option value="expense">gasto</option><option value="transfer">transferência</option></select></div><div class="col-12"><label class="form-label">Categoria pai</label><select name="parent_id" class="form-select"><option value="">Nenhuma</option><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>"><?= e($category['name']) ?> (<?= e($category['type']) ?>)</option><?php endforeach; ?></select></div><div class="col-12"><button class="btn btn-primary" type="submit">Criar tipo</button></div></form>
        <div class="list-group list-group-flush rounded-4 overflow-hidden"><?php foreach ($categories as $category): ?><div class="list-group-item d-flex justify-content-between align-items-center"><div><div class="fw-semibold"><?= e($category['name']) ?></div><div class="small text-body-secondary"><?= e($category['type']) ?><?= $category['parent_id'] ? ' · subcategoria' : '' ?></div></div><span class="badge text-bg-light border"><?= $category['active'] ? 'ativo' : 'inativo' ?></span></div><?php endforeach; ?></div>
      </div></div>
    </div>

    <div class="accordion-item shadow-sm border-0 rounded-4 overflow-hidden mb-3">
      <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a3">Contas</button></h2>
      <div id="a3" class="accordion-collapse collapse" data-bs-parent="#finAcc"><div class="accordion-body">
        <form method="post" class="row g-3 mb-3"><input type="hidden" name="action" value="create_account"><input type="hidden" name="instance_id" value="<?= $instanceId ?>"><div class="col-12 col-md-4"><label class="form-label">Nome</label><input type="text" name="name" class="form-control" required></div><div class="col-12 col-md-4"><label class="form-label">Tipo</label><select name="type" class="form-select"><option value="cash">cash</option><option value="bank">bank</option><option value="credit_card">credit_card</option><option value="investment">investment</option><option value="wallet">wallet</option></select></div><div class="col-12 col-md-4"><label class="form-label">Banco</label><input type="text" name="bank_name" class="form-control"></div><div class="col-6 col-md-2"><label class="form-label">Saldo inicial</label><input type="number" step="0.01" name="initial_balance" class="form-control" value="0"></div><div class="col-6 col-md-2"><label class="form-label">Saldo atual</label><input type="number" step="0.01" name="current_balance" class="form-control" value="0"></div><div class="col-6 col-md-2"><label class="form-label">Limite</label><input type="number" step="0.01" name="credit_limit" class="form-control" value="0"></div><div class="col-6 col-md-2"><label class="form-label">Fechamento</label><input type="number" name="closing_day" class="form-control" min="1" max="31"></div><div class="col-6 col-md-2"><label class="form-label">Vencimento</label><input type="number" name="due_day" class="form-control" min="1" max="31"></div><div class="col-12 col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100" type="submit">Criar conta</button></div></form>
        <div class="list-group list-group-flush rounded-4 overflow-hidden"><?php foreach ($accounts as $account): ?><div class="list-group-item d-flex justify-content-between align-items-center"><div><div class="fw-semibold"><?= e($account['name']) ?></div><div class="small text-body-secondary"><?= e($account['type']) ?><?= $account['bank_name'] ? ' · ' . e($account['bank_name']) : '' ?></div></div><span class="badge text-bg-light border">R$ <?= number_format((float) $account['current_balance'], 2, ',', '.') ?></span></div><?php endforeach; ?></div>
      </div></div>
    </div>

    <div class="accordion-item shadow-sm border-0 rounded-4 overflow-hidden mb-3">
      <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a4">Lançamentos</button></h2>
      <div id="a4" class="accordion-collapse collapse" data-bs-parent="#finAcc"><div class="accordion-body">
        <form method="post" class="row g-3 mb-3"><input type="hidden" name="action" value="create_transaction"><input type="hidden" name="instance_id" value="<?= $instanceId ?>"><div class="col-12 col-md-4"><label class="form-label">Data</label><input type="date" name="transaction_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div><div class="col-12 col-md-4"><label class="form-label">Vencimento</label><input type="date" name="due_date" class="form-control"></div><div class="col-12 col-md-4"><label class="form-label">Pagamento</label><input type="date" name="paid_date" class="form-control"></div><div class="col-12 col-md-6"><label class="form-label">Descrição</label><input type="text" name="description" class="form-control" required></div><div class="col-12 col-md-3"><label class="form-label">Valor</label><input type="number" step="0.01" name="amount" class="form-control" required></div><div class="col-12 col-md-3"><label class="form-label">Tipo</label><select name="type" class="form-select"><option value="income">entrada</option><option value="expense">gasto</option><option value="transfer">transferência</option></select></div><div class="col-12 col-md-3"><label class="form-label">Status</label><select name="status" class="form-select"><option value="planned">previsto</option><option value="pending">pendente</option><option value="paid">pago</option><option value="overdue">vencido</option><option value="canceled">cancelado</option></select></div><div class="col-12 col-md-3"><label class="form-label">Conta</label><select name="account_id" class="form-select" required><?php foreach ($accounts as $account): ?><option value="<?= (int) $account['id'] ?>"><?= e($account['name']) ?></option><?php endforeach; ?></select></div><div class="col-12 col-md-3"><label class="form-label">Área</label><select name="center_id" class="form-select" required><?php foreach ($centers as $center): ?><option value="<?= (int) $center['id'] ?>"><?= e($center['name']) ?></option><?php endforeach; ?></select></div><div class="col-12 col-md-3"><label class="form-label">Tipo de gasto/entrada</label><select name="category_id" class="form-select" required><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>"><?= e($category['name']) ?></option><?php endforeach; ?></select></div><div class="col-12 col-md-4"><label class="form-label">Forma de pagamento</label><select name="payment_method" class="form-select"><option value="pix">pix</option><option value="cash">cash</option><option value="debit">debit</option><option value="credit_card">credit_card</option><option value="boleto">boleto</option><option value="transfer">transfer</option><option value="other">other</option></select></div><div class="col-12"><button class="btn btn-primary" type="submit">Criar lançamento</button></div></form>
        <div class="list-group list-group-flush rounded-4 overflow-hidden"><?php foreach ($transactions as $transaction): ?><div class="list-group-item d-flex justify-content-between align-items-center"><div><div class="fw-semibold"><?= e($transaction['description']) ?></div><div class="small text-body-secondary"><?= e($transaction['transaction_date']) ?> · <?= e($transaction['status']) ?> · <?= e($transaction['type']) ?></div></div><span class="badge text-bg-light border">R$ <?= number_format((float) $transaction['amount'], 2, ',', '.') ?></span></div><?php endforeach; ?></div>
      </div></div>
    </div>

    <div class="accordion-item shadow-sm border-0 rounded-4 overflow-hidden mb-3">
      <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a5">Recorrências e metas</button></h2>
      <div id="a5" class="accordion-collapse collapse" data-bs-parent="#finAcc"><div class="accordion-body">
        <form method="post" class="row g-3 mb-3"><input type="hidden" name="action" value="create_recurring"><input type="hidden" name="instance_id" value="<?= $instanceId ?>"><div class="col-12 col-md-6"><label class="form-label">Descrição</label><input type="text" name="description" class="form-control" required></div><div class="col-12 col-md-6"><label class="form-label">Valor</label><input type="number" step="0.01" name="amount" class="form-control" required></div><div class="col-12 col-md-6"><label class="form-label">Tipo</label><select name="type" class="form-select"><option value="income">entrada</option><option value="expense">gasto</option><option value="transfer">transferência</option></select></div><div class="col-12 col-md-6"><label class="form-label">Frequência</label><select name="frequency" class="form-select"><option value="weekly">semanal</option><option value="monthly">mensal</option><option value="yearly">anual</option></select></div><div class="col-6 col-md-3"><label class="form-label">Vencimento</label><input type="number" name="due_day" class="form-control" min="1" max="31"></div><div class="col-6 col-md-3"><label class="form-label">Início</label><input type="date" name="start_date" class="form-control" value="<?= date('Y-m-d') ?>"></div><div class="col-6 col-md-3"><label class="form-label">Fim</label><input type="date" name="end_date" class="form-control"></div><div class="col-6 col-md-3"><label class="form-label">Conta</label><select name="account_id" class="form-select"><?php foreach ($accounts as $account): ?><option value="<?= (int) $account['id'] ?>"><?= e($account['name']) ?></option><?php endforeach; ?></select></div><div class="col-12 col-md-4"><label class="form-label">Área</label><select name="center_id" class="form-select"><?php foreach ($centers as $center): ?><option value="<?= (int) $center['id'] ?>"><?= e($center['name']) ?></option><?php endforeach; ?></select></div><div class="col-12 col-md-4"><label class="form-label">Tipo de gasto/entrada</label><select name="category_id" class="form-select"><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>"><?= e($category['name']) ?></option><?php endforeach; ?></select></div><div class="col-12"><button class="btn btn-primary" type="submit">Criar recorrência</button></div></form>
        <div class="list-group list-group-flush rounded-4 overflow-hidden"><?php foreach ($recurring as $item): ?><div class="list-group-item d-flex justify-content-between align-items-center"><div><div class="fw-semibold"><?= e($item['description']) ?></div><div class="small text-body-secondary"><?= e($item['frequency']) ?> · <?= e($item['type']) ?></div></div><span class="badge text-bg-light border">R$ <?= number_format((float) $item['amount'], 2, ',', '.') ?></span></div><?php endforeach; ?></div>
      </div></div>
    </div>

    <div class="accordion-item shadow-sm border-0 rounded-4 overflow-hidden mb-3">
      <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a6">Orçamentos, metas e automações</button></h2>
      <div id="a6" class="accordion-collapse collapse" data-bs-parent="#finAcc"><div class="accordion-body">
        <div class="row g-3">
          <div class="col-12 col-lg-4"><div class="card border-0 bg-body-tertiary rounded-4 h-100"><div class="card-body"><h3 class="h5 fw-bold">Orçamento</h3><form method="post" class="vstack gap-2"><input type="hidden" name="action" value="create_budget"><input type="hidden" name="instance_id" value="<?= $instanceId ?>"><input type="number" class="form-control" name="month" min="1" max="12" placeholder="Mês" required><input type="number" class="form-control" name="year" value="<?= date('Y') ?>" placeholder="Ano" required><select name="center_id" class="form-select"><?php foreach ($centers as $center): ?><option value="<?= (int) $center['id'] ?>"><?= e($center['name']) ?></option><?php endforeach; ?></select><input type="number" class="form-control" step="0.01" name="planned_amount" value="0" placeholder="Planejado"><input type="number" class="form-control" name="alert_percent" value="80" placeholder="Alerta %"><button class="btn btn-primary" type="submit">Criar orçamento</button></form></div></div></div>
          <div class="col-12 col-lg-4"><div class="card border-0 bg-body-tertiary rounded-4 h-100"><div class="card-body"><h3 class="h5 fw-bold">Meta</h3><form method="post" class="vstack gap-2"><input type="hidden" name="action" value="create_goal"><input type="hidden" name="instance_id" value="<?= $instanceId ?>"><input type="text" class="form-control" name="name" placeholder="Nome" required><input type="number" class="form-control" step="0.01" name="target_amount" placeholder="Alvo" required><input type="number" class="form-control" step="0.01" name="current_amount" value="0" placeholder="Atual"><input type="date" class="form-control" name="deadline"><select name="center_id" class="form-select"><?php foreach ($centers as $center): ?><option value="<?= (int) $center['id'] ?>"><?= e($center['name']) ?></option><?php endforeach; ?></select><input type="number" class="form-control" name="priority" value="3" placeholder="Prioridade"><button class="btn btn-primary" type="submit">Criar meta</button></form></div></div></div>
          <div class="col-12 col-lg-4"><div class="card border-0 bg-body-tertiary rounded-4 h-100"><div class="card-body"><h3 class="h5 fw-bold">Regras</h3><form method="post" class="vstack gap-2"><input type="hidden" name="action" value="create_rule"><input type="hidden" name="instance_id" value="<?= $instanceId ?>"><input type="text" class="form-control" name="match_text" placeholder="Texto alvo" required><select name="match_type" class="form-select"><option value="contains">contains</option><option value="starts_with">starts_with</option><option value="equals">equals</option><option value="regex">regex</option></select><input type="text" class="form-control" name="transaction_type" placeholder="income, expense, transfer"><select name="center_id" class="form-select"><?php foreach ($centers as $center): ?><option value="<?= (int) $center['id'] ?>"><?= e($center['name']) ?></option><?php endforeach; ?></select><select name="category_id" class="form-select"><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>"><?= e($category['name']) ?></option><?php endforeach; ?></select><select name="account_id" class="form-select"><option value="">Sem conta</option><?php foreach ($accounts as $account): ?><option value="<?= (int) $account['id'] ?>"><?= e($account['name']) ?></option><?php endforeach; ?></select><button class="btn btn-primary" type="submit">Criar regra</button></form></div></div></div>
        </div>
        <div class="row g-3 mt-3">
          <div class="col-12 col-lg-4"><div class="card border-0 bg-body-tertiary rounded-4 h-100"><div class="card-body"><h3 class="h5 fw-bold">Orçamentos</h3><div class="list-group list-group-flush rounded-4 overflow-hidden"><?php foreach ($budgets as $budget): ?><div class="list-group-item d-flex justify-content-between align-items-center"><div class="small"><?= (int) $budget['month'] ?>/<?= (int) $budget['year'] ?></div><span class="badge text-bg-light border">R$ <?= number_format((float) $budget['planned_amount'], 2, ',', '.') ?></span></div><?php endforeach; ?></div></div></div></div>
          <div class="col-12 col-lg-4"><div class="card border-0 bg-body-tertiary rounded-4 h-100"><div class="card-body"><h3 class="h5 fw-bold">Metas</h3><div class="list-group list-group-flush rounded-4 overflow-hidden"><?php foreach ($goals as $goal): ?><div class="list-group-item d-flex justify-content-between align-items-center"><div><div class="fw-semibold"><?= e($goal['name']) ?></div><div class="small text-body-secondary">Meta financeira</div></div><span class="badge text-bg-light border">R$ <?= number_format((float) $goal['current_amount'], 2, ',', '.') ?> / <?= number_format((float) $goal['target_amount'], 2, ',', '.') ?></span></div><?php endforeach; ?></div></div></div></div>
          <div class="col-12 col-lg-4"><div class="card border-0 bg-body-tertiary rounded-4 h-100"><div class="card-body"><h3 class="h5 fw-bold">Regras</h3><div class="list-group list-group-flush rounded-4 overflow-hidden"><?php foreach ($rules as $rule): ?><div class="list-group-item d-flex justify-content-between align-items-center"><div><div class="fw-semibold"><?= e($rule['match_text']) ?></div><div class="small text-body-secondary"><?= e($rule['match_type']) ?> · <?= e((string) $rule['transaction_type']) ?></div></div><span class="badge text-bg-light border">regra</span></div><?php endforeach; ?></div></div></div></div>
        </div>
      </div></div>
    </div>

    <div class="accordion-item shadow-sm border-0 rounded-4 overflow-hidden mb-3">
      <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a7">Cartões de crédito</button></h2>
      <div id="a7" class="accordion-collapse collapse" data-bs-parent="#finAcc"><div class="accordion-body">
        <form method="post" class="row g-3 mb-3"><input type="hidden" name="action" value="create_card"><input type="hidden" name="instance_id" value="<?= $instanceId ?>"><div class="col-12 col-md-6"><label class="form-label">Nome do cartão</label><input type="text" name="card_name" class="form-control" required></div><div class="col-12 col-md-6"><label class="form-label">Conta vinculada</label><select name="card_account_id" class="form-select" required><?php foreach ($accounts as $account): ?><option value="<?= (int) $account['id'] ?>"><?= e($account['name']) ?></option><?php endforeach; ?></select></div><div class="col-12 col-md-4"><label class="form-label">Banco</label><input type="text" name="card_bank_name" class="form-control"></div><div class="col-12 col-md-4"><label class="form-label">Limite de crédito</label><input type="number" step="0.01" name="card_credit_limit" class="form-control" value="0"></div><div class="col-6 col-md-2"><label class="form-label">Fechamento</label><input type="number" name="card_closing_day" class="form-control" min="1" max="31"></div><div class="col-6 col-md-2"><label class="form-label">Vencimento</label><input type="number" name="card_due_day" class="form-control" min="1" max="31"></div><div class="col-12"><button class="btn btn-primary" type="submit">Criar cartão</button></div></form>
        <div class="list-group list-group-flush rounded-4 overflow-hidden"><?php foreach ($cards as $card): ?><div class="list-group-item d-flex justify-content-between align-items-center"><div><div class="fw-semibold"><?= e($card['name']) ?></div><div class="small text-body-secondary"><?= e((string) $card['bank_name']) ?> · conta #<?= (int) $card['account_id'] ?></div></div><span class="badge text-bg-light border">R$ <?= number_format((float) $card['credit_limit'], 2, ',', '.') ?></span></div><?php endforeach; ?><?php if (!$cards): ?><div class="list-group-item text-body-secondary">Nenhum cartão cadastrado ainda.</div><?php endif; ?></div>
      </div></div>
    </div>

    <div class="accordion-item shadow-sm border-0 rounded-4 overflow-hidden mb-3">
      <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a8">Compra parcelada</button></h2>
      <div id="a8" class="accordion-collapse collapse" data-bs-parent="#finAcc"><div class="accordion-body">
        <form method="post" class="row g-3 mb-3"><input type="hidden" name="action" value="create_card_purchase"><input type="hidden" name="instance_id" value="<?= $instanceId ?>"><div class="col-12 col-md-6"><label class="form-label">Cartão</label><select name="purchase_card_id" class="form-select" required><?php foreach ($cards as $card): ?><option value="<?= (int) $card['id'] ?>"><?= e($card['name']) ?></option><?php endforeach; ?></select></div><div class="col-12 col-md-6"><label class="form-label">Descrição</label><input type="text" name="purchase_description" class="form-control" required></div><div class="col-12 col-md-4"><label class="form-label">Valor total</label><input type="number" step="0.01" name="purchase_total_amount" class="form-control" required></div><div class="col-12 col-md-4"><label class="form-label">Data da compra</label><input type="date" name="purchase_date" class="form-control" value="<?= date('Y-m-d') ?>"></div><div class="col-12 col-md-4"><label class="form-label">Parcelas</label><input type="number" name="installments_count" class="form-control" min="1" value="1"></div><div class="col-12 col-md-6"><label class="form-label">Área</label><select name="purchase_center_id" class="form-select" required><?php foreach ($centers as $center): ?><option value="<?= (int) $center['id'] ?>"><?= e($center['name']) ?></option><?php endforeach; ?></select></div><div class="col-12 col-md-6"><label class="form-label">Tipo de gasto/entrada</label><select name="purchase_category_id" class="form-select" required><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>"><?= e($category['name']) ?></option><?php endforeach; ?></select></div><div class="col-12"><label class="form-label">Observações</label><input type="text" name="purchase_notes" class="form-control"></div><div class="col-12"><button class="btn btn-primary" type="submit">Cadastrar compra</button></div></form>
        <div class="list-group list-group-flush rounded-4 overflow-hidden"><?php foreach ($cardPurchases as $purchase): ?><div class="list-group-item d-flex justify-content-between align-items-center"><div><div class="fw-semibold"><?= e($purchase['description']) ?></div><div class="small text-body-secondary"><?= e($purchase['card_name']) ?> · <?= (int) $purchase['installments_count'] ?>x · <?= e($purchase['purchase_date']) ?></div></div><span class="badge text-bg-light border">R$ <?= number_format((float) $purchase['total_amount'], 2, ',', '.') ?></span></div><?php endforeach; ?><?php if (!$cardPurchases): ?><div class="list-group-item text-body-secondary">Sem compras registradas.</div><?php endif; ?></div>
      </div></div>
    </div>

    <div class="accordion-item shadow-sm border-0 rounded-4 overflow-hidden mb-3">
      <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a9">Faturas do cartão</button></h2>
      <div id="a9" class="accordion-collapse collapse" data-bs-parent="#finAcc"><div class="accordion-body"><div class="list-group list-group-flush rounded-4 overflow-hidden"><?php foreach ($cardBills as $bill): ?><div class="list-group-item d-flex justify-content-between align-items-center"><div><div class="fw-semibold"><?= e($bill['card_name']) ?> · <?= (int) $bill['reference_month'] ?>/<?= (int) $bill['reference_year'] ?></div><div class="small text-body-secondary">Vencimento <?= e($bill['due_date']) ?> · Status <?= e($bill['status']) ?></div></div><span class="badge text-bg-light border">R$ <?= number_format((float) $bill['total_amount'], 2, ',', '.') ?></span></div><?php endforeach; ?><?php if (!$cardBills): ?><div class="list-group-item text-body-secondary">Nenhuma fatura gerada ainda.</div><?php endif; ?></div></div></div>
    </div>

    <div class="accordion-item shadow-sm border-0 rounded-4 overflow-hidden mb-3">
      <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a10">Limite e comprometimento</button></h2>
      <div id="a10" class="accordion-collapse collapse" data-bs-parent="#finAcc"><div class="accordion-body"><div class="list-group list-group-flush rounded-4 overflow-hidden"><?php foreach ($cardStats as $stat): ?><?php $used = (float) $stat['spent_total']; $limit = (float) $stat['credit_limit']; $available = $limit - $used; $future = (float) $stat['future_commitment']; ?><div class="list-group-item d-flex justify-content-between align-items-center"><div><div class="fw-semibold"><?= e($stat['name']) ?></div><div class="small text-body-secondary">Usado R$ <?= number_format($used, 2, ',', '.') ?> · Disponível R$ <?= number_format($available, 2, ',', '.') ?></div></div><span class="badge text-bg-light border">Compromisso futuro: R$ <?= number_format($future, 2, ',', '.') ?></span></div><?php endforeach; ?><?php if (!$cardStats): ?><div class="list-group-item text-body-secondary">Ainda não há cartões com métricas para exibir.</div><?php endif; ?></div></div></div>
    </div>

    <div class="accordion-item shadow-sm border-0 rounded-4 overflow-hidden mb-3">
      <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#a11">Parcelas futuras</button></h2>
      <div id="a11" class="accordion-collapse collapse" data-bs-parent="#finAcc"><div class="accordion-body"><div class="list-group list-group-flush rounded-4 overflow-hidden"><?php foreach ($cardInstallments as $installment): ?><div class="list-group-item d-flex justify-content-between align-items-center"><div><div class="fw-semibold"><?= e($installment['card_name']) ?> · <?= e($installment['purchase_description']) ?></div><div class="small text-body-secondary">Parcela <?= (int) $installment['installment_number'] ?> · Vencimento <?= e($installment['due_date']) ?> · <?= e($installment['status']) ?></div></div><span class="badge text-bg-light border">R$ <?= number_format((float) $installment['amount'], 2, ',', '.') ?></span></div><?php endforeach; ?><?php if (!$cardInstallments): ?><div class="list-group-item text-body-secondary">Sem parcelas geradas ainda.</div><?php endif; ?></div></div></div>
    </div>
  </div>
</div>
</body>
</html>
