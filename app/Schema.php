<?php
declare(strict_types=1);

final class Schema
{
    public static function migrate(PDO $pdo): void
    {
        $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS instances (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL,
    FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS instance_members (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    instance_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    role TEXT NOT NULL DEFAULT 'member',
    created_at TEXT NOT NULL,
    UNIQUE(instance_id, user_id),
    FOREIGN KEY (instance_id) REFERENCES instances(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS invites (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    instance_id INTEGER NOT NULL,
    email TEXT NOT NULL,
    token TEXT NOT NULL UNIQUE,
    status TEXT NOT NULL DEFAULT 'pending',
    created_at TEXT NOT NULL,
    accepted_at TEXT NULL,
    FOREIGN KEY (instance_id) REFERENCES instances(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS financial_centers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    instance_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    type TEXT NOT NULL DEFAULT 'personal',
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(instance_id, name),
    FOREIGN KEY (instance_id) REFERENCES instances(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS financial_categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    instance_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    type TEXT NOT NULL,
    parent_id INTEGER NULL,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(instance_id, name, type, parent_id),
    FOREIGN KEY (instance_id) REFERENCES instances(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_id) REFERENCES financial_categories(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS financial_accounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    instance_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    type TEXT NOT NULL,
    bank_name TEXT NULL,
    initial_balance REAL NOT NULL DEFAULT 0,
    current_balance REAL NOT NULL DEFAULT 0,
    credit_limit REAL NOT NULL DEFAULT 0,
    closing_day INTEGER NULL,
    due_day INTEGER NULL,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(instance_id, name),
    FOREIGN KEY (instance_id) REFERENCES instances(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS financial_transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    instance_id INTEGER NOT NULL,
    transaction_date TEXT NOT NULL,
    due_date TEXT NULL,
    paid_date TEXT NULL,
    description TEXT NOT NULL,
    amount REAL NOT NULL,
    type TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'planned',
    account_id INTEGER NOT NULL,
    destination_account_id INTEGER NULL,
    center_id INTEGER NOT NULL,
    category_id INTEGER NOT NULL,
    payment_method TEXT NOT NULL DEFAULT 'other',
    responsible_person TEXT NULL,
    client_id INTEGER NULL,
    lead_id INTEGER NULL,
    appointment_id INTEGER NULL,
    notes TEXT NULL,
    source TEXT NOT NULL DEFAULT 'manual',
    external_provider TEXT NULL,
    external_account_id TEXT NULL,
    external_transaction_id TEXT NULL,
    sync_status TEXT NOT NULL DEFAULT 'not_synced',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (instance_id) REFERENCES instances(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES financial_accounts(id) ON DELETE RESTRICT,
    FOREIGN KEY (destination_account_id) REFERENCES financial_accounts(id) ON DELETE SET NULL,
    FOREIGN KEY (center_id) REFERENCES financial_centers(id) ON DELETE RESTRICT,
    FOREIGN KEY (category_id) REFERENCES financial_categories(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS financial_recurring (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    instance_id INTEGER NOT NULL,
    description TEXT NOT NULL,
    amount REAL NOT NULL,
    type TEXT NOT NULL,
    frequency TEXT NOT NULL,
    due_day INTEGER NULL,
    start_date TEXT NOT NULL,
    end_date TEXT NULL,
    account_id INTEGER NOT NULL,
    center_id INTEGER NOT NULL,
    category_id INTEGER NOT NULL,
    payment_method TEXT NOT NULL DEFAULT 'other',
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (instance_id) REFERENCES instances(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES financial_accounts(id) ON DELETE RESTRICT,
    FOREIGN KEY (center_id) REFERENCES financial_centers(id) ON DELETE RESTRICT,
    FOREIGN KEY (category_id) REFERENCES financial_categories(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS financial_budgets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    instance_id INTEGER NOT NULL,
    month INTEGER NOT NULL,
    year INTEGER NOT NULL,
    center_id INTEGER NOT NULL,
    category_id INTEGER NULL,
    planned_amount REAL NOT NULL DEFAULT 0,
    alert_percent INTEGER NOT NULL DEFAULT 80,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(instance_id, month, year, center_id, category_id),
    FOREIGN KEY (instance_id) REFERENCES instances(id) ON DELETE CASCADE,
    FOREIGN KEY (center_id) REFERENCES financial_centers(id) ON DELETE RESTRICT,
    FOREIGN KEY (category_id) REFERENCES financial_categories(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS financial_goals (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    instance_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    target_amount REAL NOT NULL,
    current_amount REAL NOT NULL DEFAULT 0,
    deadline TEXT NULL,
    center_id INTEGER NOT NULL,
    priority INTEGER NOT NULL DEFAULT 3,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (instance_id) REFERENCES instances(id) ON DELETE CASCADE,
    FOREIGN KEY (center_id) REFERENCES financial_centers(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS financial_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    instance_id INTEGER NOT NULL,
    match_text TEXT NOT NULL,
    match_type TEXT NOT NULL DEFAULT 'contains',
    transaction_type TEXT NULL,
    center_id INTEGER NOT NULL,
    category_id INTEGER NOT NULL,
    account_id INTEGER NULL,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (instance_id) REFERENCES instances(id) ON DELETE CASCADE,
    FOREIGN KEY (center_id) REFERENCES financial_centers(id) ON DELETE RESTRICT,
    FOREIGN KEY (category_id) REFERENCES financial_categories(id) ON DELETE RESTRICT,
    FOREIGN KEY (account_id) REFERENCES financial_accounts(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS credit_cards (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    instance_id INTEGER NOT NULL,
    account_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    bank_name TEXT NULL,
    credit_limit REAL NOT NULL DEFAULT 0,
    closing_day INTEGER NULL,
    due_day INTEGER NULL,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(instance_id, name),
    FOREIGN KEY (instance_id) REFERENCES instances(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES financial_accounts(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS credit_card_purchases (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    instance_id INTEGER NOT NULL,
    card_id INTEGER NOT NULL,
    description TEXT NOT NULL,
    total_amount REAL NOT NULL,
    purchase_date TEXT NOT NULL,
    installments_count INTEGER NOT NULL DEFAULT 1,
    center_id INTEGER NOT NULL,
    category_id INTEGER NOT NULL,
    notes TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (instance_id) REFERENCES instances(id) ON DELETE CASCADE,
    FOREIGN KEY (card_id) REFERENCES credit_cards(id) ON DELETE CASCADE,
    FOREIGN KEY (center_id) REFERENCES financial_centers(id) ON DELETE RESTRICT,
    FOREIGN KEY (category_id) REFERENCES financial_categories(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS credit_card_installments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    purchase_id INTEGER NOT NULL,
    installment_number INTEGER NOT NULL,
    due_date TEXT NOT NULL,
    amount REAL NOT NULL,
    status TEXT NOT NULL DEFAULT 'planned',
    transaction_id INTEGER NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(purchase_id, installment_number),
    FOREIGN KEY (purchase_id) REFERENCES credit_card_purchases(id) ON DELETE CASCADE,
    FOREIGN KEY (transaction_id) REFERENCES financial_transactions(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS credit_card_bills (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    instance_id INTEGER NOT NULL,
    card_id INTEGER NOT NULL,
    reference_month INTEGER NOT NULL,
    reference_year INTEGER NOT NULL,
    closing_date TEXT NOT NULL,
    due_date TEXT NOT NULL,
    total_amount REAL NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'open',
    payment_transaction_id INTEGER NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(card_id, reference_month, reference_year),
    FOREIGN KEY (instance_id) REFERENCES instances(id) ON DELETE CASCADE,
    FOREIGN KEY (card_id) REFERENCES credit_cards(id) ON DELETE CASCADE,
    FOREIGN KEY (payment_transaction_id) REFERENCES financial_transactions(id) ON DELETE SET NULL
);
SQL);

        self::ensureInviteColumns($pdo);
        self::seedFinancialBase($pdo);
    }

    private static function ensureInviteColumns(PDO $pdo): void
    {
        $columns = $pdo->query('PRAGMA table_info(invites)')->fetchAll(PDO::FETCH_ASSOC);
        $names = array_column($columns, 'name');

        if (!in_array('accepted_at', $names, true)) {
            $pdo->exec('ALTER TABLE invites ADD COLUMN accepted_at TEXT NULL');
        }
    }

    public static function seedFinancialBaseForInstance(PDO $pdo, int $instanceId): void
    {
        self::insertDefaultCenters($pdo, $instanceId);
        self::insertDefaultCategories($pdo, $instanceId);
        self::insertDefaultSubcategories($pdo, $instanceId);
    }

    private static function seedFinancialBase(PDO $pdo): void
    {
        $instances = $pdo->query('SELECT id FROM instances')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($instances as $instanceId) {
            self::seedFinancialBaseForInstance($pdo, (int) $instanceId);
        }
    }

    private static function insertDefaultCenters(PDO $pdo, int $instanceId): void
    {
        $now = date('Y-m-d H:i:s');
        $centers = [
            ['Casa/Família', 'personal'],
            ['Estúdio', 'business'],
            ['Daniel pessoal', 'personal'],
            ['Fran', 'personal'],
            ['Luna', 'personal'],
            ['Investimentos/Reserva', 'reserve'],
            ['Dívidas/Parcelamentos', 'liability'],
            ['Impostos', 'tax'],
            ['Obra/Reforma', 'project'],
        ];

        $check = $pdo->prepare('SELECT 1 FROM financial_centers WHERE instance_id = ? AND name = ? LIMIT 1');
        $stmt = $pdo->prepare('
            INSERT INTO financial_centers (instance_id, name, type, active, created_at, updated_at)
            VALUES (?, ?, ?, 1, ?, ?)
        ');
        foreach ($centers as [$name, $type]) {
            $check->execute([$instanceId, $name]);
            if (!$check->fetchColumn()) {
                $stmt->execute([$instanceId, $name, $type, $now, $now]);
            }
        }
    }

    private static function insertDefaultCategories(PDO $pdo, int $instanceId): void
    {
        $now = date('Y-m-d H:i:s');

        $categories = [
            'income' => [
                'Tattoo',
                'Sinal',
                'Sessão',
                'Anestésico',
                'Fran',
                'Reembolso',
                'Venda extra',
                'Investimentos',
                'Outros',
            ],
            'expense' => [
                'Moradia',
                'Aluguel',
                'Condomínio',
                'Energia',
                'Água',
                'Internet',
                'Mercado',
                'Saúde',
                'Terapias',
                'Luna',
                'Transporte',
                'Carro',
                'Estúdio',
                'Material tattoo',
                'Descartáveis',
                'Marketing',
                'Equipamentos',
                'Manutenção',
                'Obra/Reforma',
                'Impostos',
                'Cartão',
                'Dívidas',
                'Lazer',
                'Assinaturas',
                'Delivery',
                'Outros',
            ],
            'transfer' => [
                'Entre contas',
            ],
        ];

        $check = $pdo->prepare('SELECT 1 FROM financial_categories WHERE instance_id = ? AND name = ? AND type = ? AND parent_id IS NULL LIMIT 1');
        $stmt = $pdo->prepare('
            INSERT INTO financial_categories (instance_id, name, type, parent_id, active, created_at, updated_at)
            VALUES (?, ?, ?, NULL, 1, ?, ?)
        ');

        foreach ($categories as $type => $items) {
            foreach ($items as $name) {
                $check->execute([$instanceId, $name, $type]);
                if (!$check->fetchColumn()) {
                    $stmt->execute([$instanceId, $name, $type, $now, $now]);
                }
            }
        }
    }

    private static function insertDefaultSubcategories(PDO $pdo, int $instanceId): void
    {
        $now = date('Y-m-d H:i:s');
        $pairs = [
            'income' => [
                'Tattoo' => ['Sessão', 'Sinal', 'Arte personalizada'],
                'Investimentos' => ['Rendimento', 'Dividendos'],
                'Outros' => ['Extra', 'Reversão'],
            ],
            'expense' => [
                'Moradia' => ['Casa', 'Família'],
                'Estúdio' => ['Aluguel interno', 'Utilidades'],
                'Marketing' => ['Meta Ads', 'Google Ads', 'Design'],
                'Carro' => ['Combustível', 'Manutenção'],
                'Material tattoo' => ['Tinta', 'Agulhas', 'Biqueiras'],
                'Impostos' => ['Simples Nacional', 'MEI', 'Taxas'],
                'Cartão' => ['Fatura', 'Anuidade', 'Juros'],
                'Dívidas' => ['Parcelas', 'Empréstimos'],
                'Delivery' => ['iFood', 'Uber Eats'],
                'Lazer' => ['Passeio', 'Streaming'],
                'Assinaturas' => ['Software', 'Serviços online'],
            ],
        ];

        $findParent = $pdo->prepare('SELECT id FROM financial_categories WHERE instance_id = ? AND name = ? AND type = ? AND parent_id IS NULL LIMIT 1');
        $check = $pdo->prepare('SELECT 1 FROM financial_categories WHERE instance_id = ? AND name = ? AND type = ? AND parent_id = ? LIMIT 1');
        $insert = $pdo->prepare('INSERT INTO financial_categories (instance_id, name, type, parent_id, active, created_at, updated_at) VALUES (?, ?, ?, ?, 1, ?, ?)');

        foreach ($pairs as $type => $parents) {
            foreach ($parents as $parentName => $children) {
                $findParent->execute([$instanceId, $parentName, $type]);
                $parentId = (int) $findParent->fetchColumn();
                if (!$parentId) {
                    continue;
                }
                foreach ($children as $childName) {
                    $check->execute([$instanceId, $childName, $type, $parentId]);
                    if (!$check->fetchColumn()) {
                        $insert->execute([$instanceId, $childName, $type, $parentId, $now, $now]);
                    }
                }
            }
        }
    }
}
