<?php
require __DIR__ . '/bootstrap.php';

$instanceId = (int) ($_GET['instance_id'] ?? $_POST['instance_id'] ?? 0);
if (!$instanceId) { exit('Instância obrigatória.'); }
$auth->requireInstanceAccess($instanceId);
$instance = $auth->instanceById($instanceId);
$centers = $financial->centers($instanceId);
$categories = $financial->categories($instanceId);
$accounts = $financial->accounts($instanceId);
$message = $error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (($_POST['action'] ?? '') === 'create') {
            if ((int)($_POST['center_id'] ?? 0) === 0) throw new RuntimeException('Centro obrigatório.');
            $stmt = $pdo->prepare('INSERT INTO financial_transactions (instance_id, transaction_date, due_date, paid_date, description, amount, type, status, account_id, destination_account_id, center_id, category_id, payment_method, responsible_person, client_id, lead_id, appointment_id, notes, source, external_provider, external_account_id, external_transaction_id, sync_status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$instanceId, $_POST['transaction_date'], $_POST['due_date'] ?: null, $_POST['paid_date'] ?: null, trim($_POST['description']), (float)$_POST['amount'], $_POST['type'], $_POST['status'], (int)$_POST['account_id'], null, (int)$_POST['center_id'], (int)$_POST['category_id'], $_POST['payment_method'], null, null, null, null, trim($_POST['notes'] ?? ''), 'manual', null, null, null, 'not_synced', date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
            $message = 'Lançamento criado.';
        }
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
$transactions = $financial->transactions($instanceId, 200);
?>
<!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Lançamentos</title><link rel="stylesheet" href="<?= e(base_path('assets/ui.css')) ?>"></head><body><div class="wrap"><?php financial_nav($instanceId,'transactions'); ?><div class="card hero"><h1 class="headline">Lançamentos</h1><p class="muted">Listar, criar e organizar lançamentos financeiros.</p><?php if($message):?><div class="toast good"><?= e($message) ?></div><?php endif; if($error):?><div class="toast bad"><?= e($error) ?></div><?php endif; ?><form method="post" class="split"><input type="hidden" name="action" value="create"><label>Data<input type="date" name="transaction_date" value="<?= date('Y-m-d') ?>"></label><label>Vencimento<input type="date" name="due_date"></label><label>Pagamento<input type="date" name="paid_date"></label><label>Descrição<input type="text" name="description"></label><label>Valor<input type="number" step="0.01" name="amount"></label><label>Tipo<select name="type"><option>income</option><option>expense</option><option>transfer</option></select></label><label>Status<select name="status"><option>planned</option><option>pending</option><option>paid</option><option>overdue</option><option>canceled</option></select></label><label>Conta<select name="account_id"><?php foreach($accounts as $a):?><option value="<?= (int)$a['id'] ?>"><?= e($a['name']) ?></option><?php endforeach; ?></select></label><label>Centro<select name="center_id"><?php foreach($centers as $c):?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></label><label>Categoria<select name="category_id"><?php foreach($categories as $c):?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></label><label>Forma<select name="payment_method"><option>pix</option><option>cash</option><option>debit</option><option>credit_card</option><option>boleto</option><option>transfer</option><option>other</option></select></label><button class="btn btn-primary" type="submit">Criar</button></form></div><div class="card"><div class="list"><?php foreach($transactions as $t): ?><div class="member"><div class="meta"><strong><?= e($t['description']) ?></strong><span class="muted"><?= e($t['transaction_date']) ?> · <?= e($t['status']) ?> · <?= e($t['type']) ?></span></div><span class="tag">R$ <?= number_format((float)$t['amount'],2,',','.') ?></span></div><?php endforeach; ?></div></div></div></body></html>
