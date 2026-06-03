<?php

namespace Administrator\Deno2\Vehicle;

use PDO;

class FuelService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Get current fuel price per litre from system settings / price history.
     */
    public function getCurrentPrice(string $fuelType = 'PETROL'): float
    {
        $stmt = $this->db->prepare("
            SELECT price_per_litre FROM fuel_price_history
            WHERE fuel_type = :type
            ORDER BY effective_date DESC
            LIMIT 1
        ");
        $stmt->execute([':type' => $fuelType]);
        return (float) ($stmt->fetchColumn() ?: 0);
    }

    /**
     * Issue a fuel coupon.
     */
    public function issueCoupon(array $data, int $issuedBy): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO fuel_coupons (
                vehicle_id, driver_id, fuel_type, quantity, amount,
                issue_date, issued_by, remarks, created_at
            ) VALUES (
                :vehicle_id, :driver_id, :fuel_type, :quantity, :amount,
                :issue_date, :issued_by, :remarks, CURRENT_TIMESTAMP
            ) RETURNING id
        ");
        $stmt->execute([
            ':vehicle_id' => $data['vehicle_id'],
            ':driver_id'  => $data['driver_id'] ?? null,
            ':fuel_type'  => $data['fuel_type'] ?? 'PETROL',
            ':quantity'   => $data['quantity'],
            ':amount'     => $data['amount'],
            ':issue_date' => $data['issue_date'],
            ':issued_by'  => $issuedBy,
            ':remarks'    => $data['remarks'] ?? null,
        ]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Monthly fuel consumption summary.
     */
    public function getMonthlySummary(string $yearMonth): array
    {
        $stmt = $this->db->prepare("
            SELECT
                fuel_type,
                COUNT(*)              AS coupons_issued,
                SUM(quantity)         AS total_litres,
                SUM(amount)           AS total_cost
            FROM fuel_coupons
            WHERE issue_date LIKE :month
            GROUP BY fuel_type
        ");
        $stmt->execute([':month' => $yearMonth . '%']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
