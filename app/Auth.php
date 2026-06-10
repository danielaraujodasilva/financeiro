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

    public function currentUser(): ?array
    {
        $id = $this->userId();
        if (!$id) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT id, name, email, created_at FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    public function hasInstanceAccess(int $userId, int $instanceId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM instance_members WHERE user_id = ? AND instance_id = ?');
        $stmt->execute([$userId, $instanceId]);
        return (bool) $stmt->fetchColumn();
    }

    public function requireInstanceAccess(int $instanceId): int
    {
        $userId = $this->requireLogin();
        if (!$this->hasInstanceAccess($userId, $instanceId)) {
            http_response_code(403);
            echo 'Acesso negado.';
            exit;
        }

        return $userId;
    }

    public function instancesForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT i.id, i.name, i.slug, m.role, i.owner_user_id
            FROM instances i
            INNER JOIN instance_members m ON m.instance_id = i.id
            WHERE m.user_id = ?
            ORDER BY i.name ASC
        ');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function createInstance(int $ownerUserId, string $name): int
    {
        $slugBase = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? '');
        $slugBase = trim($slugBase, '-');
        $slug = $slugBase . '-' . substr(bin2hex(random_bytes(3)), 0, 6);

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('INSERT INTO instances (owner_user_id, name, slug, created_at) VALUES (?, ?, ?, datetime("now"))');
            $stmt->execute([$ownerUserId, $name, $slug]);
            $instanceId = (int) $this->pdo->lastInsertId();

            $stmt = $this->pdo->prepare('INSERT INTO instance_members (instance_id, user_id, role, created_at) VALUES (?, ?, "owner", datetime("now"))');
            $stmt->execute([$instanceId, $ownerUserId]);

            $this->pdo->commit();
            return $instanceId;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function inviteMember(int $instanceId, string $email): string
    {
        $token = bin2hex(random_bytes(24));
        $stmt = $this->pdo->prepare('INSERT INTO invites (instance_id, email, token, status, created_at) VALUES (?, ?, ?, "pending", datetime("now"))');
        $stmt->execute([$instanceId, mb_strtolower(trim($email)), $token]);
        return $token;
    }

    public function pendingInvitesForEmail(string $email): array
    {
        $stmt = $this->pdo->prepare('
            SELECT i.id, i.instance_id, i.email, i.token, i.status, i.created_at, inst.name AS instance_name
            FROM invites i
            INNER JOIN instances inst ON inst.id = i.instance_id
            WHERE i.email = ? AND i.status = "pending"
            ORDER BY i.created_at DESC
        ');
        $stmt->execute([mb_strtolower(trim($email))]);
        return $stmt->fetchAll();
    }

    public function acceptInvite(string $token, int $userId): void
    {
        $stmt = $this->pdo->prepare('SELECT id, instance_id, email, status FROM invites WHERE token = ?');
        $stmt->execute([$token]);
        $invite = $stmt->fetch();
        if (!$invite || $invite['status'] !== 'pending') {
            throw new RuntimeException('Convite inválido.');
        }

        $stmt = $this->pdo->prepare('SELECT email FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $email = mb_strtolower((string) $stmt->fetchColumn());
        if ($email !== mb_strtolower((string) $invite['email'])) {
            throw new RuntimeException('Este convite foi enviado para outro e-mail.');
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('INSERT OR IGNORE INTO instance_members (instance_id, user_id, role, created_at) VALUES (?, ?, "member", datetime("now"))');
            $stmt->execute([(int) $invite['instance_id'], $userId]);

            $stmt = $this->pdo->prepare('UPDATE invites SET status = "accepted", accepted_at = datetime("now") WHERE id = ?');
            $stmt->execute([(int) $invite['id']]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function instanceMembers(int $instanceId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT u.id, u.name, u.email, m.role, m.created_at
            FROM instance_members m
            INNER JOIN users u ON u.id = m.user_id
            WHERE m.instance_id = ?
            ORDER BY m.role DESC, u.name ASC
        ');
        $stmt->execute([$instanceId]);
        return $stmt->fetchAll();
    }

    public function instanceById(int $instanceId): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT id, owner_user_id, name, slug, created_at
            FROM instances
            WHERE id = ?
        ');
        $stmt->execute([$instanceId]);
        $instance = $stmt->fetch();
        return $instance ?: null;
    }

    public function canManageInstance(int $userId, int $instanceId): bool
    {
        $stmt = $this->pdo->prepare('SELECT role FROM instance_members WHERE user_id = ? AND instance_id = ?');
        $stmt->execute([$userId, $instanceId]);
        return $stmt->fetchColumn() === 'owner';
    }

    public function updateMemberRole(int $instanceId, int $targetUserId, string $role, int $actingUserId): void
    {
        if (!$this->canManageInstance($actingUserId, $instanceId)) {
            throw new RuntimeException('Sem permissão.');
        }

        if (!in_array($role, ['owner', 'member'], true)) {
            throw new RuntimeException('Papel inválido.');
        }

        $this->pdo->beginTransaction();
        try {
            if ($role === 'owner') {
                $stmt = $this->pdo->prepare('UPDATE instance_members SET role = "member" WHERE instance_id = ? AND role = "owner"');
                $stmt->execute([$instanceId]);
            }

            $stmt = $this->pdo->prepare('UPDATE instance_members SET role = ? WHERE instance_id = ? AND user_id = ?');
            $stmt->execute([$role, $instanceId, $targetUserId]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function removeMember(int $instanceId, int $targetUserId, int $actingUserId): void
    {
        if (!$this->canManageInstance($actingUserId, $instanceId)) {
            throw new RuntimeException('Sem permissão.');
        }

        $stmt = $this->pdo->prepare('DELETE FROM instance_members WHERE instance_id = ? AND user_id = ? AND role <> "owner"');
        $stmt->execute([$instanceId, $targetUserId]);
    }

    public function deleteInviteById(int $inviteId, int $actingUserId): void
    {
        $stmt = $this->pdo->prepare('
            DELETE FROM invites
            WHERE id = ?
              AND instance_id IN (
                SELECT instance_id FROM instance_members WHERE user_id = ? AND role = "owner"
              )
        ');
        $stmt->execute([$inviteId, $actingUserId]);
    }

    public function resendInvite(int $inviteId, int $actingUserId): string
    {
        $stmt = $this->pdo->prepare('
            SELECT i.instance_id, i.email
            FROM invites i
            INNER JOIN instance_members m ON m.instance_id = i.instance_id
            WHERE i.id = ? AND m.user_id = ? AND m.role = "owner"
        ');
        $stmt->execute([$inviteId, $actingUserId]);
        $invite = $stmt->fetch();
        if (!$invite) {
            throw new RuntimeException('Convite não encontrado.');
        }

        $token = bin2hex(random_bytes(24));
        $stmt = $this->pdo->prepare('UPDATE invites SET token = ?, status = "pending", created_at = datetime("now"), accepted_at = NULL WHERE id = ?');
        $stmt->execute([$token, $inviteId]);
        return $token;
    }

    public function createInviteForUser(int $instanceId, string $email, int $actingUserId): string
    {
        if (!$this->canManageInstance($actingUserId, $instanceId)) {
            throw new RuntimeException('Sem permissão.');
        }

        return $this->inviteMember($instanceId, $email);
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
