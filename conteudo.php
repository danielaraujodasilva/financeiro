<?php
require __DIR__ . '/bootstrap.php';

$instanceId = (int) ($_GET['instance_id'] ?? 0);
if (!$instanceId) {
    http_response_code(400);
    exit('Instância obrigatória.');
}
$auth->requireInstanceAccess($instanceId);
$instance = $auth->instanceById($instanceId);
if (!$instance) {
    http_response_code(404);
    exit('Instância não encontrada.');
}

$sheetStmt = $pdo->prepare('
    SELECT
        substr(transaction_date, 1, 7) AS month_key,
        COUNT(*) AS total_items,
        SUM(CASE WHEN status = "paid" THEN amount ELSE 0 END) AS paid_amount,
        SUM(CASE WHEN status != "paid" THEN amount ELSE 0 END) AS pending_amount,
        SUM(amount) AS total_amount
    FROM financial_transactions
    WHERE instance_id = ? AND source = "spreadsheet"
    GROUP BY substr(transaction_date, 1, 7)
    ORDER BY month_key DESC
');
$sheetStmt->execute([$instanceId]);
$months = $sheetStmt->fetchAll();

$categoryStmt = $pdo->prepare('
    SELECT c.name, SUM(t.amount) AS total_amount, COUNT(*) AS total_items
    FROM financial_transactions t
    INNER JOIN financial_categories c ON c.id = t.category_id
    WHERE t.instance_id = ? AND t.source = "spreadsheet"
    GROUP BY c.name
    ORDER BY total_amount DESC
    LIMIT 8
');
$categoryStmt->execute([$instanceId]);
$topCategories = $categoryStmt->fetchAll();

$recentStmt = $pdo->prepare('
    SELECT t.*, c.name AS category_name
    FROM financial_transactions t
    INNER JOIN financial_categories c ON c.id = t.category_id
    WHERE t.instance_id = ? AND t.source = "spreadsheet"
    ORDER BY t.transaction_date DESC, t.id DESC
    LIMIT 24
');
$recentStmt->execute([$instanceId]);
$recent = $recentStmt->fetchAll();

$summaryStmt = $pdo->prepare('
    SELECT
        COUNT(*) AS total_items,
        SUM(CASE WHEN status = "paid" THEN amount ELSE 0 END) AS paid_amount,
        SUM(CASE WHEN status != "paid" THEN amount ELSE 0 END) AS pending_amount,
        SUM(amount) AS total_amount,
        COUNT(DISTINCT substr(transaction_date, 1, 7)) AS months_count
    FROM financial_transactions
    WHERE instance_id = ? AND source = "spreadsheet"
');
$summaryStmt->execute([$instanceId]);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Conteúdo - <?= e($instance['name']) ?></title>
<?= bootstrap_assets() ?>
<link rel="stylesheet" href="<?= e(base_path('assets/ui.css')) ?>">
<style>
.content-hero{
  border:1px solid rgba(15,23,42,.06);
  border-radius:28px;
  background:linear-gradient(180deg, rgba(255,255,255,.98), rgba(246,249,253,.94));
  box-shadow:0 12px 28px rgba(15,23,42,.05);
}
.content-card{
  border:1px solid rgba(15,23,42,.06);
  border-radius:22px;
  background:#fff;
  box-shadow:0 10px 20px rgba(15,23,42,.04);
}
.mini-pill{
  display:inline-flex; align-items:center; gap:6px;
  padding:6px 10px; border-radius:999px;
  background:rgba(37,99,235,.08); color:#1d4ed8; font-size:.88rem;
}
</style>
</head>
<body class="bg-body-tertiary">
<div class="container py-3 py-lg-4">
  <?php financial_nav($instanceId, 'content'); ?>

  <div class="content-hero p-3 p-lg-4 mb-3">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start align-items-lg-center gap-3">
      <div>
        <span class="mini-pill mb-2">Conteúdo importado</span>
        <h1 class="h2 fw-bold mb-1">Orçamento mensal em formato visual</h1>
        <div class="text-body-secondary">A planilha foi convertida em dados navegáveis dentro da instância.</div>
      </div>
      <a class="btn btn-outline-primary" href="<?= e(base_path('dashboard.php')) ?>">Voltar ao dashboard</a>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-12 col-md-6 col-xxl-3">
      <div class="content-card p-3 h-100">
        <div class="text-body-secondary small">Registros importados</div>
        <div class="fs-3 fw-bold"><?= number_format((int) ($summary['total_items'] ?? 0), 0, ',', '.') ?></div>
      </div>
    </div>
    <div class="col-12 col-md-6 col-xxl-3">
      <div class="content-card p-3 h-100">
        <div class="text-body-secondary small">Meses cobertos</div>
        <div class="fs-3 fw-bold"><?= number_format((int) ($summary['months_count'] ?? 0), 0, ',', '.') ?></div>
      </div>
    </div>
    <div class="col-12 col-md-6 col-xxl-3">
      <div class="content-card p-3 h-100">
        <div class="text-body-secondary small">Já pago</div>
        <div class="fs-3 fw-bold text-success">R$ <?= number_format((float) ($summary['paid_amount'] ?? 0), 2, ',', '.') ?></div>
      </div>
    </div>
    <div class="col-12 col-md-6 col-xxl-3">
      <div class="content-card p-3 h-100">
        <div class="text-body-secondary small">Pendente</div>
        <div class="fs-3 fw-bold text-warning">R$ <?= number_format((float) ($summary['pending_amount'] ?? 0), 2, ',', '.') ?></div>
      </div>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-12 col-xl-7">
      <div class="content-card p-3 p-lg-4 h-100">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div>
            <h2 class="h5 fw-bold mb-1">Resumo por mês</h2>
            <div class="text-body-secondary small">Cada linha representa um conjunto de dados importado da planilha.</div>
          </div>
        </div>
        <div class="list-group list-group-flush rounded-4 overflow-hidden">
          <?php foreach ($months as $month): ?>
            <div class="list-group-item d-flex justify-content-between align-items-center gap-3">
              <div>
                <div class="fw-semibold"><?= e($month['month_key']) ?></div>
                <div class="text-body-secondary small"><?= (int) $month['total_items'] ?> lançamentos importados</div>
              </div>
              <div class="text-end">
                <div class="small text-body-secondary">R$ <?= number_format((float) $month['total_amount'], 2, ',', '.') ?></div>
                <div class="small text-success">Pago: R$ <?= number_format((float) $month['paid_amount'], 2, ',', '.') ?></div>
                <div class="small text-warning">Pendente: R$ <?= number_format((float) $month['pending_amount'], 2, ',', '.') ?></div>
              </div>
            </div>
          <?php endforeach; ?>
          <?php if (!$months): ?>
            <div class="list-group-item text-body-secondary">Nenhum conteúdo importado ainda.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="col-12 col-xl-5">
      <div class="content-card p-3 p-lg-4 h-100">
        <h2 class="h5 fw-bold mb-3">Categorias mais fortes no conteúdo</h2>
        <div class="list-group list-group-flush rounded-4 overflow-hidden">
          <?php foreach ($topCategories as $category): ?>
            <div class="list-group-item d-flex justify-content-between align-items-center">
              <div>
                <div class="fw-semibold"><?= e($category['name']) ?></div>
                <div class="text-body-secondary small"><?= (int) $category['total_items'] ?> itens</div>
              </div>
              <span class="badge text-bg-light border">R$ <?= number_format((float) $category['total_amount'], 2, ',', '.') ?></span>
            </div>
          <?php endforeach; ?>
          <?php if (!$topCategories): ?>
            <div class="list-group-item text-body-secondary">Sem categorias importadas ainda.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="content-card p-3 p-lg-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <div>
        <h2 class="h5 fw-bold mb-1">Últimos lançamentos do conteúdo</h2>
        <div class="text-body-secondary small">Visão direta dos itens importados da planilha mensal.</div>
      </div>
    </div>
    <div class="list-group list-group-flush rounded-4 overflow-hidden">
      <?php foreach ($recent as $item): ?>
        <div class="list-group-item d-flex justify-content-between align-items-start gap-3">
          <div>
            <div class="fw-semibold"><?= e($item['description']) ?></div>
            <div class="text-body-secondary small"><?= e($item['transaction_date']) ?> · <?= e($item['category_name']) ?> · <?= e($item['status']) ?></div>
          </div>
          <span class="badge text-bg-light border rounded-pill">R$ <?= number_format((float) $item['amount'], 2, ',', '.') ?></span>
        </div>
      <?php endforeach; ?>
      <?php if (!$recent): ?>
        <div class="list-group-item text-body-secondary">Sem lançamentos importados.</div>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
