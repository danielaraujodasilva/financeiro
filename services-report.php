<?php
require __DIR__ . '/bootstrap.php';
$instanceId=(int)($_GET['instance_id']??0);
if(!$instanceId) exit('Instância obrigatória.');
$auth->requireInstanceAccess($instanceId);
$appointments=$financial->appointments($instanceId);
$paid = 0; $pending = 0; $signals = 0;
foreach ($appointments as $a) { if ($a['status']==='done') $paid += (float)$a['expected_amount']; if ($a['status']!=='done' && $a['status']!=='canceled') $pending += (float)$a['remaining_amount']; $signals += (float)$a['signal_amount']; }
?><!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Serviços</title><link rel="stylesheet" href="<?= e(base_path('assets/ui.css')) ?>"></head><body><div class="wrap"><?php financial_nav($instanceId,'services'); ?><div class="card"><h1 class="headline">Relatório de serviços</h1><div class="statbar"><div class="stat"><span class="muted">Receita por serviços concluídos</span><strong>R$ <?= number_format($paid,2,',','.') ?></strong></div><div class="stat"><span class="muted">Sinais recebidos</span><strong>R$ <?= number_format($signals,2,',','.') ?></strong></div><div class="stat"><span class="muted">Pendentes</span><strong>R$ <?= number_format($pending,2,',','.') ?></strong></div></div></div></div></body></html>
