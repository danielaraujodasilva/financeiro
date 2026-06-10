<?php
require __DIR__ . '/bootstrap.php';
$instanceId=(int)($_GET['instance_id']??0);
if(!$instanceId) exit('Instância obrigatória.');
$auth->requireInstanceAccess($instanceId);
$instance=$auth->instanceById($instanceId);
$accounts=$financial->accounts($instanceId);
$centers=$financial->centers($instanceId);
$categories=$financial->categories($instanceId);
$message=$error=null;
$simulation=null;
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='simulate'){
    $amount=(float)($_POST['amount']??0);
    $installments=max(1,(int)($_POST['installments_count']??1));
    $months=max(1,$installments);
    $balance=array_sum(array_map(fn($a)=>(float)$a['current_balance'],$accounts));
    $monthlyImpact=round($amount/$installments,2);
    $next30 = round($amount / max(1, min($months, 1)), 2);
    $next90 = round($amount / max(1, min($months, 3)), 2);
    $survivalAfter = $months > 0 ? max(0, floor(($balance - $amount) / max($monthlyImpact, 1))) : 0;
    $simulation=[
        'monthlyImpact'=>$monthlyImpact,
        'currentBalance'=>$balance,
        'projectedAfter'=>$balance-$amount,
        'next30'=>$next30,
        'next90'=>$next90,
        'survivalAfter'=>$survivalAfter,
        'risk'=>$amount > $balance ? 'alto' : ($installments>6 ? 'médio' : 'baixo'),
    ];
}
?><!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Simulador</title><link rel="stylesheet" href="<?= e(base_path('assets/ui.css')) ?>"></head><body><div class="wrap"><?php financial_nav($instanceId,'simulator'); ?><div class="card"><h1 class="headline">Simulador de decisão</h1><form method="post" class="split"><input type="hidden" name="action" value="simulate"><label>Valor<input name="amount" type="number" step="0.01"></label><label>À vista ou parcelado<select name="installments_count"><option value="1">À vista</option><option value="2">2x</option><option value="3">3x</option><option value="6">6x</option><option value="12">12x</option></select></label><label>Centro<select name="center_id"><?php foreach($centers as $c):?><option><?= e($c['name']) ?></option><?php endforeach; ?></select></label><label>Categoria<select name="category_id"><?php foreach($categories as $c):?><option><?= e($c['name']) ?></option><?php endforeach; ?></select></label><button class="btn btn-primary">Simular</button></form><?php if($simulation): ?><div class="statbar"><div class="stat"><span class="muted">Saldo atual</span><strong>R$ <?= number_format($simulation['currentBalance'],2,',','.') ?></strong></div><div class="stat"><span class="muted">Impacto mensal</span><strong>R$ <?= number_format($simulation['monthlyImpact'],2,',','.') ?></strong></div><div class="stat"><span class="muted">Após compra</span><strong>R$ <?= number_format($simulation['projectedAfter'],2,',','.') ?></strong></div><div class="stat"><span class="muted">Risco</span><strong><?= e($simulation['risk']) ?></strong></div></div><?php endif; ?></div></div></body></html>
