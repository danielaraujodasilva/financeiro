<?php
declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function base_path(string $path = ''): string
{
    static $basePath = null;

    if ($basePath === null) {
        $config = require __DIR__ . '/config.php';
        $basePath = rtrim((string) ($config['base_path'] ?? ''), '/');
        if ($basePath === '') {
            $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
            $basePath = rtrim($scriptDir, '/');
        }
        if ($basePath === '') {
            $basePath = '';
        }
    }

    return $basePath . '/' . ltrim($path, '/');
}

function bootstrap_assets(): string
{
    return <<<HTML
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
HTML;
}

function financial_type_label(string $type): string
{
    return match ($type) {
        'income' => 'Entrada',
        'expense' => 'Gasto',
        'transfer' => 'Transferência',
        default => ucfirst($type),
    };
}

function financial_status_label(string $status): string
{
    return match ($status) {
        'planned' => 'Previsto',
        'pending' => 'Pendente',
        'paid' => 'Pago',
        'overdue' => 'Vencido',
        'canceled' => 'Cancelado',
        'done' => 'Concluído',
        default => ucfirst($status),
    };
}

function financial_account_type_label(string $type): string
{
    return match ($type) {
        'cash' => 'Dinheiro',
        'bank' => 'Banco',
        'credit_card' => 'Cartão',
        'investment' => 'Investimento',
        'wallet' => 'Carteira',
        default => ucfirst($type),
    };
}

function financial_center_label(string $center): string
{
    $labels = [
        'personal' => 'Pessoal',
        'business' => 'Negócio',
        'reserve' => 'Reserva',
        'liability' => 'Dívidas',
        'tax' => 'Impostos',
        'project' => 'Projeto',
    ];
    return $labels[$center] ?? ucfirst($center);
}

function format_money(float $value): string
{
    return 'R$ ' . number_format($value, 2, ',', '.');
}

function risk_label(string $risk): string
{
    return match ($risk) {
        'baixo' => 'Baixo',
        'médio' => 'Médio',
        'alto' => 'Alto',
        default => ucfirst($risk),
    };
}

function interface_mode(): string
{
    if (isset($_SESSION['interface_mode']) && in_array($_SESSION['interface_mode'], ['simple', 'advanced'], true)) {
        return $_SESSION['interface_mode'];
    }
    $_SESSION['interface_mode'] = 'simple';
    return 'simple';
}

function quick_add_modal(int $instanceId, array $accounts, array $centers, array $categories, array $cards): string
{
    ob_start();
    ?>
<div class="modal fade" id="quickAddModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title mb-0">Adicionar rápido</h5>
          <small class="text-muted">Escolha o que você quer lançar agora.</small>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
      </div>
      <div class="modal-body">
        <ul class="nav nav-pills mb-3" id="quickAddTabs" role="tablist">
          <li class="nav-item"><button class="nav-link active" data-bs-toggle="pill" data-bs-target="#qa-expense" type="button">Gasto</button></li>
          <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#qa-income" type="button">Entrada</button></li>
          <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#qa-card" type="button">Cartão</button></li>
          <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#qa-fixed" type="button">Conta fixa</button></li>
          <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#qa-transfer" type="button">Transferência</button></li>
        </ul>
        <div class="tab-content">
          <div class="tab-pane fade show active" id="qa-expense">
            <form method="post" action="<?= e(base_path('transactions.php?instance_id=' . $instanceId)) ?>" class="split">
              <input type="hidden" name="action" value="create">
              <input type="hidden" name="instance_id" value="<?= $instanceId ?>">
              <input type="hidden" name="type" value="expense">
              <label>Valor<input type="number" step="0.01" name="amount" required></label>
              <label>Descrição<input type="text" name="description" placeholder="Ex.: mercado, almoço, terapia" required></label>
              <label>Data<input type="date" name="transaction_date" value="<?= date('Y-m-d') ?>"></label>
              <label>Área<select name="center_id" required><?php foreach ($centers as $center): ?><option value="<?= (int) $center['id'] ?>"><?= e($center['name']) ?></option><?php endforeach; ?></select></label>
              <label>Tipo de gasto<select name="category_id" required><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>"><?= e($category['name']) ?></option><?php endforeach; ?></select></label>
              <label>Forma<select name="payment_method"><option>pix</option><option>cash</option><option>debit</option><option>credit_card</option><option>boleto</option><option>transfer</option><option>other</option></select></label>
              <div class="col-12"><button class="btn btn-primary" type="submit">Salvar gasto</button></div>
            </form>
          </div>
          <div class="tab-pane fade" id="qa-income">
            <form method="post" action="<?= e(base_path('transactions.php?instance_id=' . $instanceId)) ?>" class="split">
              <input type="hidden" name="action" value="create">
              <input type="hidden" name="instance_id" value="<?= $instanceId ?>">
              <input type="hidden" name="type" value="income">
              <label>Valor<input type="number" step="0.01" name="amount" required></label>
              <label>Descrição<input type="text" name="description" placeholder="Ex.: sessão, sinal, venda" required></label>
              <label>Data<input type="date" name="transaction_date" value="<?= date('Y-m-d') ?>"></label>
              <label>Área<select name="center_id" required><?php foreach ($centers as $center): ?><option value="<?= (int) $center['id'] ?>"><?= e($center['name']) ?></option><?php endforeach; ?></select></label>
              <label>Tipo de entrada<select name="category_id" required><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>"><?= e($category['name']) ?></option><?php endforeach; ?></select></label>
              <label>Forma<select name="payment_method"><option>pix</option><option>cash</option><option>debit</option><option>credit_card</option><option>transfer</option><option>other</option></select></label>
              <div class="col-12"><button class="btn btn-primary" type="submit">Salvar entrada</button></div>
            </form>
          </div>
          <div class="tab-pane fade" id="qa-card">
            <p class="mb-2">Compra no cartão continua na tela de cartões, mas você pode ir direto por aqui.</p>
            <a class="btn btn-primary" href="<?= e(base_path('cards.php?instance_id=' . $instanceId)) ?>">Abrir cartões</a>
          </div>
          <div class="tab-pane fade" id="qa-fixed">
            <p class="mb-2">Conta fixa continua em Configurações/Recorrências.</p>
            <a class="btn btn-primary" href="<?= e(base_path('financial.php?instance_id=' . $instanceId)) ?>">Abrir configurações</a>
          </div>
          <div class="tab-pane fade" id="qa-transfer">
            <p class="mb-2">Transferências podem ser lançadas em Lançamentos com tipo Transferência.</p>
            <a class="btn btn-primary" href="<?= e(base_path('transactions.php?instance_id=' . $instanceId)) ?>">Abrir lançamentos</a>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php
    return (string) ob_get_clean();
}
