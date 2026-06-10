<?php
declare(strict_types=1);

function financial_nav(int $instanceId, string $current = ''): void
{
    $items = [
        'dashboard' => ['Dashboard', base_path('dashboard.php')],
        'finance' => ['Base', base_path('financial.php?instance_id=' . $instanceId)],
        'transactions' => ['Lançamentos', base_path('transactions.php?instance_id=' . $instanceId)],
        'recurring' => ['Recorrências', base_path('recurring.php?instance_id=' . $instanceId)],
        'centers' => ['Centros', base_path('centers.php?instance_id=' . $instanceId)],
        'categories' => ['Categorias', base_path('categories.php?instance_id=' . $instanceId)],
        'accounts' => ['Contas', base_path('accounts.php?instance_id=' . $instanceId)],
        'cards' => ['Cartões', base_path('cards.php?instance_id=' . $instanceId)],
        'budgets' => ['Orçamentos', base_path('budgets.php?instance_id=' . $instanceId)],
        'goals' => ['Metas', base_path('goals.php?instance_id=' . $instanceId)],
        'cashflow' => ['Fluxo', base_path('cashflow.php?instance_id=' . $instanceId)],
        'calendar' => ['Calendário', base_path('calendar.php?instance_id=' . $instanceId)],
        'reports' => ['Relatórios', base_path('reports.php?instance_id=' . $instanceId)],
        'dre' => ['DRE', base_path('dre.php?instance_id=' . $instanceId)],
        'insights' => ['Insights', base_path('insights.php?instance_id=' . $instanceId)],
        'simulator' => ['Simulador', base_path('simulator.php?instance_id=' . $instanceId)],
        'smart_rules' => ['Regras Smart', base_path('smart-rules.php?instance_id=' . $instanceId)],
        'appointments' => ['Agenda/Receita', base_path('appointments.php?instance_id=' . $instanceId)],
        'services' => ['Serviços', base_path('services-report.php?instance_id=' . $instanceId)],
        'marketing' => ['Marketing', base_path('marketing-report.php?instance_id=' . $instanceId)],
    ];
    echo '<div class="card enter" style="margin-bottom:16px"><div class="actions">';
    foreach ($items as $key => [$label, $href]) {
        $class = $key === $current ? 'btn btn-primary' : 'btn btn-secondary';
        echo '<a class="' . $class . '" href="' . e($href) . '">' . e($label) . '</a>';
    }
    echo '</div></div>';
}
