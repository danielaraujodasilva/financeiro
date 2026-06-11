<?php
require __DIR__ . '/bootstrap.php';

$userId = $auth->requireLogin();
$user = $auth->currentUser();
$error = null;
$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $goal = trim((string) ($_POST['goal'] ?? ''));
    $centers = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) ($_POST['centers'] ?? '')) ?: [])));
    $accounts = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) ($_POST['accounts'] ?? '')) ?: [])));
    $fixeds = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) ($_POST['fixeds'] ?? '')) ?: [])));

    if ($goal === '') {
        $error = 'Escolha um objetivo inicial.';
    } else {
        $auth->setOnboardingData($userId, $goal, 1);
        $instanceId = (int) ($_POST['instance_id'] ?? 0);
        if ($instanceId > 0 && $auth->hasInstanceAccess($userId, $instanceId)) {
            $now = date('Y-m-d H:i:s');
            $centerInsert = $pdo->prepare('INSERT OR IGNORE INTO financial_centers (instance_id, name, type, active, created_at, updated_at) VALUES (?, ?, ?, 1, ?, ?)');
            foreach ($centers as $centerName) {
                $centerInsert->execute([$instanceId, $centerName, 'personal', $now, $now]);
            }
            $accountInsert = $pdo->prepare('INSERT OR IGNORE INTO financial_accounts (instance_id, name, type, bank_name, initial_balance, current_balance, credit_limit, closing_day, due_day, active, created_at, updated_at) VALUES (?, ?, ?, ?, 0, 0, 0, NULL, NULL, 1, ?, ?)');
            foreach ($accounts as $accountName) {
                $type = str_contains(mb_strtolower($accountName), 'cart') ? 'credit_card' : (str_contains(mb_strtolower($accountName), 'invest') ? 'investment' : (str_contains(mb_strtolower($accountName), 'dinhe') ? 'cash' : 'bank'));
                $accountInsert->execute([$instanceId, $accountName, $type, '', $now, $now]);
            }
            if ($fixeds) {
                $firstCenterStmt = $pdo->prepare('SELECT id FROM financial_centers WHERE instance_id = ? ORDER BY id ASC LIMIT 1');
                $firstCenterStmt->execute([$instanceId]);
                $centerId = (int) $firstCenterStmt->fetchColumn();
                $firstAccountStmt = $pdo->prepare('SELECT id FROM financial_accounts WHERE instance_id = ? ORDER BY id ASC LIMIT 1');
                $firstAccountStmt->execute([$instanceId]);
                $accountId = (int) $firstAccountStmt->fetchColumn();
                $firstCategoryStmt = $pdo->prepare('SELECT id FROM financial_categories WHERE instance_id = ? ORDER BY id ASC LIMIT 1');
                $firstCategoryStmt->execute([$instanceId]);
                $categoryId = (int) $firstCategoryStmt->fetchColumn();
                if ($centerId && $accountId && $categoryId) {
                    $fixedInsert = $pdo->prepare('INSERT INTO financial_recurring (instance_id, description, amount, type, frequency, due_day, start_date, end_date, account_id, center_id, category_id, payment_method, active, created_at, updated_at) VALUES (?, ?, ?, "expense", "monthly", NULL, ?, NULL, ?, ?, ?, "other", 1, ?, ?)');
                    foreach ($fixeds as $fixedName) {
                        $fixedInsert->execute([$instanceId, $fixedName, 0, date('Y-m-d'), $accountId, $centerId, $categoryId, $now, $now]);
                    }
                }
            }
        }
        header('Location: ' . base_path('dashboard.php'));
        exit;
    }
}

$activeInstanceId = (int) ($_GET['instance_id'] ?? ($auth->instancesForUser($userId)[0]['id'] ?? 0));
$suggestedCenters = "Casa/Família\nEstúdio\nPessoal\nReserva\nDívidas";
$suggestedAccounts = "Dinheiro\nBanco\nCartão\nInvestimento";
$suggestedFixeds = "Aluguel\nInternet\nEnergia\nCartão\nAnúncios\nTerapia";
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Onboarding - Financeiro</title>
<?= bootstrap_assets() ?>
<link rel="stylesheet" href="<?= e(base_path('assets/ui.css')) ?>">
</head>
<body>
<div class="wrap">
  <div class="card hero" style="max-width:860px;margin:0 auto">
    <div class="tag">Primeira configuração</div>
    <h1 class="headline">Vamos deixar sua base pronta em 5 passos</h1>
    <p class="muted">Você escolhe o foco, sugere áreas, cadastros básicos e contas fixas. Depois disso, o sistema já te leva ao dashboard.</p>
    <?php if ($error): ?><div class="toast bad"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="split">
      <input type="hidden" name="instance_id" value="<?= (int) $activeInstanceId ?>">
      <label>1. Você quer controlar o quê?
        <select name="goal" required>
          <option value="">Escolha uma opção</option>
          <option value="Minha vida pessoal">Minha vida pessoal</option>
          <option value="Meu negócio">Meu negócio</option>
          <option value="Os dois">Os dois</option>
        </select>
      </label>
      <label>2. Crie suas áreas principais
        <textarea name="centers" rows="6"><?= e($suggestedCenters) ?></textarea>
      </label>
      <label>3. Cadastre suas contas
        <textarea name="accounts" rows="5"><?= e($suggestedAccounts) ?></textarea>
      </label>
      <label>4. Cadastre seus gastos fixos
        <textarea name="fixeds" rows="6"><?= e($suggestedFixeds) ?></textarea>
      </label>
      <div class="col-12">
        <button class="btn btn-primary" type="submit">Finalizar configuração</button>
        <a class="btn btn-secondary" href="<?= e(base_path('dashboard.php')) ?>">Pular por agora</a>
      </div>
    </form>
  </div>
</div>
</body>
</html>
