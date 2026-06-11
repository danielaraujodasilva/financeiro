<?php
require __DIR__ . '/bootstrap.php';

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $userId = $auth->register(trim($_POST['name'] ?? ''), trim($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
        $_SESSION['user_id'] = $userId;
        header('Location: ' . base_path('instance-create.php'));
        exit;
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
<title>Criar conta - Financeiro</title>
<?= bootstrap_assets() ?>
<link rel="stylesheet" href="<?= e(base_path('assets/ui.css')) ?>">
</head>
<body class="bg-body-tertiary">
<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-12 col-md-10 col-lg-6">
      <div class="card shadow-sm border-0 rounded-4">
        <div class="card-body p-4 p-lg-5">
          <span class="badge rounded-pill text-bg-primary-subtle text-primary-emphasis mb-3">Nova conta</span>
          <h1 class="h2 fw-bold mb-2">Crie seu acesso</h1>
          <p class="text-body-secondary mb-4">Depois você pode criar sua primeira instância e convidar pessoas para uma conta conjunta.</p>
          <?php if ($error): ?><div class="alert alert-danger rounded-4"><?= e($error) ?></div><?php endif; ?>
          <form method="post" class="vstack gap-3">
            <div>
              <label class="form-label">Nome</label>
              <input type="text" name="name" class="form-control form-control-lg" required>
            </div>
            <div>
              <label class="form-label">Email</label>
              <input type="email" name="email" class="form-control form-control-lg" required>
            </div>
            <div>
              <label class="form-label">Senha</label>
              <input type="password" name="password" class="form-control form-control-lg" required>
            </div>
            <button class="btn btn-primary btn-lg w-100" type="submit">Criar conta</button>
          </form>
          <div class="mt-3 small text-body-secondary">Já tem conta? <a href="<?= e(base_path('login.php')) ?>">Entrar</a></div>
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>
