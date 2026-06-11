<?php
require __DIR__ . '/bootstrap.php';

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($auth->login(trim($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''))) {
        $userId = $auth->userId();
        $instances = $userId ? $auth->instancesForUser($userId) : [];
        if (count($instances) === 1) {
            header('Location: ' . base_path('dashboard.php'));
        } else {
            header('Location: ' . base_path('dashboard.php'));
        }
        exit;
    }
    $error = 'Email ou senha inválidos.';
}
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login - Financeiro</title>
<?= bootstrap_assets() ?>
<link rel="stylesheet" href="<?= e(base_path('assets/ui.css')) ?>">
</head>
<body class="bg-body-tertiary">
<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-12 col-md-10 col-lg-6">
      <div class="card shadow-sm border-0 rounded-4">
        <div class="card-body p-4 p-lg-5">
          <span class="badge rounded-pill text-bg-primary-subtle text-primary-emphasis mb-3">Acesso seguro</span>
          <h1 class="h2 fw-bold mb-2">Entrar no Financeiro</h1>
          <p class="text-body-secondary mb-4">Sua conta e suas instâncias permanecem separadas e protegidas.</p>
          <?php if ($error): ?><div class="alert alert-danger rounded-4"><?= e($error) ?></div><?php endif; ?>
          <form method="post" class="vstack gap-3">
            <div>
              <label class="form-label">Email</label>
              <input type="email" name="email" class="form-control form-control-lg" required>
            </div>
            <div>
              <label class="form-label">Senha</label>
              <input type="password" name="password" class="form-control form-control-lg" required>
            </div>
            <button class="btn btn-primary btn-lg w-100" type="submit">Entrar</button>
          </form>
          <div class="mt-3 small text-body-secondary">Ainda não tem conta? <a href="<?= e(base_path('register.php')) ?>">Criar agora</a></div>
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>
