<?php
require __DIR__ . '/bootstrap.php';

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($auth->login(trim($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''))) {
        header('Location: /dashboard.php');
        exit;
    }
    $error = 'Email ou senha inválidos.';
}
?>
<!doctype html>
<html lang="pt-br">
<head><meta charset="utf-8"><title>Login - Financeiro</title></head>
<body>
<h1>Entrar</h1>
<?php if ($error): ?><p><?= htmlspecialchars($error) ?></p><?php endif; ?>
<form method="post">
  <label>Email <input type="email" name="email" required></label><br>
  <label>Senha <input type="password" name="password" required></label><br>
  <button type="submit">Entrar</button>
</form>
</body>
</html>
