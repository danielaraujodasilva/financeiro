<?php
declare(strict_types=1);

final class Financial
{
    public function __construct(private PDO $pdo)
    {
    }

    public function centers(int $instanceId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM financial_centers WHERE instance_id = ? ORDER BY name');
        $stmt->execute([$instanceId]);
        return $stmt->fetchAll();
    }

    public function categories(int $instanceId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM financial_categories WHERE instance_id = ? ORDER BY type, name');
        $stmt->execute([$instanceId]);
        return $stmt->fetchAll();
    }

    public function accounts(int $instanceId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM financial_accounts WHERE instance_id = ? ORDER BY name');
        $stmt->execute([$instanceId]);
        return $stmt->fetchAll();
    }

    public function transactions(int $instanceId, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM financial_transactions WHERE instance_id = ? ORDER BY transaction_date DESC, id DESC LIMIT ' . (int) $limit);
        $stmt->execute([$instanceId]);
        return $stmt->fetchAll();
    }

    public function recurring(int $instanceId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM financial_recurring WHERE instance_id = ? ORDER BY id DESC');
        $stmt->execute([$instanceId]);
        return $stmt->fetchAll();
    }

    public function budgets(int $instanceId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM financial_budgets WHERE instance_id = ? ORDER BY year DESC, month DESC');
        $stmt->execute([$instanceId]);
        return $stmt->fetchAll();
    }

    public function goals(int $instanceId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM financial_goals WHERE instance_id = ? ORDER BY priority ASC, id DESC');
        $stmt->execute([$instanceId]);
        return $stmt->fetchAll();
    }

    public function rules(int $instanceId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM financial_rules WHERE instance_id = ? ORDER BY id DESC');
        $stmt->execute([$instanceId]);
        return $stmt->fetchAll();
    }

    public function cards(int $instanceId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM credit_cards WHERE instance_id = ? ORDER BY id DESC');
        $stmt->execute([$instanceId]);
        return $stmt->fetchAll();
    }

    public function purchases(int $instanceId): array
    {
        $stmt = $this->pdo->prepare('SELECT p.*, c.name AS card_name FROM credit_card_purchases p INNER JOIN credit_cards c ON c.id = p.card_id WHERE p.instance_id = ? ORDER BY p.purchase_date DESC, p.id DESC');
        $stmt->execute([$instanceId]);
        return $stmt->fetchAll();
    }

    public function bills(int $instanceId): array
    {
        $stmt = $this->pdo->prepare('SELECT b.*, c.name AS card_name FROM credit_card_bills b INNER JOIN credit_cards c ON c.id = b.card_id WHERE b.instance_id = ? ORDER BY b.reference_year DESC, b.reference_month DESC');
        $stmt->execute([$instanceId]);
        return $stmt->fetchAll();
    }

    public function appointments(int $instanceId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM financial_service_appointments WHERE instance_id = ? ORDER BY appointment_date DESC, id DESC');
        $stmt->execute([$instanceId]);
        return $stmt->fetchAll();
    }

    public function marketingReports(int $instanceId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM financial_marketing_reports WHERE instance_id = ? ORDER BY report_date DESC, id DESC');
        $stmt->execute([$instanceId]);
        return $stmt->fetchAll();
    }
}
