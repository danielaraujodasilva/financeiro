<?php
require __DIR__ . '/bootstrap.php';

$instanceId = (int) ($_GET['instance_id'] ?? $_POST['instance_id'] ?? 0);
if (!$instanceId) {
    exit('Instância obrigatória.');
}

$userId = $auth->requireInstanceAccess($instanceId);
$cards = $financial->cards($instanceId);
$purchases = $financial->purchases($instanceId);
$bills = $financial->bills($instanceId);
$centers = $financial->centers($instanceId);
$categories = $financial->categories($instanceId);
$accounts = $financial->accounts($instanceId);
$message = $error = null;

function card_post(string $key, mixed $default = null): mixed
{
    return $_POST[$key] ?? $default;
}

function card_rebuild_bills(PDO $pdo, int $instanceId, int $cardId): void
{
    $pdo->beginTransaction();
    try {
        $sumStmt = $pdo->prepare('
            SELECT
                COALESCE(SUM(ci.amount), 0) AS total_amount,
                MIN(ci.due_date) AS due_date,
                MAX(ci.due_date) AS last_due_date
            FROM credit_card_installments ci
            INNER JOIN credit_card_purchases p ON p.id = ci.purchase_id
            WHERE p.instance_id = ? AND p.card_id = ?
        ');
        $sumStmt->execute([$instanceId, $cardId]);
        $summary = $sumStmt->fetch(PDO::FETCH_ASSOC) ?: ['total_amount' => 0, 'due_date' => null, 'last_due_date' => null];

        $billsStmt = $pdo->prepare('DELETE FROM credit_card_bills WHERE instance_id = ? AND card_id = ?');
        $billsStmt->execute([$instanceId, $cardId]);

        $monthStmt = $pdo->prepare('
            SELECT DISTINCT strftime("%m", due_date) AS month, strftime("%Y", due_date) AS year, MIN(due_date) AS closing_date, MAX(due_date) AS due_date
            FROM credit_card_installments ci
            INNER JOIN credit_card_purchases p ON p.id = ci.purchase_id
            WHERE p.instance_id = ? AND p.card_id = ?
            GROUP BY strftime("%Y", due_date), strftime("%m", due_date)
            ORDER BY year, month
        ');
        $monthStmt->execute([$instanceId, $cardId]);
        $months = $monthStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$months) {
            $pdo->commit();
            return;
        }

        $insertBill = $pdo->prepare('
            INSERT INTO credit_card_bills (instance_id, card_id, reference_month, reference_year, closing_date, due_date, total_amount, status, payment_transaction_id, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, "open", NULL, ?, ?)
        ');
        foreach ($months as $row) {
            $amountStmt = $pdo->prepare('
                SELECT COALESCE(SUM(amount), 0)
                FROM credit_card_installments ci
                INNER JOIN credit_card_purchases p ON p.id = ci.purchase_id
                WHERE p.instance_id = ? AND p.card_id = ? AND strftime("%m", ci.due_date) = ? AND strftime("%Y", ci.due_date) = ?
            ');
            $amountStmt->execute([$instanceId, $cardId, $row['month'], $row['year']]);
            $total = (float) $amountStmt->fetchColumn();
            $now = date('Y-m-d H:i:s');
            $insertBill->execute([
                $instanceId,
                $cardId,
                (int) $row['month'],
                (int) $row['year'],
                $row['closing_date'] ?? $row['due_date'],
                $row['due_date'],
                $total,
                $now,
                $now,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string) card_post('action', '');
        $now = date('Y-m-d H:i:s');

        if ($action === 'create_card') {
            $stmt = $pdo->prepare('INSERT INTO credit_cards (instance_id, account_id, name, bank_name, credit_limit, closing_day, due_day, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)');
            $stmt->execute([
                $instanceId,
                (int) card_post('card_account_id'),
                trim((string) card_post('card_name')),
                trim((string) card_post('card_bank_name', '')),
                (float) card_post('card_credit_limit', 0),
                card_post('card_closing_day') === '' ? null : (int) card_post('card_closing_day'),
                card_post('card_due_day') === '' ? null : (int) card_post('card_due_day'),
                $now,
                $now,
            ]);
            $audit->log($instanceId, $userId, 'financial_card_create', 'credit_cards', (string) $pdo->lastInsertId(), [], ['name' => trim((string) card_post('card_name'))]);
            $message = 'Cartão criado.';
        } elseif ($action === 'update_card') {
            $cardId = (int) card_post('card_id', 0);
            $beforeStmt = $pdo->prepare('SELECT * FROM credit_cards WHERE id = ? AND instance_id = ?');
            $beforeStmt->execute([$cardId, $instanceId]);
            $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $stmt = $pdo->prepare('UPDATE credit_cards SET account_id = ?, name = ?, bank_name = ?, credit_limit = ?, closing_day = ?, due_day = ?, active = ?, updated_at = ? WHERE id = ? AND instance_id = ?');
            $stmt->execute([
                (int) card_post('card_account_id'),
                trim((string) card_post('card_name')),
                trim((string) card_post('card_bank_name', '')),
                (float) card_post('card_credit_limit', 0),
                card_post('card_closing_day') === '' ? null : (int) card_post('card_closing_day'),
                card_post('card_due_day') === '' ? null : (int) card_post('card_due_day'),
                isset($_POST['card_active']) ? 1 : 0,
                $now,
                $cardId,
                $instanceId,
            ]);
            $audit->log($instanceId, $userId, 'financial_card_update', 'credit_cards', (string) $cardId, $before, [
                'name' => trim((string) card_post('card_name')),
                'credit_limit' => (float) card_post('card_credit_limit', 0),
            ]);
            $message = 'Cartão atualizado.';
        } elseif ($action === 'delete_card') {
            $cardId = (int) card_post('card_id', 0);
            $beforeStmt = $pdo->prepare('SELECT * FROM credit_cards WHERE id = ? AND instance_id = ?');
            $beforeStmt->execute([$cardId, $instanceId]);
            $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $purchaseIdsStmt = $pdo->prepare('SELECT id FROM credit_card_purchases WHERE instance_id = ? AND card_id = ?');
            $purchaseIdsStmt->execute([$instanceId, $cardId]);
            $purchaseIds = $purchaseIdsStmt->fetchAll(PDO::FETCH_COLUMN);

            if ($purchaseIds) {
                $placeholders = implode(',', array_fill(0, count($purchaseIds), '?'));
                $pdo->prepare("DELETE FROM credit_card_installments WHERE purchase_id IN ($placeholders)")->execute($purchaseIds);
                $pdo->prepare("DELETE FROM credit_card_purchases WHERE id IN ($placeholders)")->execute($purchaseIds);
            }
            $pdo->prepare('DELETE FROM credit_card_bills WHERE instance_id = ? AND card_id = ?')->execute([$instanceId, $cardId]);
            $pdo->prepare('DELETE FROM credit_cards WHERE id = ? AND instance_id = ?')->execute([$cardId, $instanceId]);
            $audit->log($instanceId, $userId, 'financial_card_delete', 'credit_cards', (string) $cardId, $before);
            $message = 'Cartão removido.';
        } elseif ($action === 'create_card_purchase') {
            $installments = max(1, (int) card_post('installments_count', 1));
            $totalAmount = (float) card_post('purchase_total_amount', 0);
            $purchaseDate = (string) card_post('purchase_date', date('Y-m-d'));
            $stmt = $pdo->prepare('INSERT INTO credit_card_purchases (instance_id, card_id, description, total_amount, purchase_date, installments_count, center_id, category_id, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $instanceId,
                (int) card_post('purchase_card_id'),
                trim((string) card_post('purchase_description')),
                $totalAmount,
                $purchaseDate,
                $installments,
                (int) card_post('purchase_center_id'),
                (int) card_post('purchase_category_id'),
                trim((string) card_post('purchase_notes', '')),
                $now, $now
            ]);
            $purchaseId = (int) $pdo->lastInsertId();
            $installmentAmount = round($totalAmount / $installments, 2);

            $cardStmt = $pdo->prepare('SELECT closing_day, due_day FROM credit_cards WHERE id = ? AND instance_id = ?');
            $cardStmt->execute([(int) card_post('purchase_card_id'), $instanceId]);
            $card = $cardStmt->fetch(PDO::FETCH_ASSOC) ?: ['closing_day' => 0, 'due_day' => 0];
            $dueDay = max(1, (int) ($card['due_day'] ?? 1));

            $insStmt = $pdo->prepare('INSERT INTO credit_card_installments (purchase_id, installment_number, due_date, amount, status, transaction_id, created_at, updated_at) VALUES (?, ?, ?, ?, "planned", NULL, ?, ?)');
            for ($i = 1; $i <= $installments; $i++) {
                $due = new DateTime($purchaseDate);
                $due->modify('first day of next month');
                $due->modify('+' . ($i - 1) . ' month');
                $due->setDate((int) $due->format('Y'), (int) $due->format('m'), min($dueDay, (int) $due->format('t')));
                $insStmt->execute([$purchaseId, $i, $due->format('Y-m-d'), $installmentAmount, $now, $now]);
            }
            card_rebuild_bills($pdo, $instanceId, (int) card_post('purchase_card_id'));
            $audit->log($instanceId, $userId, 'financial_card_purchase_create', 'credit_card_purchases', (string) $purchaseId, [], ['description' => trim((string) card_post('purchase_description')), 'total_amount' => $totalAmount]);
            $message = 'Compra do cartão criada com parcelas.';
        } elseif ($action === 'update_card_purchase') {
            $purchaseId = (int) card_post('purchase_id', 0);
            $beforeStmt = $pdo->prepare('SELECT * FROM credit_card_purchases WHERE id = ? AND instance_id = ?');
            $beforeStmt->execute([$purchaseId, $instanceId]);
            $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            if (!$before) {
                throw new RuntimeException('Compra não encontrada.');
            }
            $cardId = (int) card_post('purchase_card_id');
            $installments = max(1, (int) card_post('installments_count', 1));
            $totalAmount = (float) card_post('purchase_total_amount', 0);
            $purchaseDate = (string) card_post('purchase_date', date('Y-m-d'));

            $pdo->beginTransaction();
            try {
                $pdo->prepare('DELETE FROM credit_card_installments WHERE purchase_id = ?')->execute([$purchaseId]);
                $stmt = $pdo->prepare('UPDATE credit_card_purchases SET card_id = ?, description = ?, total_amount = ?, purchase_date = ?, installments_count = ?, center_id = ?, category_id = ?, notes = ?, updated_at = ? WHERE id = ? AND instance_id = ?');
                $stmt->execute([
                    $cardId,
                    trim((string) card_post('purchase_description')),
                    $totalAmount,
                    $purchaseDate,
                    $installments,
                    (int) card_post('purchase_center_id'),
                    (int) card_post('purchase_category_id'),
                    trim((string) card_post('purchase_notes', '')),
                    $now,
                    $purchaseId,
                    $instanceId,
                ]);

                $cardStmt = $pdo->prepare('SELECT closing_day, due_day FROM credit_cards WHERE id = ? AND instance_id = ?');
                $cardStmt->execute([$cardId, $instanceId]);
                $card = $cardStmt->fetch(PDO::FETCH_ASSOC) ?: ['due_day' => 0];
                $dueDay = max(1, (int) ($card['due_day'] ?? 1));
                $installmentAmount = round($totalAmount / $installments, 2);
                $insStmt = $pdo->prepare('INSERT INTO credit_card_installments (purchase_id, installment_number, due_date, amount, status, transaction_id, created_at, updated_at) VALUES (?, ?, ?, ?, "planned", NULL, ?, ?)');
                for ($i = 1; $i <= $installments; $i++) {
                    $due = new DateTime($purchaseDate);
                    $due->modify('first day of next month');
                    $due->modify('+' . ($i - 1) . ' month');
                    $due->setDate((int) $due->format('Y'), (int) $due->format('m'), min($dueDay, (int) $due->format('t')));
                    $insStmt->execute([$purchaseId, $i, $due->format('Y-m-d'), $installmentAmount, $now, $now]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            card_rebuild_bills($pdo, $instanceId, $cardId);

            $audit->log($instanceId, $userId, 'financial_card_purchase_update', 'credit_card_purchases', (string) $purchaseId, $before, [
                'description' => trim((string) card_post('purchase_description')),
                'total_amount' => $totalAmount,
            ]);
            $message = 'Compra atualizada.';
        } elseif ($action === 'delete_card_purchase') {
            $purchaseId = (int) card_post('purchase_id', 0);
            $beforeStmt = $pdo->prepare('SELECT * FROM credit_card_purchases WHERE id = ? AND instance_id = ?');
            $beforeStmt->execute([$purchaseId, $instanceId]);
            $before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            if (!$before) {
                throw new RuntimeException('Compra não encontrada.');
            }
            $cardId = (int) $before['card_id'];
            $pdo->beginTransaction();
            try {
                $pdo->prepare('DELETE FROM credit_card_installments WHERE purchase_id = ?')->execute([$purchaseId]);
                $pdo->prepare('DELETE FROM credit_card_purchases WHERE id = ? AND instance_id = ?')->execute([$purchaseId, $instanceId]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            card_rebuild_bills($pdo, $instanceId, $cardId);
            $audit->log($instanceId, $userId, 'financial_card_purchase_delete', 'credit_card_purchases', (string) $purchaseId, $before);
            $message = 'Compra removida.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$cardStats = [];
$cardStatsStmt = $pdo->prepare('
    SELECT
        c.id,
        c.name,
        c.credit_limit,
        COALESCE((SELECT SUM(p.total_amount) FROM credit_card_purchases p WHERE p.card_id = c.id), 0) AS spent_total,
        COALESCE((SELECT SUM(ci.amount) FROM credit_card_installments ci INNER JOIN credit_card_purchases p ON p.id = ci.purchase_id WHERE p.card_id = c.id AND ci.status IN ("planned", "pending")), 0) AS future_commitment,
        COALESCE((SELECT SUM(ci.amount) FROM credit_card_installments ci INNER JOIN credit_card_purchases p ON p.id = ci.purchase_id WHERE p.card_id = c.id AND ci.status = "paid"), 0) AS paid_installments
    FROM credit_cards c
    WHERE c.instance_id = ?
    ORDER BY c.id DESC
');
$cardStatsStmt->execute([$instanceId]);
$cardStats = $cardStatsStmt->fetchAll();

$cardInstallmentsStmt = $pdo->prepare('
    SELECT
        ci.*,
        p.description AS purchase_description,
        c.name AS card_name
    FROM credit_card_installments ci
    INNER JOIN credit_card_purchases p ON p.id = ci.purchase_id
    INNER JOIN credit_cards c ON c.id = p.card_id
    WHERE c.instance_id = ?
    ORDER BY ci.due_date ASC, ci.installment_number ASC
');
$cardInstallmentsStmt->execute([$instanceId]);
$cardInstallments = $cardInstallmentsStmt->fetchAll();
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cartões</title>
<?= bootstrap_assets() ?>
<link rel="stylesheet" href="<?= e(base_path('assets/ui.css')) ?>">
</head>
<body>
<div class="wrap">
  <?php financial_nav($instanceId,'cards'); ?>
  <div class="card hero">
    <h1 class="headline">Cartões</h1>
    <p class="muted">Agora você consegue editar e remover cartões e compras sem bagunçar a projeção das faturas.</p>
    <?php if ($message): ?><div class="toast good"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="toast bad"><?= e($error) ?></div><?php endif; ?>
    <div class="actions" style="margin-top:14px">
      <a class="btn btn-primary" href="#novo-cartao">Novo cartão</a>
      <a class="btn btn-secondary" href="#compras">Compras</a>
      <a class="btn btn-secondary" href="#faturas">Faturas</a>
    </div>
  </div>

  <div class="card" id="novo-cartao">
    <details>
      <summary class="tag" style="cursor:pointer">Novo cartão</summary>
      <div style="margin-top:14px">
    <form method="post" class="split">
      <input type="hidden" name="action" value="create_card">
      <input type="hidden" name="instance_id" value="<?= $instanceId ?>">
      <label>Nome do cartão<input type="text" name="card_name" required></label>
      <label>Conta vinculada
        <select name="card_account_id" required>
          <?php foreach ($accounts as $account): ?>
            <option value="<?= (int) $account['id'] ?>"><?= e($account['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Banco<input type="text" name="card_bank_name"></label>
      <label>Limite de crédito<input type="number" step="0.01" name="card_credit_limit" value="0"></label>
      <label>Fechamento<input type="number" name="card_closing_day" min="1" max="31"></label>
      <label>Vencimento<input type="number" name="card_due_day" min="1" max="31"></label>
      <button class="btn btn-primary" type="submit">Criar cartão</button>
    </form>
      </div>
    </details>
  </div>

  <div class="grid">
    <div class="card">
      <details open>
        <summary class="tag" style="cursor:pointer">Cartões cadastrados</summary>
        <div style="margin-top:14px">
      <div class="list">
        <?php foreach ($cards as $card): ?>
          <div class="member">
            <div class="meta">
              <strong><?= e($card['name']) ?></strong>
              <span class="muted"><?= e((string)$card['bank_name']) ?> · conta #<?= (int)$card['account_id'] ?></span>
            </div>
            <div class="actions">
              <span class="tag">R$ <?= number_format((float)$card['credit_limit'],2,',','.') ?></span>
            </div>
          </div>
          <form method="post" class="split" style="margin-bottom:16px">
            <input type="hidden" name="action" value="update_card">
            <input type="hidden" name="instance_id" value="<?= $instanceId ?>">
            <input type="hidden" name="card_id" value="<?= (int)$card['id'] ?>">
            <label>Nome<input type="text" name="card_name" value="<?= e($card['name']) ?>"></label>
            <label>Conta vinculada
              <select name="card_account_id">
                <?php foreach ($accounts as $account): ?>
                  <option value="<?= (int) $account['id'] ?>" <?= (int)$account['id'] === (int)$card['account_id'] ? 'selected' : '' ?>><?= e($account['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>Banco<input type="text" name="card_bank_name" value="<?= e((string)$card['bank_name']) ?>"></label>
            <label>Limite<input type="number" step="0.01" name="card_credit_limit" value="<?= e((string)$card['credit_limit']) ?>"></label>
            <label>Fechamento<input type="number" name="card_closing_day" min="1" max="31" value="<?= e((string)$card['closing_day']) ?>"></label>
            <label>Vencimento<input type="number" name="card_due_day" min="1" max="31" value="<?= e((string)$card['due_day']) ?>"></label>
            <label><input type="checkbox" name="card_active" <?= (int)$card['active'] === 1 ? 'checked' : '' ?>> Ativo</label>
            <div class="actions">
              <button class="btn btn-secondary" type="submit">Salvar</button>
            </div>
          </form>
          <form method="post" onsubmit="return confirm('Remover este cartão e todas as compras vinculadas?');" style="margin-top:-8px; margin-bottom:16px">
            <input type="hidden" name="action" value="delete_card">
            <input type="hidden" name="instance_id" value="<?= $instanceId ?>">
            <input type="hidden" name="card_id" value="<?= (int)$card['id'] ?>">
            <button class="btn btn-danger" type="submit">Remover cartão</button>
          </form>
        <?php endforeach; ?>
      </div>
        </div>
      </details>
    </div>

    <div class="card" id="compras">
      <details>
        <summary class="tag" style="cursor:pointer">Compras</summary>
        <div style="margin-top:14px">
      <form method="post" class="split">
        <input type="hidden" name="action" value="create_card_purchase">
        <input type="hidden" name="instance_id" value="<?= $instanceId ?>">
        <label>Cartão
          <select name="purchase_card_id" required>
            <?php foreach ($cards as $card): ?>
              <option value="<?= (int) $card['id'] ?>"><?= e($card['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Descrição<input type="text" name="purchase_description" required></label>
        <label>Valor total<input type="number" step="0.01" name="purchase_total_amount" required></label>
        <label>Data da compra<input type="date" name="purchase_date" value="<?= date('Y-m-d') ?>"></label>
        <label>Parcelas<input type="number" name="installments_count" min="1" value="1"></label>
        <label>Centro
          <select name="purchase_center_id" required>
            <?php foreach ($centers as $center): ?>
              <option value="<?= (int) $center['id'] ?>"><?= e($center['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Categoria
          <select name="purchase_category_id" required>
            <?php foreach ($categories as $category): ?>
              <option value="<?= (int) $category['id'] ?>"><?= e($category['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Observações<input type="text" name="purchase_notes"></label>
        <button class="btn btn-primary" type="submit">Cadastrar compra</button>
      </form>
      <div class="list">
        <?php foreach ($purchases as $purchase): ?>
          <div class="member">
            <div class="meta">
              <strong><?= e($purchase['description']) ?></strong>
              <span class="muted"><?= e($purchase['card_name']) ?> · <?= (int)$purchase['installments_count'] ?>x · <?= e($purchase['purchase_date']) ?></span>
            </div>
            <span class="tag">R$ <?= number_format((float)$purchase['total_amount'],2,',','.') ?></span>
          </div>
          <form method="post" class="split" style="margin-bottom:16px">
            <input type="hidden" name="action" value="update_card_purchase">
            <input type="hidden" name="instance_id" value="<?= $instanceId ?>">
            <input type="hidden" name="purchase_id" value="<?= (int)$purchase['id'] ?>">
            <label>Cartão
              <select name="purchase_card_id">
                <?php foreach ($cards as $card): ?>
                  <option value="<?= (int) $card['id'] ?>" <?= (int)$card['id'] === (int)$purchase['card_id'] ? 'selected' : '' ?>><?= e($card['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>Descrição<input type="text" name="purchase_description" value="<?= e($purchase['description']) ?>"></label>
            <label>Valor total<input type="number" step="0.01" name="purchase_total_amount" value="<?= e((string)$purchase['total_amount']) ?>"></label>
            <label>Data da compra<input type="date" name="purchase_date" value="<?= e($purchase['purchase_date']) ?>"></label>
            <label>Parcelas<input type="number" name="installments_count" min="1" value="<?= (int)$purchase['installments_count'] ?>"></label>
            <label>Centro
              <select name="purchase_center_id">
                <?php foreach ($centers as $center): ?>
                  <option value="<?= (int) $center['id'] ?>" <?= (int)$center['id'] === (int)$purchase['center_id'] ? 'selected' : '' ?>><?= e($center['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>Categoria
              <select name="purchase_category_id">
                <?php foreach ($categories as $category): ?>
                  <option value="<?= (int) $category['id'] ?>" <?= (int)$category['id'] === (int)$purchase['category_id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>Observações<input type="text" name="purchase_notes" value="<?= e((string)$purchase['notes']) ?>"></label>
            <div class="actions">
              <button class="btn btn-secondary" type="submit">Salvar</button>
            </div>
          </form>
          <form method="post" onsubmit="return confirm('Remover esta compra e suas parcelas?');" style="margin-top:-8px; margin-bottom:16px">
            <input type="hidden" name="action" value="delete_card_purchase">
            <input type="hidden" name="instance_id" value="<?= $instanceId ?>">
            <input type="hidden" name="purchase_id" value="<?= (int)$purchase['id'] ?>">
            <button class="btn btn-danger" type="submit">Remover compra</button>
          </form>
        <?php endforeach; ?>
      </div>
        </div>
      </details>
    </div>
  </div>

  <div class="card" id="faturas">
    <details>
      <summary class="tag" style="cursor:pointer">Faturas do cartão</summary>
      <div style="margin-top:14px">
    <div class="list">
      <?php foreach ($bills as $bill): ?>
        <div class="member">
          <div class="meta">
            <strong><?= e($bill['card_name']) ?> · <?= (int)$bill['reference_month'] ?>/<?= (int)$bill['reference_year'] ?></strong>
            <span class="muted">Vencimento <?= e($bill['due_date']) ?> · Status <?= e($bill['status']) ?></span>
          </div>
          <span class="tag">R$ <?= number_format((float)$bill['total_amount'],2,',','.') ?></span>
        </div>
      <?php endforeach; ?>
      <?php if (!$bills): ?><p class="muted">Nenhuma fatura gerada ainda.</p><?php endif; ?>
    </div>
      </div>
    </details>
  </div>
</div>
</body>
</html>
