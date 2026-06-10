<?php
require __DIR__ . '/bootstrap.php';

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($auth->login(trim($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''))) {
        header('Location: ' . base_path('dashboard.php'));
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
<link rel="stylesheet" href="<?= e(base_path('assets/ui.css')) ?>">
</head>
<body>
<div class="wrap">
  <div class="card enter" style="max-width:520px;margin:8vh auto 0">
    <div class="tag">Acesso seguro</div>
    <h1 class="headline">Entrar no Financeiro</h1>
    <p class="muted">Sua conta e suas instâncias permanecem separadas e protegidas.</p>
    <?php if ($error): ?><div class="toast bad"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
      <label>Email
        <input type="email" name="email" required>
      </label>
      <label>Senha
        <input type="password" name="password" required>
      </label>
      <button class="btn btn-primary" type="submit">Entrar</button>
    </form>
    <p class="note">Ainda não tem conta? <a href="<?= e(base_path('register.php')) ?>">Criar agora</a></p>
  </div>
</div>
</body>
</html>
