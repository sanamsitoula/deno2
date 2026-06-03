<?php

namespace Administrator\Deno2\HR;

use PDO;

class DepartmentRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findAll(bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM department';
        if ($activeOnly) {
            $sql .= ' WHERE status = true';
        }
        $sql .= ' ORDER BY display_order, name';
        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM department WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO department (name, sub_department_name, status, remarks, display_order, is_technical)
            VALUES (:name, :sub_department_name, :status, :remarks, :display_order, :is_technical)
            RETURNING id
        ");
        $stmt->execute([
            ':name'               => $data['name'],
            ':sub_department_name'=> $data['sub_department_name'] ?? null,
            ':status'             => $data['status'] ?? true,
            ':remarks'            => $data['remarks'] ?? null,
            ':display_order'      => $data['display_order'] ?? 0,
            ':is_technical'       => $data['is_technical'] ?? false,
        ]);
        return (int) $stmt->fetchColumn();
    }

    public function update(int $id, array $data): bool
    {
        $stmt = $this->db->prepare("
            UPDATE department
            SET name = :name, sub_department_name = :sub_department_name,
                status = :status, remarks = :remarks,
                display_order = :display_order, is_technical = :is_technical
            WHERE id = :id
        ");
        return $stmt->execute([
            ':id'                 => $id,
            ':name'               => $data['name'],
            ':sub_department_name'=> $data['sub_department_name'] ?? null,
            ':status'             => $data['status'] ?? true,
            ':remarks'            => $data['remarks'] ?? null,
            ':display_order'      => $data['display_order'] ?? 0,
            ':is_technical'       => $data['is_technical'] ?? false,
        ]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM department WHERE id = :id');
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Employee count per department (for the department list view).
     */
    public function getEmployeeCountMap(): array
    {
        $rows = $this->db->query("
            SELECT department_id, COUNT(*) AS cnt
            FROM employee
            WHERE deleted_date IS NULL AND emp_status = 'ACTIVE'
            GROUP BY department_id
        ")->fetchAll(PDO::FETCH_ASSOC);

        return array_column($rows, 'cnt', 'department_id');
    }
}
