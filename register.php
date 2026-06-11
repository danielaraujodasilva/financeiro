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
<link rel="stylesheet" href="<?= e(base_path('assets/ui.css')) ?>">
</head>
<body>
<div class="wrap">
  <div class="card enter" style="max-width:560px;margin:6vh auto 0">
    <div class="tag">Nova conta</div>
    <h1 class="headline">Crie seu acesso</h1>
    <p class="muted">Depois você pode criar sua primeira instância e convidar pessoas para uma conta conjunta.</p>
    <?php if ($error): ?><div class="toast bad"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
      <label>Nome
        <input type="text" name="name" required>
      </label>
      <label>Email
        <input type="email" name="email" required>
      </label>
      <label>Senha
        <input type="password" name="password" required>
      </label>
      <button class="btn btn-primary" type="submit">Criar conta</button>
    </form>
    <p class="note">Já tem conta? <a href="<?= e(base_path('login.php')) ?>">Entrar</a></p>
  </div>
</div>
</body>
</html>
