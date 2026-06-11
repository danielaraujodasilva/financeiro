<?php
require __DIR__ . '/bootstrap.php';

$instanceId = (int) ($_GET['instance_id'] ?? $_POST['instance_id'] ?? 0);
if (!$instanceId) {
    exit('Instância obrigatória.');
}

$auth->requireInstanceAccess($instanceId);
$instance = $auth->instanceById($instanceId);
$providers = $financial->externalProviders($instanceId);
$externalAccounts = $financial->externalAccounts($instanceId);
$externalTransactions = $financial->externalTransactions($instanceId);
$reconciliations = $financial->reconciliations($instanceId);
$internalAccounts = $financial->accounts($instanceId);
$transactions = $financial->transactions($instanceId, 200);
$message = $error = null;

if (!function_exists('of_normalize_text')) {
    function of_normalize_text(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        $value = preg_replace('/[^a-z0-9]+/i', ' ', $value) ?: '';
        return trim(preg_replace('/\s+/', ' ', $value) ?: '');
    }
}

if (!function_exists('of_date_diff_days')) {
    function of_date_diff_days(string $a, string $b): int
    {
        try {
            $da = new DateTimeImmutable(substr($a, 0, 10));
            $db = new DateTimeImmutable(substr($b, 0, 10));
            return (int) $da->diff($db)->format('%r%a');
        } catch (Throwable) {
            return 9999;
        }
    }
}

if (!function_exists('of_match_score')) {
    function of_match_score(array $external, array $internal): array
    {
        $externalAmount = round((float) ($external['amount'] ?? 0), 2);
        $internalAmount = round((float) ($internal['amount'] ?? 0), 2);
        $amountDiff = abs($externalAmount - $internalAmount);
        $amountScore = 0.0;
        if ($amountDiff === 0.0) {
            $amountScore = 60.0;
        } elseif ($amountDiff <= 0.5) {
            $amountScore = 54.0;
        } elseif ($amountDiff <= 1.0) {
            $amountScore = 48.0;
        } elseif ($amountDiff <= max(10.0, abs($externalAmount) * 0.02)) {
            $amountScore = 35.0;
        }

        $dateDiff = abs(of_date_diff_days((string) ($external['transaction_date'] ?? ''), (string) ($internal['transaction_date'] ?? '')));
        $dateScore = match (true) {
            $dateDiff === 0 => 25.0,
            $dateDiff === 1 => 20.0,
            $dateDiff === 2 => 15.0,
            $dateDiff <= 5 => 10.0,
            $dateDiff <= 10 => 5.0,
            default => 0.0,
        };

        $externalText = of_normalize_text((string) ($external['description'] ?? ''));
        $internalText = of_normalize_text((string) ($internal['description'] ?? ''));
        $descriptionScore = 0.0;
        if ($externalText !== '' && $internalText !== '') {
            similar_text($externalText, $internalText, $percent);
            $descriptionScore = min(15.0, $percent * 0.15);

            $externalWords = array_filter(explode(' ', $externalText));
            $internalWords = array_filter(explode(' ', $internalText));
            $intersection = array_intersect($externalWords, $internalWords);
            $union = array_unique(array_merge($externalWords, $internalWords));
            if ($union) {
                $jaccard = count($intersection) / count($union);
                $descriptionScore = max($descriptionScore, min(15.0, $jaccard * 20.0));
            }
        }

        $directionScore = (string) ($external['direction'] ?? '') === ((float) $externalAmount >= 0 ? 'credit' : 'debit') ? 0.0 : 0.0;
        $total = $amountScore + $dateScore + $descriptionScore + $directionScore;

        return [
            'score' => $total,
            'amount_diff' => $amountDiff,
            'date_diff' => $dateDiff,
        ];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        $now = date('Y-m-d H:i:s');
        if ($action === 'create_provider') {
            $slug = strtolower(trim((string) ($_POST['slug'] ?? '')));
            if ($slug === '') {
                throw new RuntimeException('Slug do provedor é obrigatório.');
            }
            $stmt = $pdo->prepare('INSERT INTO financial_external_providers (instance_id, name, slug, provider_type, status, environment, consent_reference, last_sync_at, last_sync_status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $instanceId,
                trim((string) ($_POST['name'] ?? '')),
                $slug,
                trim((string) ($_POST['provider_type'] ?? 'openfinance')),
                trim((string) ($_POST['status'] ?? 'draft')),
                trim((string) ($_POST['environment'] ?? 'sandbox')),
                trim((string) ($_POST['consent_reference'] ?? '')),
                null,
                'never',
                $now,
                $now,
            ]);
            $audit->log($instanceId, $auth->userId(), 'openfinance_provider_create', 'financial_external_providers', (string) $pdo->lastInsertId(), [], [
                'name' => trim((string) ($_POST['name'] ?? '')),
                'slug' => $slug,
            ]);
            $message = 'Provedor criado.';
        } elseif ($action === 'create_external_account') {
            $stmt = $pdo->prepare('INSERT INTO financial_external_accounts (instance_id, provider_id, external_account_id, account_name, account_type, institution_name, branch_code, account_number, currency, balance, last_sync_at, last_sync_status, linked_account_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $instanceId,
                (int) ($_POST['provider_id'] ?? 0),
                trim((string) ($_POST['external_account_id'] ?? '')),
                trim((string) ($_POST['account_name'] ?? '')),
                trim((string) ($_POST['account_type'] ?? 'checking')),
                trim((string) ($_POST['institution_name'] ?? '')),
                trim((string) ($_POST['branch_code'] ?? '')),
                trim((string) ($_POST['account_number'] ?? '')),
                trim((string) ($_POST['currency'] ?? 'BRL')),
                (float) ($_POST['balance'] ?? 0),
                null,
                'never',
                ($_POST['linked_account_id'] ?? '') === '' ? null : (int) $_POST['linked_account_id'],
                $now,
                $now,
            ]);
            $audit->log($instanceId, $auth->userId(), 'openfinance_account_create', 'financial_external_accounts', (string) $pdo->lastInsertId(), [], [
                'external_account_id' => trim((string) ($_POST['external_account_id'] ?? '')),
                'account_name' => trim((string) ($_POST['account_name'] ?? '')),
            ]);
            $message = 'Conta externa criada.';
        } elseif ($action === 'import_statement') {
            $providerId = (int) ($_POST['provider_id'] ?? 0);
            $externalAccountId = (int) ($_POST['external_account_id'] ?? 0);
            $payload = trim((string) ($_POST['raw_payload_json'] ?? ''));
            $rows = json_decode($payload, true);
            if (!is_array($rows)) {
                throw new RuntimeException('Cole um JSON válido com a lista de transações.');
            }

            $insert = $pdo->prepare('INSERT INTO financial_external_transactions (instance_id, provider_id, external_account_id, external_transaction_id, transaction_date, description, amount, direction, status, category_hint, matched_transaction_id, reconciliation_status, raw_payload_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT(provider_id, external_transaction_id) DO UPDATE SET transaction_date = excluded.transaction_date, description = excluded.description, amount = excluded.amount, direction = excluded.direction, status = excluded.status, category_hint = excluded.category_hint, raw_payload_json = excluded.raw_payload_json, updated_at = excluded.updated_at');

            $count = 0;
            foreach ($rows as $row) {
                $externalId = (string) ($row['id'] ?? $row['external_transaction_id'] ?? '');
                if ($externalId === '') {
                    continue;
                }
                $insert->execute([
                    $instanceId,
                    $providerId,
                    $externalAccountId,
                    $externalId,
                    (string) ($row['date'] ?? $row['transaction_date'] ?? date('Y-m-d')),
                    (string) ($row['description'] ?? 'Importação Open Finance'),
                    (float) ($row['amount'] ?? 0),
                    (string) ($row['direction'] ?? ((float) ($row['amount'] ?? 0) < 0 ? 'debit' : 'credit')),
                    (string) ($row['status'] ?? 'imported'),
                    (string) ($row['category_hint'] ?? ''),
                    null,
                    'pending',
                    json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $now,
                    $now,
                ]);
                $count++;
            }
            $audit->log($instanceId, $auth->userId(), 'openfinance_import_statement', 'financial_external_transactions', null, [], ['imported_count' => $count]);
            $message = $count . ' transações importadas.';
        } elseif ($action === 'run_reconciliation') {
            $providerId = (int) ($_POST['provider_id'] ?? 0);
            $externalAccountId = (int) ($_POST['external_account_id'] ?? 0);
            $linkedAccountId = (int) ($_POST['linked_account_id'] ?? 0);

            $externalStmt = $pdo->prepare('SELECT * FROM financial_external_transactions WHERE instance_id = ? AND provider_id = ? AND external_account_id = ? ORDER BY transaction_date DESC, id DESC');
            $externalStmt->execute([$instanceId, $providerId, $externalAccountId]);
            $externalRows = $externalStmt->fetchAll(PDO::FETCH_ASSOC);

            $internalStmt = $pdo->prepare('SELECT * FROM financial_transactions WHERE instance_id = ? AND account_id = ? ORDER BY transaction_date DESC, id DESC');
            $internalStmt->execute([$instanceId, $linkedAccountId]);
            $internalRows = $internalStmt->fetchAll(PDO::FETCH_ASSOC);

            $matched = 0;
            $unmatched = 0;
            $ambiguous = 0;
            $mark = $pdo->prepare('UPDATE financial_external_transactions SET matched_transaction_id = ?, reconciliation_status = ?, updated_at = ? WHERE id = ?');
            foreach ($externalRows as $row) {
                $best = null;
                $bestScore = 0.0;
                $ties = 0;
                foreach ($internalRows as $internal) {
                    $score = of_match_score($row, $internal);
                    if ($score['score'] > $bestScore) {
                        $bestScore = $score['score'];
                        $best = $internal;
                        $ties = 1;
                    } elseif ($best !== null && abs($score['score'] - $bestScore) < 0.01 && $score['score'] >= 60.0) {
                        $ties++;
                    }
                }

                $eligible = $best !== null
                    && $bestScore >= 70.0
                    && (float) ($row['amount'] ?? 0) != 0.0
                    && abs(of_date_diff_days((string) ($row['transaction_date'] ?? ''), (string) ($best['transaction_date'] ?? ''))) <= 10;

                if ($eligible && $ties === 1) {
                    $matched++;
                    $mark->execute([(int) $best['id'], 'matched', $now, (int) $row['id']]);
                } elseif ($eligible && $ties > 1) {
                    $ambiguous++;
                    $mark->execute([null, 'needs_review', $now, (int) $row['id']]);
                } else {
                    $unmatched++;
                    $mark->execute([null, 'pending', $now, (int) $row['id']]);
                }
            }

            $stmt = $pdo->prepare('INSERT INTO financial_reconciliations (instance_id, provider_id, external_account_id, reconciliation_date, opened_count, matched_count, unmatched_count, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $notes = trim((string) ($_POST['notes'] ?? ''));
            if ($ambiguous > 0) {
                $notes = trim($notes . ' ' . sprintf('[%d itens precisam revisão]', $ambiguous));
            }
            $stmt->execute([$instanceId, $providerId, $externalAccountId, date('Y-m-d'), count($externalRows), $matched, $unmatched + $ambiguous, $notes, $now, $now]);
            $audit->log($instanceId, $auth->userId(), 'openfinance_reconciliation', 'financial_reconciliations', (string) $pdo->lastInsertId(), [], [
                'opened_count' => count($externalRows),
                'matched_count' => $matched,
                'unmatched_count' => $unmatched + $ambiguous,
            ]);
            $message = sprintf('Conciliação executada: %d pareadas, %d pendentes, %d para revisão.', $matched, $unmatched, $ambiguous);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Open Finance</title>
<?= bootstrap_assets() ?>
<link rel="stylesheet" href="<?= e(base_path('assets/ui.css')) ?>">
</head>
<body>
<div class="wrap">
  <?php financial_nav($instanceId, 'openfinance'); ?>
  <div class="card hero">
    <div class="tag">Open Finance · base preparada</div>
    <h1 class="headline">Open Finance, sem pressa e sem quebrar o que já funciona</h1>
    <p class="muted">Aqui a gente prepara provedores, contas externas, importação de extratos e conciliação. A estrutura já aguenta evolução real sem obrigar ninguém a começar com banco conectado.</p>
    <?php if ($message): ?><div class="toast good"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="toast bad"><?= e($error) ?></div><?php endif; ?>
    <div class="actions" style="margin-top:14px">
      <a class="btn btn-primary" href="#provedores">Provedores</a>
      <a class="btn btn-secondary" href="#contas">Contas</a>
      <a class="btn btn-secondary" href="#conciliacao">Conciliação</a>
    </div>
  </div>

  <div class="card">
    <h2 class="mb-1">Fluxo simples</h2>
    <p class="muted mb-3">Se você só quer começar rápido, siga esta ordem: crie um provedor, crie uma conta externa e depois concilie.</p>
    <div class="d-flex flex-wrap gap-2">
      <a class="btn btn-primary" href="#provedores">1. Provedor</a>
      <a class="btn btn-secondary" href="#contas">2. Conta</a>
      <a class="btn btn-secondary" href="#conciliacao">3. Conciliação</a>
    </div>
  </div>

  <div class="grid">
    <div class="card enter" id="provedores">
      <details>
        <summary class="tag" style="cursor:pointer">Provedores</summary>
        <div style="margin-top:14px">
      <form method="post" class="split">
        <input type="hidden" name="action" value="create_provider">
        <label>Nome<input type="text" name="name" required></label>
        <label>Slug<input type="text" name="slug" required></label>
        <label>Tipo
          <select name="provider_type">
            <option value="openfinance">openfinance</option>
            <option value="manual">manual</option>
            <option value="csv">csv</option>
          </select>
        </label>
        <label>Status
          <select name="status">
            <option value="draft">draft</option>
            <option value="active">active</option>
            <option value="paused">paused</option>
          </select>
        </label>
        <label>Ambiente
          <select name="environment">
            <option value="sandbox">sandbox</option>
            <option value="production">production</option>
          </select>
        </label>
        <label>Consentimento<input type="text" name="consent_reference"></label>
        <button class="btn btn-primary" type="submit">Criar provedor</button>
      </form>
      <div class="list">
        <?php foreach ($providers as $provider): ?>
          <div class="member">
            <div class="meta">
              <strong><?= e($provider['name']) ?></strong>
              <span class="muted"><?= e($provider['slug']) ?> · <?= e($provider['environment']) ?> · <?= e($provider['status']) ?></span>
            </div>
            <span class="tag"><?= e($provider['provider_type']) ?></span>
          </div>
        <?php endforeach; ?>
        <?php if (!$providers): ?><p class="muted">Nenhum provedor cadastrado ainda.</p><?php endif; ?>
      </div>
        </div>
      </details>
    </div>

  <div class="card enter" id="contas">
      <details>
        <summary class="tag" style="cursor:pointer">Contas externas</summary>
        <div style="margin-top:14px">
      <form method="post" class="split">
        <input type="hidden" name="action" value="create_external_account">
        <label>Provedor
          <select name="provider_id" required>
            <?php foreach ($providers as $provider): ?><option value="<?= (int) $provider['id'] ?>"><?= e($provider['name']) ?></option><?php endforeach; ?>
          </select>
        </label>
        <label>ID externo<input type="text" name="external_account_id" required></label>
        <label>Nome<input type="text" name="account_name" required></label>
        <label>Tipo
          <select name="account_type">
            <option value="checking">checking</option>
            <option value="savings">savings</option>
            <option value="credit">credit</option>
            <option value="investment">investment</option>
          </select>
        </label>
        <label>Instituição<input type="text" name="institution_name"></label>
        <label>Agência<input type="text" name="branch_code"></label>
        <label>Conta<input type="text" name="account_number"></label>
        <label>Moeda<input type="text" name="currency" value="BRL"></label>
        <label>Saldo inicial<input type="number" step="0.01" name="balance" value="0"></label>
        <label>Conta interna vinculada
          <select name="linked_account_id">
            <option value="">Nenhuma</option>
            <?php foreach ($internalAccounts as $account): ?><option value="<?= (int) $account['id'] ?>"><?= e($account['name']) ?></option><?php endforeach; ?>
          </select>
        </label>
        <button class="btn btn-primary" type="submit">Criar conta externa</button>
      </form>
      <div class="list">
        <?php foreach ($externalAccounts as $account): ?>
          <div class="member">
            <div class="meta">
              <strong><?= e($account['account_name']) ?></strong>
              <span class="muted"><?= e($account['provider_name']) ?> · <?= e($account['external_account_id']) ?> · <?= e((string) $account['institution_name']) ?></span>
            </div>
            <span class="tag">R$ <?= number_format((float) $account['balance'], 2, ',', '.') ?></span>
          </div>
        <?php endforeach; ?>
        <?php if (!$externalAccounts): ?><p class="muted">Nenhuma conta externa ainda.</p><?php endif; ?>
      </div>
        </div>
      </details>
    </div>
  </div>

  <div class="card enter">
    <details>
      <summary class="tag" style="cursor:pointer">Importar extrato</summary>
      <div style="margin-top:14px">
    <form method="post" class="split">
      <input type="hidden" name="action" value="import_statement">
      <label>Provedor
        <select name="provider_id" required>
          <?php foreach ($providers as $provider): ?><option value="<?= (int) $provider['id'] ?>"><?= e($provider['name']) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>Conta externa
        <select name="external_account_id" required>
          <?php foreach ($externalAccounts as $account): ?><option value="<?= (int) $account['id'] ?>"><?= e($account['account_name']) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>Extrato JSON
        <textarea name="raw_payload_json" rows="8" placeholder='[{"id":"x1","date":"2026-06-10","description":"Pix recebido","amount":150}]'></textarea>
      </label>
      <button class="btn btn-primary" type="submit">Importar transações</button>
    </form>
      </div>
    </details>
  </div>

  <div class="card enter" id="conciliacao">
    <details open>
      <summary class="tag" style="cursor:pointer">Conciliação inteligente</summary>
      <div style="margin-top:14px">
    <form method="post" class="split">
      <input type="hidden" name="action" value="run_reconciliation">
      <label>Provedor
        <select name="provider_id" required>
          <?php foreach ($providers as $provider): ?><option value="<?= (int) $provider['id'] ?>"><?= e($provider['name']) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>Conta externa
        <select name="external_account_id" required>
          <?php foreach ($externalAccounts as $account): ?><option value="<?= (int) $account['id'] ?>"><?= e($account['account_name']) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>Conta interna vinculada
        <select name="linked_account_id" required>
          <?php foreach ($internalAccounts as $account): ?><option value="<?= (int) $account['id'] ?>"><?= e($account['name']) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>Notas<input type="text" name="notes"></label>
      <button class="btn btn-secondary" type="submit">Executar conciliação</button>
    </form>
    <div class="list">
      <?php foreach ($reconciliations as $recon): ?>
        <div class="member">
          <div class="meta">
            <strong><?= e($recon['provider_name']) ?> · <?= e($recon['account_name']) ?></strong>
            <span class="muted"><?= e($recon['reconciliation_date']) ?> · abertas <?= (int) $recon['opened_count'] ?> · pareadas <?= (int) $recon['matched_count'] ?> · pendentes <?= (int) $recon['unmatched_count'] ?></span>
          </div>
          <span class="tag">conciliação</span>
        </div>
      <?php endforeach; ?>
      <?php if (!$reconciliations): ?><p class="muted">Ainda não há conciliações registradas.</p><?php endif; ?>
    </div>
      </div>
    </details>
  </div>

  <div class="grid">
    <div class="card enter">
      <details>
        <summary class="tag" style="cursor:pointer">Transações importadas</summary>
        <div style="margin-top:14px">
      <div class="list">
        <?php foreach ($externalTransactions as $tx): ?>
          <div class="member">
            <div class="meta">
              <strong><?= e($tx['description']) ?></strong>
              <span class="muted"><?= e($tx['provider_name']) ?> · <?= e($tx['transaction_date']) ?> · <?= e($tx['reconciliation_status']) ?></span>
            </div>
            <span class="tag">R$ <?= number_format((float) $tx['amount'], 2, ',', '.') ?></span>
          </div>
        <?php endforeach; ?>
        <?php if (!$externalTransactions): ?><p class="muted">Sem transações importadas ainda.</p><?php endif; ?>
      </div>
        </div>
      </details>
    </div>

    <div class="card enter">
      <details>
        <summary class="tag" style="cursor:pointer">Transações internas</summary>
        <div style="margin-top:14px">
      <div class="list">
        <?php foreach ($transactions as $tx): ?>
          <div class="member">
            <div class="meta">
              <strong><?= e($tx['description']) ?></strong>
              <span class="muted"><?= e($tx['transaction_date']) ?> · <?= e($tx['status']) ?> · conta #<?= (int) $tx['account_id'] ?></span>
            </div>
            <span class="tag">R$ <?= number_format((float) $tx['amount'], 2, ',', '.') ?></span>
          </div>
        <?php endforeach; ?>
      </div>
        </div>
      </details>
    </div>
  </div>
</div>
</body>
</html>
