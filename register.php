<?php
require __DIR__ . '/bootstrap.php';

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $userId = $auth->register(trim($_POST['name'] ?? ''), trim($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
        $_SESSION['user_id'] = $userId;
        header('Location: /dashboard.php');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="pt-br">
<head><meta charset="utf-8"><title>Criar conta - Financeiro</title></head>
<body>
<h1>Criar conta</h1>
<?php if ($error): ?><p><?= htmlspecialchars($error) ?></p><?php endif; ?>
<form method="post">
  <label>Nome <input type="text" name="name" required></label><br>
  <label>Email <input type="email" name="email" required></label><br>
  <label>Senha <input type="password" name="password" required></label><br>
  <button type="submit">Criar</button>
</form>
</body>
</html>
