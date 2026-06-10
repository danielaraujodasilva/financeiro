<?php
require __DIR__ . '/bootstrap.php';

$instanceId = (int) ($_GET['instance_id'] ?? $_POST['instance_id'] ?? 0);
if (!$instanceId) {
    http_response_code(400);
    exit('Instância obrigatória.');
}

$userId = $auth->requireInstanceAccess($instanceId);
$instance = $auth->instanceById($instanceId);
if (!$instance) {
    http_response_code(404);
    exit('Instância não encontrada.');
}

if (!function_exists('dt_now')) {
    function dt_now(): string { return date('Y-m-d H:i:s'); }
}

$message = null;
$error = null;

function post_value(string $key, mixed $default = null): mixed {
    return $_POST[$key] ?? $default;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) post_value('action', '');
    try {
        switch ($action) {
            case 'create_center':
                $stmt = $pdo->prepare('INSERT INTO financial_centers (instance_id, name, type, active, created_at, updated_at) VALUES (?, ?, ?, 1, ?, ?)');
                $stmt->execute([$instanceId, trim((string) post_value('name')), trim((string) post_value('type', 'personal')), dt_now(), dt_now()]);
                $message = 'Centro criado.';
                break;
            case 'create_category':
                $parentId = post_value('parent_id');
                $parentId = $parentId === '' || $parentId === null ? null : (int) $parentId;
                $stmt = $pdo->prepare('INSERT INTO financial_categories (instance_id, name, type, parent_id, active, created_at, updated_at) VALUES (?, ?, ?, ?, 1, ?, ?)');
                $stmt->execute([$instanceId, trim((string) post_value('name')), trim((string) post_value('type', 'expense')), $parentId, dt_now(), dt_now()]);
                $message = 'Categoria criada.';
                break;
            case 'create_account':
                $stmt = $pdo->prepare('INSERT INTO financial_accounts (instance_id, name, type, bank_name, initial_balance, current_balance, credit_limit, closing_day, due_day, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)');
                $stmt->execute([
                    $instanceId,
                    trim((string) post_value('name')),
                    trim((string) post_value('type', 'cash')),
                    trim((string) post_value('bank_name', '')),
                    (float) post_value('initial_balance', 0),
                    (float) post_value('current_balance', 0),
                    (float) post_value('credit_limit', 0),
                    post_value('closing_day') === '' ? null : (int) post_value('closing_day'),
                    post_value('due_day') === '' ? null : (int) post_value('due_day'),
                    dt_now(), dt_now()
                ]);
                $message = 'Conta criada.';
                break;
            case 'create_transaction':
                if ((int) post_value('center_id', 0) === 0) {
                    throw new RuntimeException('Não permitir lançamento sem centro financeiro.');
                }
                $stmt = $pdo->prepare('INSERT INTO financial_transactions (instance_id, transaction_date, due_date, paid_date, description, amount, type, status, account_id, destination_account_id, center_id, category_id, payment_method, responsible_person, client_id, lead_id, appointment_id, notes, source, external_provider, external_account_id, external_transaction_id, sync_status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    $instanceId,
                    trim((string) post_value('transaction_date')),
                    post_value('due_date') ?: null,
                    post_value('paid_date') ?: null,
                    trim((string) post_value('description')),
                    (float) post_value('amount', 0),
                    trim((string) post_value('type', 'expense')),
                    trim((string) post_value('status', 'planned')),
                    (int) post_value('account_id'),
                    post_value('destination_account_id') === '' ? null : (int) post_value('destination_account_id'),
                    (int) post_value('center_id'),
                    (int) post_value('category_id'),
                    trim((string) post_value('payment_method', 'other')),
                    trim((string) post_value('responsible_person', '')),
                    null,
                    null,
                    null,
                    trim((string) post_value('notes', '')),
                    trim((string) post_value('source', 'manual')),
                    null,
                    null,
                    null,
                    'not_synced',
                    dt_now(),
                    dt_now()
                ]);
                $message = 'Lançamento criado.';
                break;
            case 'create_recurring':
                $stmt = $pdo->prepare('INSERT INTO financial_recurring (instance_id, description, amount, type, frequency, due_day, start_date, end_date, account_id, center_id, category_id, payment_method, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)');
                $stmt->execute([
                    $instanceId,
                    trim((string) post_value('description')),
                    (float) post_value('amount', 0),
                    trim((string) post_value('type', 'expense')),
                    trim((string) post_value('frequency', 'monthly')),
                    post_value('due_day') === '' ? null : (int) post_value('due_day'),
                    trim((string) post_value('start_date')),
                    post_value('end_date') ?: null,
                    (int) post_value('account_id'),
                    (int) post_value('center_id'),
                    (int) post_value('category_id'),
                    trim((string) post_value('payment_method', 'other')),
                    dt_now(), dt_now()
                ]);
                $message = 'Recorrência criada.';
                break;
            case 'create_budget':
                $stmt = $pdo->prepare('INSERT INTO financial_budgets (instance_id, month, year, center_id, category_id, planned_amount, alert_percent, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    $instanceId,
                    (int) post_value('month'),
                    (int) post_value('year'),
                    (int) post_value('center_id'),
                    post_value('category_id') === '' ? null : (int) post_value('category_id'),
                    (float) post_value('planned_amount', 0),
                    (int) post_value('alert_percent', 80),
                    dt_now(), dt_now()
                ]);
                $message = 'Orçamento criado.';
                break;
            case 'create_goal':
                $stmt = $pdo->prepare('INSERT INTO financial_goals (instance_id, name, target_amount, current_amount, deadline, center_id, priority, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)');
                $stmt->execute([
                    $instanceId,
                    trim((string) post_value('name')),
                    (float) post_value('target_amount', 0),
                    (float) post_value('current_amount', 0),
                    post_value('deadline') ?: null,
                    (int) post_value('center_id'),
                    (int) post_value('priority', 3),
                    dt_now(), dt_now()
                ]);
                $message = 'Meta criada.';
                break;
            case 'create_rule':
                $stmt = $pdo->prepare('INSERT INTO financial_rules (instance_id, match_text, match_type, transaction_type, center_id, category_id, account_id, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)');
                $stmt->execute([
                    $instanceId,
                    trim((string) post_value('match_text')),
                    trim((string) post_value('match_type', 'contains')),
                    post_value('transaction_type') ?: null,
                    (int) post_value('center_id'),
                    (int) post_value('category_id'),
                    post_value('account_id') === '' ? null : (int) post_value('account_id'),
                    dt_now(), dt_now()
                ]);
                $message = 'Regra criada.';
                break;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$centers = $pdo->query('SELECT * FROM financial_centers WHERE instance_id = ' . (int) $instanceId . ' ORDER BY name')->fetchAll();
$categories = $pdo->query('SELECT * FROM financial_categories WHERE instance_id = ' . (int) $instanceId . ' ORDER BY type, name')->fetchAll();
$accounts = $pdo->query('SELECT * FROM financial_accounts WHERE instance_id = ' . (int) $instanceId . ' ORDER BY name')->fetchAll();
$transactions = $pdo->query('SELECT * FROM financial_transactions WHERE instance_id = ' . (int) $instanceId . ' ORDER BY transaction_date DESC, id DESC LIMIT 20')->fetchAll();
$recurring = $pdo->query('SELECT * FROM financial_recurring WHERE instance_id = ' . (int) $instanceId . ' ORDER BY id DESC')->fetchAll();
$budgets = $pdo->query('SELECT * FROM financial_budgets WHERE instance_id = ' . (int) $instanceId . ' ORDER BY year DESC, month DESC')->fetchAll();
$goals = $pdo->query('SELECT * FROM financial_goals WHERE instance_id = ' . (int) $instanceId . ' ORDER BY priority ASC, id DESC')->fetchAll();
$rules = $pdo->query('SELECT * FROM financial_rules WHERE instance_id = ' . (int) $instanceId . ' ORDER BY id DESC')->fetchAll();
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Financeiro Base - <?= e($instance['name']) ?></title>
<link rel="stylesheet" href="<?= e(base_path('assets/ui.css')) ?>">
</head>
<body>
<div class="wrap">
  <div class="topbar fade-in">
    <div class="brand">
      <div class="mark"></div>
      <div>
        <div class="tag">Fase 1 · Base financeira</div>
        <h1 class="headline"><?= e($instance['name']) ?></h1>
      </div>
    </div>
    <div class="actions">
      <a class="btn btn-secondary" href="<?= e(base_path('instance.php?id=' . $instanceId)) ?>">Voltar para a instância</a>
    </div>
  </div>

  <?php if ($message): ?><div class="toast good"><?= e($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="toast bad"><?= e($error) ?></div><?php endif; ?>

  <div class="grid">
    <div class="card enter">
      <h2>Centros</h2>
      <form method="post" class="split">
        <input type="hidden" name="action" value="create_center">
        <input type="hidden" name="instance_id" value="<?= $instanceId ?>">
        <label>Nome<input type="text" name="name" required></label>
        <label>Tipo
          <select name="type">
            <option value="personal">personal</option>
            <option value="business">business</option>
            <option value="reserve">reserve</option>
            <option value="liability">liability</option>
            <option value="tax">tax</option>
            <option value="project">project</option>
          </select>
        </label>
        <button class="btn btn-primary" type="submit">Criar centro</button>
      </form>
      <div class="list">
        <?php foreach ($centers as $center): ?>
          <div class="member"><div class="meta"><strong><?= e($center['name']) ?></strong><span class="muted"><?= e($center['type']) ?></span></div><span class="tag"><?= $center['active'] ? 'ativo' : 'inativo' ?></span></div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card enter">
      <h2>Categorias</h2>
      <form method="post" class="split">
        <input type="hidden" name="action" value="create_category">
        <input type="hidden" name="instance_id" value="<?= $instanceId ?>">
        <label>Nome<input type="text" name="name" required></label>
        <label>Tipo
          <select name="type">
            <option value="income">income</option>
            <option value="expense">expense</option>
            <option value="transfer">transfer</option>
          </select>
        </label>
        <label>Categoria pai
          <select name="parent_id">
            <option value="">Nenhuma</option>
            <?php foreach ($categories as $category): ?>
              <option value="<?= (int) $category['id'] ?>"><?= e($category['name']) ?> (<?= e($category['type']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </label>
        <button class="btn btn-primary" type="submit">Criar categoria</button>
      </form>
      <div class="list">
        <?php foreach ($categories as $category): ?>
          <div class="member"><div class="meta"><strong><?= e($category['name']) ?></strong><span class="muted"><?= e($category['type']) ?><?= $category['parent_id'] ? ' · subcategoria' : '' ?></span></div><span class="tag"><?= $category['active'] ? 'ativo' : 'inativo' ?></span></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="grid">
    <div class="card enter">
      <h2>Contas</h2>
      <form method="post" class="split">
        <input type="hidden" name="action" value="create_account">
        <input type="hidden" name="instance_id" value="<?= $instanceId ?>">
        <label>Nome<input type="text" name="name" required></label>
        <label>Tipo
          <select name="type">
            <option value="cash">cash</option>
            <option value="bank">bank</option>
            <option value="credit_card">credit_card</option>
            <option value="investment">investment</option>
            <option value="wallet">wallet</option>
          </select>
        </label>
        <label>Banco<input type="text" name="bank_name"></label>
        <label>Saldo inicial<input type="number" step="0.01" name="initial_balance" value="0"></label>
        <label>Saldo atual<input type="number" step="0.01" name="current_balance" value="0"></label>
        <label>Limite de crédito<input type="number" step="0.01" name="credit_limit" value="0"></label>
        <label>Fechamento<input type="number" name="closing_day" min="1" max="31"></label>
        <label>Vencimento<input type="number" name="due_day" min="1" max="31"></label>
        <button class="btn btn-primary" type="submit">Criar conta</button>
      </form>
      <div class="list">
        <?php foreach ($accounts as $account): ?>
          <div class="member"><div class="meta"><strong><?= e($account['name']) ?></strong><span class="muted"><?= e($account['type']) ?><?= $account['bank_name'] ? ' · ' . e($account['bank_name']) : '' ?></span></div><span class="tag">R$ <?= number_format((float) $account['current_balance'], 2, ',', '.') ?></span></div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card enter">
      <h2>Lançamentos</h2>
      <form method="post" class="split">
        <input type="hidden" name="action" value="create_transaction">
        <input type="hidden" name="instance_id" value="<?= $instanceId ?>">
        <label>Data<input type="date" name="transaction_date" value="<?= date('Y-m-d') ?>" required></label>
        <label>Vencimento<input type="date" name="due_date"></label>
        <label>Pagamento<input type="date" name="paid_date"></label>
        <label>Descrição<input type="text" name="description" required></label>
        <label>Valor<input type="number" step="0.01" name="amount" required></label>
        <label>Tipo
          <select name="type">
            <option value="income">income</option>
            <option value="expense">expense</option>
            <option value="transfer">transfer</option>
          </select>
        </label>
        <label>Status
          <select name="status">
            <option value="planned">planned</option>
            <option value="pending">pending</option>
            <option value="paid">paid</option>
            <option value="overdue">overdue</option>
            <option value="canceled">canceled</option>
          </select>
        </label>
        <label>Conta
          <select name="account_id" required>
            <?php foreach ($accounts as $account): ?>
              <option value="<?= (int) $account['id'] ?>"><?= e($account['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Centro
          <select name="center_id" required>
            <?php foreach ($centers as $center): ?>
              <option value="<?= (int) $center['id'] ?>"><?= e($center['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Categoria
          <select name="category_id" required>
            <?php foreach ($categories as $category): ?>
              <option value="<?= (int) $category['id'] ?>"><?= e($category['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Forma pagamento
          <select name="payment_method">
            <option value="pix">pix</option>
            <option value="cash">cash</option>
            <option value="debit">debit</option>
            <option value="credit_card">credit_card</option>
            <option value="boleto">boleto</option>
            <option value="transfer">transfer</option>
            <option value="other">other</option>
          </select>
        </label>
        <button class="btn btn-primary" type="submit">Criar lançamento</button>
      </form>
      <div class="list">
        <?php foreach ($transactions as $transaction): ?>
          <div class="member"><div class="meta"><strong><?= e($transaction['description']) ?></strong><span class="muted"><?= e($transaction['transaction_date']) ?> · <?= e($transaction['status']) ?> · <?= e($transaction['type']) ?></span></div><span class="tag">R$ <?= number_format((float) $transaction['amount'], 2, ',', '.') ?></span></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="grid">
    <div class="card enter">
      <h2>Recorrências</h2>
      <form method="post" class="split">
        <input type="hidden" name="action" value="create_recurring">
        <input type="hidden" name="instance_id" value="<?= $instanceId ?>">
        <label>Descrição<input type="text" name="description" required></label>
        <label>Valor<input type="number" step="0.01" name="amount" required></label>
        <label>Tipo
          <select name="type"><option value="income">income</option><option value="expense">expense</option><option value="transfer">transfer</option></select>
        </label>
        <label>Frequência
          <select name="frequency"><option value="weekly">weekly</option><option value="monthly">monthly</option><option value="yearly">yearly</option></select>
        </label>
        <label>Vencimento do mês<input type="number" name="due_day" min="1" max="31"></label>
        <label>Início<input type="date" name="start_date" value="<?= date('Y-m-d') ?>"></label>
        <label>Fim<input type="date" name="end_date"></label>
        <label>Conta
          <select name="account_id">
            <?php foreach ($accounts as $account): ?><option value="<?= (int) $account['id'] ?>"><?= e($account['name']) ?></option><?php endforeach; ?>
          </select>
        </label>
        <label>Centro
          <select name="center_id">
            <?php foreach ($centers as $center): ?><option value="<?= (int) $center['id'] ?>"><?= e($center['name']) ?></option><?php endforeach; ?>
          </select>
        </label>
        <label>Categoria
          <select name="category_id">
            <?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>"><?= e($category['name']) ?></option><?php endforeach; ?>
          </select>
        </label>
        <button class="btn btn-primary" type="submit">Criar recorrência</button>
      </form>
      <div class="list">
        <?php foreach ($recurring as $item): ?>
          <div class="member"><div class="meta"><strong><?= e($item['description']) ?></strong><span class="muted"><?= e($item['frequency']) ?> · <?= e($item['type']) ?></span></div><span class="tag">R$ <?= number_format((float) $item['amount'], 2, ',', '.') ?></span></div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card enter">
      <h2>Orçamentos, metas e regras</h2>
      <div class="split">
        <form method="post">
          <input type="hidden" name="action" value="create_budget">
          <input type="hidden" name="instance_id" value="<?= $instanceId ?>">
          <h3>Orçamento</h3>
          <label>Mês<input type="number" name="month" min="1" max="12" required></label>
          <label>Ano<input type="number" name="year" value="<?= date('Y') ?>" required></label>
          <label>Centro
            <select name="center_id"><?php foreach ($centers as $center): ?><option value="<?= (int) $center['id'] ?>"><?= e($center['name']) ?></option><?php endforeach; ?></select>
          </label>
          <label>Categoria<input type="hidden" name="category_id" value=""></label>
          <label>Planejado<input type="number" step="0.01" name="planned_amount" value="0"></label>
          <label>Alerta %<input type="number" name="alert_percent" value="80"></label>
          <button class="btn btn-primary" type="submit">Criar orçamento</button>
        </form>
        <form method="post">
          <input type="hidden" name="action" value="create_goal">
          <input type="hidden" name="instance_id" value="<?= $instanceId ?>">
          <h3>Meta</h3>
          <label>Nome<input type="text" name="name" required></label>
          <label>Alvo<input type="number" step="0.01" name="target_amount" required></label>
          <label>Atual<input type="number" step="0.01" name="current_amount" value="0"></label>
          <label>Prazo<input type="date" name="deadline"></label>
          <label>Centro
            <select name="center_id"><?php foreach ($centers as $center): ?><option value="<?= (int) $center['id'] ?>"><?= e($center['name']) ?></option><?php endforeach; ?></select>
          </label>
          <label>Prioridade<input type="number" name="priority" value="3"></label>
          <button class="btn btn-primary" type="submit">Criar meta</button>
        </form>
      </div>

      <div class="list">
        <?php foreach ($budgets as $budget): ?>
          <div class="member"><div class="meta"><strong>Orçamento <?= (int) $budget['month'] ?>/<?= (int) $budget['year'] ?></strong><span class="muted">Centro <?= (int) $budget['center_id'] ?></span></div><span class="tag">R$ <?= number_format((float) $budget['planned_amount'], 2, ',', '.') ?></span></div>
        <?php endforeach; ?>
        <?php foreach ($goals as $goal): ?>
          <div class="member"><div class="meta"><strong><?= e($goal['name']) ?></strong><span class="muted">Meta financeira</span></div><span class="tag">R$ <?= number_format((float) $goal['current_amount'], 2, ',', '.') ?> / <?= number_format((float) $goal['target_amount'], 2, ',', '.') ?></span></div>
        <?php endforeach; ?>
      </div>

      <form method="post" class="split" style="margin-top:16px">
        <input type="hidden" name="action" value="create_rule">
        <input type="hidden" name="instance_id" value="<?= $instanceId ?>">
        <h3>Regras automáticas</h3>
        <label>Texto alvo<input type="text" name="match_text" required></label>
        <label>Tipo de correspondência
          <select name="match_type"><option value="contains">contains</option><option value="starts_with">starts_with</option><option value="equals">equals</option><option value="regex">regex</option></select>
        </label>
        <label>Tipo transação<input type="text" name="transaction_type" placeholder="income, expense, transfer"></label>
        <label>Centro
          <select name="center_id"><?php foreach ($centers as $center): ?><option value="<?= (int) $center['id'] ?>"><?= e($center['name']) ?></option><?php endforeach; ?></select>
        </label>
        <label>Categoria
          <select name="category_id"><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>"><?= e($category['name']) ?></option><?php endforeach; ?></select>
        </label>
        <label>Conta
          <select name="account_id"><option value="">Sem conta</option><?php foreach ($accounts as $account): ?><option value="<?= (int) $account['id'] ?>"><?= e($account['name']) ?></option><?php endforeach; ?></select>
        </label>
        <button class="btn btn-primary" type="submit">Criar regra</button>
      </form>

      <div class="list">
        <?php foreach ($rules as $rule): ?>
          <div class="member"><div class="meta"><strong><?= e($rule['match_text']) ?></strong><span class="muted"><?= e($rule['match_type']) ?> · <?= e((string) $rule['transaction_type']) ?></span></div><span class="tag">regra</span></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
</body>
</html>
