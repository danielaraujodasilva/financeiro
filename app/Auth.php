<?php
declare(strict_types=1);

final class Auth
{
    public function __construct(private PDO $pdo)
    {
    }

    public function register(string $name, string $email, string $password): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            throw new RuntimeException('Email já cadastrado.');
        }

        $stmt = $this->pdo->prepare('INSERT INTO users (name, email, password_hash, created_at) VALUES (?, ?, ?, datetime("now"))');
        $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
        return (int) $this->pdo->lastInsertId();
    }

    public function login(string $email, string $password): bool
    {
        $stmt = $this->pdo->prepare('SELECT id, password_hash FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        $_SESSION['user_id'] = (int) $user['id'];
        return true;
    }

    public function userId(): ?int
    {
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    public function requireLogin(): int
    {
        $id = $this->userId();
        if (!$id) {
            header('Location: /login.php');
            exit;
        }
        return $id;
    }
}
