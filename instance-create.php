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
            header('Location: /dashboard.php');
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
<head><meta charset="utf-8"><title>Nova instância - Financeiro</title></head>
<body>
<h1>Criar instância</h1>
<?php if ($error): ?><p><?= htmlspecialchars($error) ?></p><?php endif; ?>
<form method="post">
  <label>Nome <input type="text" name="name" required></label><br>
  <button type="submit">Criar</button>
</form>
</body>
</html>
