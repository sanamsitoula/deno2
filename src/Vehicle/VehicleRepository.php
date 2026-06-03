<?php

namespace Administrator\Deno2\Vehicle;

use PDO;

class VehicleRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findAll(bool $activeOnly = false): array
    {
        $sql = "
            SELECT v.*, d.name AS driver_name
            FROM vehicle v
            LEFT JOIN driver d ON v.current_driver_id = d.id
        ";
        if ($activeOnly) {
            $sql .= " WHERE v.status = 'ACTIVE'";
        }
        $sql .= ' ORDER BY v.vehicle_number';
        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT v.*, d.name AS driver_name
            FROM vehicle v
            LEFT JOIN driver d ON v.current_driver_id = d.id
            WHERE v.id = :id
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getMonthlySummary(string $yearMonth): array
    {
        $stmt = $this->db->prepare("
            SELECT
                v.vehicle_number, v.vehicle_type,
                COUNT(vdl.id)               AS total_trips,
                COALESCE(SUM(vdl.distance_km), 0) AS total_km,
                COALESCE(SUM(fc.quantity),  0) AS fuel_litres,
                COALESCE(SUM(fc.amount),    0) AS fuel_cost
            FROM vehicle v
            LEFT JOIN vehicle_daily_logs vdl
                ON vdl.vehicle_id = v.id
               AND vdl.log_date LIKE :month
            LEFT JOIN fuel_coupons fc
                ON fc.vehicle_id = v.id
               AND fc.issue_date LIKE :month
            GROUP BY v.id, v.vehicle_number, v.vehicle_type
            ORDER BY v.vehicle_number
        ");
        $stmt->execute([':month' => $yearMonth . '%']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
