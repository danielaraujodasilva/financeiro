<?php
declare(strict_types=1);

final class Audit
{
    public function __construct(private PDO $pdo)
    {
    }

    public function log(?int $instanceId, ?int $userId, string $action, string $entityType, ?string $entityId = null, array $before = [], array $after = []): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO audit_logs (instance_id, user_id, action, entity_type, entity_id, before_json, after_json, ip_address, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $instanceId,
            $userId,
            $action,
            $entityType,
            $entityId,
            $before ? json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            $after ? json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            date('Y-m-d H:i:s'),
        ]);
    }

    public function recent(int $instanceId, int $limit = 30): array
    {
        $stmt = $this->pdo->prepare('
            SELECT a.*, u.name AS user_name
            FROM audit_logs a
            LEFT JOIN users u ON u.id = a.user_id
            WHERE a.instance_id = ?
            ORDER BY a.id DESC
            LIMIT ' . (int) $limit
        );
        $stmt->execute([$instanceId]);
        return $stmt->fetchAll();
    }
}
