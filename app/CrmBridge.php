<?php
declare(strict_types=1);

final class CrmBridge
{
    private ?PDO $crmPdo = null;
    private ?array $crmConfig = null;

    public function __construct(private PDO $financePdo)
    {
    }

    public function integrationRow(int $instanceId): array
    {
        $stmt = $this->financePdo->prepare('SELECT * FROM financial_integrations WHERE instance_id = ? AND provider = ? LIMIT 1');
        $stmt->execute([$instanceId, 'crm']);
        $row = $stmt->fetch();
        if ($row) {
            return $row;
        }

        $now = date('Y-m-d H:i:s');
        $stmt = $this->financePdo->prepare('INSERT INTO financial_integrations (instance_id, provider, enabled, config_json, last_sync_status, created_at, updated_at) VALUES (?, ?, 0, ?, "never", ?, ?)');
        $stmt->execute([$instanceId, 'crm', '{}', $now, $now]);

        return [
            'id' => (int) $this->financePdo->lastInsertId(),
            'instance_id' => $instanceId,
            'provider' => 'crm',
            'enabled' => 0,
            'config_json' => '{}',
            'last_sync_at' => null,
            'last_sync_status' => 'never',
            'last_sync_message' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    public function isEnabled(int $instanceId): bool
    {
        return (int) ($this->integrationRow($instanceId)['enabled'] ?? 0) === 1;
    }

    public function saveSettings(int $instanceId, bool $enabled, array $config = []): void
    {
        $now = date('Y-m-d H:i:s');
        $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $stmt = $this->financePdo->prepare('
            INSERT INTO financial_integrations (instance_id, provider, enabled, config_json, last_sync_status, created_at, updated_at)
            VALUES (?, ?, ?, ?, "never", ?, ?)
            ON CONFLICT(instance_id, provider)
            DO UPDATE SET enabled = excluded.enabled, config_json = excluded.config_json, updated_at = excluded.updated_at
        ');
        $stmt->execute([$instanceId, 'crm', $enabled ? 1 : 0, $json, $now, $now]);
    }

    public function syncAppointments(int $instanceId): array
    {
        if (!$this->isEnabled($instanceId)) {
            return ['ok' => false, 'message' => 'Integração CRM desativada.', 'imported' => 0, 'updated' => 0];
        }

        $crm = $this->crm();
        if (!$crm) {
            return ['ok' => false, 'message' => 'CRM local não configurado ou indisponível.', 'imported' => 0, 'updated' => 0];
        }

        $rows = [];
        foreach ([
            [
                'table' => 'leads',
                'prefix' => 'lead:',
                'sql' => 'SELECT id, nome, telefone, interesse, valor, status, etapa_funil, data_ultimo_contato, created_at FROM leads ORDER BY id DESC LIMIT 150',
            ],
            [
                'table' => 'crm_whatsapp_clientes',
                'prefix' => 'whatsapp:',
                'sql' => 'SELECT id, nome, numero, interesse, valor, status, etapa, data_ultimo_contato, created_at FROM crm_whatsapp_clientes ORDER BY id DESC LIMIT 150',
            ],
        ] as $source) {
            try {
                $sourceRows = $crm->query($source['sql'])->fetchAll();
            } catch (Throwable) {
                $sourceRows = [];
            }

            foreach ($sourceRows as $row) {
                $rows[] = [
                    'external_appointment_id' => $source['prefix'] . $row['id'],
                    'client_name' => $row['nome'] ?? '',
                    'client_phone' => $row['telefone'] ?? ($row['numero'] ?? ''),
                    'service_name' => $row['interesse'] ?? 'CRM',
                    'expected_amount' => $row['valor'] ?? 0,
                    'lead_status' => $row['status'] ?? '',
                    'etapa_funil' => $row['etapa_funil'] ?? ($row['etapa'] ?? ''),
                    'data_ultimo_contato' => $row['data_ultimo_contato'] ?? null,
                    'created_at' => $row['created_at'] ?? null,
                ];
            }
        }

        $find = $this->financePdo->prepare('SELECT id FROM financial_service_appointments WHERE instance_id = ? AND external_provider = ? AND external_appointment_id = ? LIMIT 1');
        $update = $this->financePdo->prepare('
            UPDATE financial_service_appointments
            SET appointment_date = ?, client_name = ?, service_name = ?, expected_amount = ?, signal_amount = ?, remaining_amount = ?, status = ?, source = ?, external_provider = ?, external_appointment_id = ?, client_id = ?, lead_id = ?, notes = ?, updated_at = ?
            WHERE id = ?
        ');
        $insert = $this->financePdo->prepare('
            INSERT INTO financial_service_appointments
                (instance_id, appointment_date, client_name, service_name, expected_amount, signal_amount, remaining_amount, status, source, external_provider, external_appointment_id, client_id, lead_id, notes, created_at, updated_at)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');

        $imported = 0;
        $updated = 0;

        foreach ($rows as $row) {
            $appointmentDate = (string) ($row['data_ultimo_contato'] ?? $row['created_at'] ?? date('Y-m-d'));
            $clientName = trim((string) ($row['client_name'] ?? ''));
            $serviceName = trim((string) ($row['service_name'] ?? 'CRM lead'));
            $expected = (float) ($row['expected_amount'] ?? 0);
            $signal = max(0.0, min($expected, $expected * 0.3));
            $remaining = max(0.0, $expected - $signal);
            $status = $this->mapStatus((string) ($row['lead_status'] ?? '') . ' ' . (string) ($row['etapa_funil'] ?? ''));
            $notes = trim('Lead CRM: ' . (string) ($row['client_phone'] ?? ''));
            $externalId = (string) ($row['external_appointment_id'] ?? '');

            $find->execute([$instanceId, 'crm-local', $externalId]);
            $existingId = (int) $find->fetchColumn();

            if ($existingId > 0) {
                $update->execute([
                    $appointmentDate,
                    $clientName,
                    $serviceName,
                    $expected,
                    $signal,
                    $remaining,
                    $status,
                    'crm',
                    'crm-local',
                    $externalId,
                    null,
                    null,
                    $notes,
                    date('Y-m-d H:i:s'),
                    $existingId,
                ]);
                $updated++;
                continue;
            }

            $insert->execute([
                $instanceId,
                $appointmentDate,
                $clientName !== '' ? $clientName : 'Lead CRM',
                $serviceName,
                $expected,
                $signal,
                $remaining,
                $status,
                'crm',
                'crm-local',
                $externalId,
                null,
                null,
                $notes,
                date('Y-m-d H:i:s'),
                date('Y-m-d H:i:s'),
            ]);
            $imported++;
        }

        $this->touchIntegration($instanceId, true, sprintf('Sincronização concluída: %d importados, %d atualizados.', $imported, $updated));

        return [
            'ok' => true,
            'message' => sprintf('CRM sincronizado: %d importados, %d atualizados.', $imported, $updated),
            'imported' => $imported,
            'updated' => $updated,
        ];
    }

    public function probe(): array
    {
        $crm = $this->crm();
        if (!$crm) {
            return ['ok' => false, 'message' => 'CRM local indisponível ou sem credenciais.'];
        }

        $stats = [];
        foreach (['leads', 'crm_whatsapp_clientes'] as $table) {
            try {
                $stats[$table] = (int) $crm->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
            } catch (Throwable) {
                $stats[$table] = null;
            }
        }

        return [
            'ok' => true,
            'message' => 'Conexão com CRM local disponível.',
            'database' => $this->crmConfig()['database'] ?? null,
            'stats' => $stats,
        ];
    }

    private function touchIntegration(int $instanceId, bool $ok, string $message): void
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->financePdo->prepare('UPDATE financial_integrations SET last_sync_at = ?, last_sync_status = ?, last_sync_message = ?, updated_at = ? WHERE instance_id = ? AND provider = ?');
        $stmt->execute([$now, $ok ? 'success' : 'error', $message, $now, $instanceId, 'crm']);
    }

    private function mapStatus(string $crmStatus): string
    {
        $status = mb_strtolower(trim($crmStatus));
        return match (true) {
            str_contains($status, 'ganho'),
            str_contains($status, 'fechado'),
            str_contains($status, 'conclu') => 'done',
            str_contains($status, 'perd') => 'canceled',
            str_contains($status, 'negoci'),
            str_contains($status, 'contato'),
            str_contains($status, 'reuni') => 'confirmed',
            default => 'planned',
        };
    }

    private function crm(): ?PDO
    {
        if ($this->crmPdo instanceof PDO) {
            return $this->crmPdo;
        }

        $config = $this->crmConfig();
        if ($config === null) {
            return null;
        }

        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['database']),
                $config['username'],
                $config['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
            $this->crmPdo = $pdo;
            return $pdo;
        } catch (Throwable) {
            return null;
        }
    }

    private function crmConfig(): ?array
    {
        if ($this->crmConfig !== null) {
            return $this->crmConfig;
        }

        $config = [
            'host' => getenv('CRM_DB_HOST') ?: null,
            'database' => getenv('CRM_DB_NAME') ?: null,
            'username' => getenv('CRM_DB_USER') ?: null,
            'password' => getenv('CRM_DB_PASS') ?: null,
        ];

        $localPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'crm' . DIRECTORY_SEPARATOR . 'config.local.php';
        if (file_exists($localPath)) {
            $loaded = require $localPath;
            if (is_array($loaded)) {
                $config = array_merge($config, $loaded);
            }
        }

        foreach (['host', 'database', 'username'] as $field) {
            if (empty($config[$field])) {
                $this->crmConfig = null;
                return null;
            }
        }

        $this->crmConfig = [
            'host' => (string) $config['host'],
            'database' => (string) $config['database'],
            'username' => (string) $config['username'],
            'password' => (string) ($config['password'] ?? ''),
        ];

        return $this->crmConfig;
    }
}
