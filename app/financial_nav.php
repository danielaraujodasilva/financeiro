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
    echo '<div class="card enter" style="margin-bottom:16px"><div class="actions">';
    foreach ($core as $key => [$label, $href]) {
        $class = $key === $current ? 'btn btn-primary' : 'btn btn-secondary';
        echo '<a class="' . $class . '" href="' . e($href) . '">' . e($label) . '</a>';
    }
    echo '</div><details style="margin-top:14px"><summary class="tag" style="cursor:pointer">Ferramentas avançadas</summary><div class="actions" style="margin-top:12px">';
    foreach ($advanced as $key => [$label, $href]) {
        $class = $key === $current ? 'btn btn-primary' : 'btn btn-secondary';
        echo '<a class="' . $class . '" href="' . e($href) . '">' . e($label) . '</a>';
    }
    echo '</div></div>';
}
