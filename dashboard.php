<?php
require __DIR__ . '/bootstrap.php';

$userId = $auth->requireLogin();
$stmt = $pdo->prepare('SELECT id, name, email FROM users WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch();

$instances = $pdo->prepare('
    SELECT i.id, i.name, i.slug, m.role
    FROM instances i
    INNER JOIN instance_members m ON m.instance_id = i.id
    WHERE m.user_id = ?
    ORDER BY i.name ASC
');
$instances->execute([$userId]);
$instances = $instances->fetchAll();
?>
<!doctype html>
<html lang="pt-br">
<head><meta charset="utf-8"><title>Dashboard - Financeiro</title></head>
<body>
<h1>Olá, <?= htmlspecialchars($user['name']) ?></h1>
<p><?= htmlspecialchars($user['email']) ?></p>
<h2>Suas instâncias</h2>
<ul>
<?php foreach ($instances as $instance): ?>
  <li><?= htmlspecialchars($instance['name']) ?> - <?= htmlspecialchars($instance['role']) ?></li>
<?php endforeach; ?>
</ul>
</body>
</html>
