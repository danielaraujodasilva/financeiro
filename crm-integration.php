<?php
require __DIR__ . '/bootstrap.php';

$instanceId = (int) ($_GET['instance_id'] ?? $_POST['instance_id'] ?? 0);
if (!$instanceId) {
    exit('Instância obrigatória.');
}

$auth->requireInstanceAccess($instanceId);
$instance = $auth->instanceById($instanceId);
$integration = $crmBridge->integrationRow($instanceId);
$probe = $crmBridge->probe();
$message = $error = null;
$integrationConfig = json_decode((string) ($integration['config_json'] ?? '{}'), true);
$integrationNotes = is_array($integrationConfig) ? (string) ($integrationConfig['notes'] ?? '') : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'save_settings') {
            $enabled = isset($_POST['enabled']) && $_POST['enabled'] === '1';
            $crmBridge->saveSettings($instanceId, $enabled, [
                'mode' => 'auto-local',
                'notes' => trim((string) ($_POST['notes'] ?? '')),
            ]);
            $audit->log($instanceId, $auth->userId(), 'crm_integration_update', 'financial_integrations', null, [], [
                'enabled' => $enabled,
                'mode' => 'auto-local',
            ]);
            $integration = $crmBridge->integrationRow($instanceId);
            $message = $enabled ? 'Integração ativada.' : 'Integração desativada.';
        } elseif ($action === 'sync_now') {
            $result = $crmBridge->syncAppointments($instanceId);
            $integration = $crmBridge->integrationRow($instanceId);
            if (!($result['ok'] ?? false)) {
                throw new RuntimeException((string) $result['message']);
            }
            $audit->log($instanceId, $auth->userId(), 'crm_integration_sync', 'financial_service_appointments', null, [], $result);
            $message = (string) $result['message'];
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$appointments = $financial->appointments($instanceId);
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CRM opcional</title>
<link rel="stylesheet" href="<?= e(base_path('assets/ui.css')) ?>">
</head>
<body>
<div class="wrap">
  <?php financial_nav($instanceId, 'crm'); ?>
  <div class="card hero">
    <div class="tag">Fase 5 · vínculo opcional</div>
    <h1 class="headline">Integração automática opcional com CRM</h1>
    <p class="muted">O sistema não depende do mesmo login. Ele tenta usar o CRM local instalado na mesma máquina/servidor, sincronizando por ID externo e, quando necessário, por dados do cliente. Se o CRM não existir ou estiver desligado, o financeiro segue funcionando normalmente.</p>
    <?php if ($message): ?><div class="toast good"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="toast bad"><?= e($error) ?></div><?php endif; ?>
  </div>

  <div class="grid">
    <div class="card enter">
      <h2>Status da conexão</h2>
      <div class="statbar">
        <div class="stat"><span class="muted">Integração</span><strong><?= (int) ($integration['enabled'] ?? 0) === 1 ? 'Ativa' : 'Desativada' ?></strong></div>
        <div class="stat"><span class="muted">CRM local</span><strong><?= ($probe['ok'] ?? false) ? 'Disponível' : 'Indisponível' ?></strong></div>
        <div class="stat"><span class="muted">Última sincronização</span><strong><?= e((string) ($integration['last_sync_at'] ?? 'Nunca')) ?></strong></div>
      </div>
      <p class="muted"><?= e((string) ($probe['message'] ?? '')) ?></p>
      <?php if (!empty($probe['stats'])): ?>
        <div class="list">
          <div class="member"><div class="meta"><strong>Leads</strong><span class="muted">Tabela `leads`</span></div><span class="tag"><?= $probe['stats']['leads'] ?? '-' ?></span></div>
          <div class="member"><div class="meta"><strong>WhatsApp</strong><span class="muted">Tabela `crm_whatsapp_clientes`</span></div><span class="tag"><?= $probe['stats']['crm_whatsapp_clientes'] ?? '-' ?></span></div>
        </div>
      <?php endif; ?>
    </div>

    <div class="card enter">
      <h2>Configuração opcional</h2>
      <form method="post" class="split">
        <input type="hidden" name="instance_id" value="<?= $instanceId ?>">
        <input type="hidden" name="action" value="save_settings">
        <label>Integração
          <select name="enabled">
            <option value="0" <?= (int) ($integration['enabled'] ?? 0) !== 1 ? 'selected' : '' ?>>Desativada</option>
            <option value="1" <?= (int) ($integration['enabled'] ?? 0) === 1 ? 'selected' : '' ?>>Ativada</option>
          </select>
        </label>
        <label>Modo
          <input type="text" value="auto-local" readonly>
        </label>
        <label>Notas
          <input type="text" name="notes" value="<?= e($integrationNotes) ?>">
        </label>
        <button class="btn btn-primary" type="submit">Salvar integração</button>
      </form>
      <form method="post" style="margin-top:16px">
        <input type="hidden" name="instance_id" value="<?= $instanceId ?>">
        <input type="hidden" name="action" value="sync_now">
        <button class="btn btn-secondary" type="submit">Sincronizar agora</button>
      </form>
    </div>
  </div>

  <div class="card enter">
    <h2>Últimos agendamentos no financeiro</h2>
    <p class="muted">Se o CRM estiver ligado, estes registros passam a ser importados/atualizados com `external_provider = crm-local` e `external_appointment_id` preenchido, puxando leads e contatos do CRM local.</p>
    <div class="list">
      <?php foreach (array_slice($appointments, 0, 10) as $a): ?>
        <div class="member">
          <div class="meta">
            <strong><?= e($a['client_name']) ?> · <?= e($a['service_name']) ?></strong>
            <span class="muted"><?= e($a['appointment_date']) ?> · <?= e($a['status']) ?> · <?= e($a['source']) ?></span>
          </div>
          <span class="tag">R$ <?= number_format((float) $a['expected_amount'], 2, ',', '.') ?></span>
        </div>
      <?php endforeach; ?>
      <?php if (!$appointments): ?><p class="muted">Ainda não há agenda sincronizada ou manual nessa instância.</p><?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
