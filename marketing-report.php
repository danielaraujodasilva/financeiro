<?php
require __DIR__ . '/bootstrap.php';
$instanceId=(int)($_GET['instance_id']??0);
if(!$instanceId) exit('Instância obrigatória.');
$auth->requireInstanceAccess($instanceId);
$reports=$financial->marketingReports($instanceId);
$message=$error=null;
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='create'){
    try{
        $stmt=$pdo->prepare('INSERT INTO financial_marketing_reports (instance_id, report_date, campaign_name, spend_amount, leads_received, clients_closed, revenue_generated, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$instanceId, $_POST['report_date'], trim($_POST['campaign_name']), (float)$_POST['spend_amount'], (int)$_POST['leads_received'], (int)$_POST['clients_closed'], (float)$_POST['revenue_generated'], trim($_POST['notes']??''), date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
        $message='Relatório de marketing salvo.';
    }catch(Throwable $e){$error=$e->getMessage();}
}
?><!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Marketing</title><link rel="stylesheet" href="<?= e(base_path('assets/ui.css')) ?>"></head><body><div class="wrap"><?php financial_nav($instanceId,'marketing'); ?><div class="card"><h1 class="headline">Relatório de marketing</h1><p class="muted">Módulo opcional e funcional para acompanhar anúncios, leads e retorno. Se o CRM existir, depois podemos automatizar a importação.</p><?php if($message):?><div class="toast good"><?= e($message) ?></div><?php endif; if($error):?><div class="toast bad"><?= e($error) ?></div><?php endif; ?><form method="post" class="split"><input type="hidden" name="action" value="create"><label>Data<input type="date" name="report_date" value="<?= date('Y-m-d') ?>"></label><label>Campanha<input type="text" name="campaign_name"></label><label>Investimento<input type="number" step="0.01" name="spend_amount"></label><label>Leads<input type="number" name="leads_received"></label><label>Clientes fechados<input type="number" name="clients_closed"></label><label>Receita gerada<input type="number" step="0.01" name="revenue_generated"></label><label>Observações<input type="text" name="notes"></label><button class="btn btn-primary">Salvar</button></form></div><div class="card"><div class="list"><?php foreach($reports as $r): ?><div class="member"><div class="meta"><strong><?= e($r['campaign_name']) ?></strong><span class="muted"><?= e($r['report_date']) ?> · Leads <?= (int)$r['leads_received'] ?> · Fechados <?= (int)$r['clients_closed'] ?></span></div><span class="tag">R$ <?= number_format((float)$r['revenue_generated'],2,',','.') ?></span></div><?php endforeach; ?></div></div></div></body></html>
