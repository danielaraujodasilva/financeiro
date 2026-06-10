<?php
require __DIR__ . '/bootstrap.php';
$instanceId=(int)($_GET['instance_id']??0);
if(!$instanceId) exit('Instância obrigatória.');
$auth->requireInstanceAccess($instanceId);
$centers=$financial->centers($instanceId);
$categories=$financial->categories($instanceId);
$accounts=$financial->accounts($instanceId);
$rules=$financial->rules($instanceId);
$message=$error=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        $action=$_POST['action']??'';
        if($action==='create'){
            $stmt=$pdo->prepare('INSERT INTO financial_rules (instance_id, match_text, match_type, transaction_type, center_id, category_id, account_id, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)');
            $stmt->execute([$instanceId, trim($_POST['match_text']), $_POST['match_type'], $_POST['transaction_type']?:null, (int)$_POST['center_id'], (int)$_POST['category_id'], $_POST['account_id']?:null, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
            $message='Regra criada.';
        }
        if($action==='apply'){
            $ruleId=(int)$_POST['rule_id'];
            $ruleStmt=$pdo->prepare('SELECT * FROM financial_rules WHERE id = ? AND instance_id = ?');
            $ruleStmt->execute([$ruleId,$instanceId]);
            $rule=$ruleStmt->fetch(PDO::FETCH_ASSOC);
            if(!$rule) throw new RuntimeException('Regra não encontrada.');
            $txStmt=$pdo->prepare('SELECT id, description FROM financial_transactions WHERE instance_id = ?');
            $txStmt->execute([$instanceId]);
            $upd=$pdo->prepare('UPDATE financial_transactions SET center_id = ?, category_id = ?, account_id = COALESCE(?, account_id), updated_at = ? WHERE id = ?');
            foreach($txStmt->fetchAll(PDO::FETCH_ASSOC) as $tx){
                $desc=mb_strtolower($tx['description']);
                $match=mb_strtolower($rule['match_text']);
                $ok=false;
                if($rule['match_type']==='contains') $ok = str_contains($desc,$match);
                elseif($rule['match_type']==='starts_with') $ok = str_starts_with($desc,$match);
                elseif($rule['match_type']==='equals') $ok = $desc === $match;
                elseif($rule['match_type']==='regex') $ok = @preg_match('/'.$rule['match_text'].'/i',$tx['description']) === 1;
                if($ok) $upd->execute([(int)$rule['center_id'], (int)$rule['category_id'], $rule['account_id'] ?: null, date('Y-m-d H:i:s'), (int)$tx['id']]);
            }
            $message='Regra aplicada aos lançamentos existentes.';
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}
?><!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Regras Smart</title><link rel="stylesheet" href="<?= e(base_path('assets/ui.css')) ?>"></head><body><div class="wrap"><?php financial_nav($instanceId,'smart_rules'); ?><div class="card"><h1 class="headline">Regras automáticas</h1><?php if($message):?><div class="toast good"><?= e($message) ?></div><?php endif; if($error):?><div class="toast bad"><?= e($error) ?></div><?php endif; ?><form method="post" class="split"><input type="hidden" name="action" value="create"><label>Texto<input name="match_text"></label><label>Match<select name="match_type"><option>contains</option><option>starts_with</option><option>equals</option><option>regex</option></select></label><label>Tipo transação<input name="transaction_type" placeholder="income, expense, transfer"></label><label>Centro<select name="center_id"><?php foreach($centers as $c):?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></label><label>Categoria<select name="category_id"><?php foreach($categories as $c):?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></label><label>Conta<select name="account_id"><option value="">Sem conta</option><?php foreach($accounts as $a):?><option value="<?= (int)$a['id'] ?>"><?= e($a['name']) ?></option><?php endforeach; ?></select></label><button class="btn btn-primary">Criar regra</button></form></div><div class="card"><div class="list"><?php foreach($rules as $r): ?><div class="member"><div class="meta"><strong><?= e($r['match_text']) ?></strong><span class="muted"><?= e($r['match_type']) ?> · <?= e((string)$r['transaction_type']) ?></span></div><form method="post"><input type="hidden" name="action" value="apply"><input type="hidden" name="rule_id" value="<?= (int)$r['id'] ?>"><button class="btn btn-secondary">Aplicar lançamentos</button></form></div><?php endforeach; ?></div></div></div></body></html>
