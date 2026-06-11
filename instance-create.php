<?php
require __DIR__ . '/bootstrap.php';

$userId = $auth->requireLogin();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        $error = 'Nome da instância é obrigatório.';
    } else {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? '');
        $slug = trim($slug, '-') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO instances (owner_user_id, name, slug, created_at) VALUES (?, ?, ?, datetime("now"))');
            $stmt->execute([$userId, $name, $slug]);
            $instanceId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare('INSERT INTO instance_members (instance_id, user_id, role, created_at) VALUES (?, ?, "owner", datetime("now"))');
            $stmt->execute([$instanceId, $userId]);

            $pdo->commit();
            header('Location: ' . base_path('financial.php?instance_id=' . $instanceId));
            exit;
        } catch (Throwable $e) {
            $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Nova instância - Financeiro</title>
<?= bootstrap_assets() ?>
<link rel="stylesheet" href="<?= e(base_path('assets/ui.css')) ?>">
</head>
<body class="bg-body-tertiary">
<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-12 col-md-10 col-lg-7">
      <div class="card shadow-sm border-0 rounded-4">
        <div class="card-body p-4 p-lg-5">
          <span class="badge rounded-pill text-bg-primary-subtle text-primary-emphasis mb-3">Organização</span>
          <h1 class="h2 fw-bold mb-2">Criar nova instância</h1>
          <p class="text-body-secondary mb-4">Cada instância funciona como um espaço separado dentro do sistema.</p>
          <?php if ($error): ?><div class="alert alert-danger rounded-4"><?= e($error) ?></div><?php endif; ?>
          <form method="post" class="vstack gap-3">
            <div>
              <label class="form-label">Nome da instância</label>
              <input type="text" name="name" class="form-control form-control-lg" required>
            </div>
            <div class="d-flex gap-2">
              <button class="btn btn-primary btn-lg" type="submit">Criar</button>
              <a class="btn btn-outline-secondary btn-lg" href="<?= e(base_path('dashboard.php?view=chooser')) ?>">Voltar</a>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>
