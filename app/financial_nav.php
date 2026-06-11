<?php
declare(strict_types=1);

function financial_nav(int $instanceId, string $current = ''): void
{
    $core = [
        'dashboard' => ['Dashboard', base_path('dashboard.php')],
        'finance' => ['Base', base_path('financial.php?instance_id=' . $instanceId)],
        'transactions' => ['Lançamentos', base_path('transactions.php?instance_id=' . $instanceId)],
        'cards' => ['Cartões', base_path('cards.php?instance_id=' . $instanceId)],
        'cashflow' => ['Fluxo', base_path('cashflow.php?instance_id=' . $instanceId)],
        'insights' => ['Insights', base_path('insights.php?instance_id=' . $instanceId)],
    ];
    $advanced = [
        'recurring' => ['Recorrências', base_path('recurring.php?instance_id=' . $instanceId)],
        'centers' => ['Centros', base_path('centers.php?instance_id=' . $instanceId)],
        'categories' => ['Categorias', base_path('categories.php?instance_id=' . $instanceId)],
        'accounts' => ['Contas', base_path('accounts.php?instance_id=' . $instanceId)],
        'budgets' => ['Orçamentos', base_path('budgets.php?instance_id=' . $instanceId)],
        'goals' => ['Metas', base_path('goals.php?instance_id=' . $instanceId)],
        'calendar' => ['Calendário', base_path('calendar.php?instance_id=' . $instanceId)],
        'reports' => ['Relatórios', base_path('reports.php?instance_id=' . $instanceId)],
        'dre' => ['DRE', base_path('dre.php?instance_id=' . $instanceId)],
        'simulator' => ['Simulador', base_path('simulator.php?instance_id=' . $instanceId)],
        'smart_rules' => ['Regras Smart', base_path('smart-rules.php?instance_id=' . $instanceId)],
        'appointments' => ['Agenda/Receita', base_path('appointments.php?instance_id=' . $instanceId)],
        'services' => ['Serviços', base_path('services-report.php?instance_id=' . $instanceId)],
        'marketing' => ['Marketing', base_path('marketing-report.php?instance_id=' . $instanceId)],
        'crm' => ['CRM opcional', base_path('crm-integration.php?instance_id=' . $instanceId)],
        'openfinance' => ['Open Finance', base_path('open-finance.php?instance_id=' . $instanceId)],
        'audit' => ['Auditoria', base_path('audit.php?instance_id=' . $instanceId)],
    ];
    echo '<div class="card enter mb-3"><div class="d-flex flex-wrap gap-2 align-items-center justify-content-between">';
    echo '<ul class="nav nav-pills flex-wrap gap-2">';
    foreach ($core as $key => [$label, $href]) {
        $active = $key === $current ? ' active' : '';
        echo '<li class="nav-item"><a class="nav-link' . $active . '" href="' . e($href) . '">' . e($label) . '</a></li>';
    }
    echo '</ul>';
    echo '<div class="dropdown">';
    echo '<button class="btn btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">Ferramentas avançadas</button>';
    echo '<ul class="dropdown-menu dropdown-menu-end p-2" style="min-width:320px;max-height:70vh;overflow:auto">';
    foreach ($advanced as $key => [$label, $href]) {
        $active = $key === $current ? ' active' : '';
        echo '<li><a class="dropdown-item rounded ' . trim($active) . '" href="' . e($href) . '">' . e($label) . '</a></li>';
    }
    echo '</ul>';
    echo '</div></div></div>';
}
