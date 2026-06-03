<?php

namespace Administrator\Deno2\HR;

use PDO;

class EmployeeRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Fetch paginated employee list with optional filters.
     *
     * @param array $filters  Keys: search, department_id, designation_id, level_id,
     *                        emp_status, emp_type, is_technical, state, fiscal_year_id
     * @return array{items: array, total: int}
     */
    public function findAll(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = $this->buildWhere($filters);

        $sql = "
            SELECT
                e.id, e.code, e.name, e.name_eng, e.name_nep,
                e.mobile_number, e.email, e.emp_status, e.emp_type,
                e.is_technical, e.state, e.local_body, e.ward_no,
                e.pan_no, e.bank_name, e.card_id,
                e.is_ssf_enrolled, e.taxpayer_type,
                d.name  AS designation_name,
                l.name  AS level_name,
                dep.name AS department_name,
                dep.sub_department_name,
                fy.fiscal_code,
                e.picture, e.join_date, e.join_date_nep
            FROM employee e
            LEFT JOIN designation  d   ON e.designation_id  = d.id
            LEFT JOIN level        l   ON e.level_id         = l.id
            LEFT JOIN department   dep ON e.department_id    = dep.id
            LEFT JOIN fiscal_years fy  ON e.fiscal_year_id   = fy.id
            WHERE e.deleted_date IS NULL $where
            ORDER BY e.code, e.name
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Count without LIMIT/OFFSET
        $countSql = "
            SELECT COUNT(*) FROM employee e
            WHERE e.deleted_date IS NULL $where
        ";
        $countStmt = $this->db->prepare($countSql);
        foreach ($params as $key => $val) {
            $countStmt->bindValue($key, $val);
        }
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Fetch a single employee with full joins.
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT
                e.*,
                d.name  AS designation_name,
                l.name  AS level_name,
                dep.name AS department_name,
                dep.sub_department_name,
                fy.fiscal_code,
                creator.name AS created_by_name,
                updater.name AS updated_by_name
            FROM employee e
            LEFT JOIN designation  d       ON e.designation_id  = d.id
            LEFT JOIN level        l       ON e.level_id         = l.id
            LEFT JOIN department   dep     ON e.department_id    = dep.id
            LEFT JOIN fiscal_years fy      ON e.fiscal_year_id   = fy.id
            LEFT JOIN employee     creator ON e.created_by       = creator.id
            LEFT JOIN employee     updater ON e.updated_by       = updater.id
            WHERE e.id = :id AND e.deleted_date IS NULL
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Aggregate stats for the employee dashboard card.
     */
    public function getStats(): array
    {
        return $this->db->query("
            SELECT
                COUNT(*) FILTER (WHERE emp_status = 'ACTIVE')      AS active,
                COUNT(*) FILTER (WHERE emp_status = 'INACTIVE')     AS inactive,
                COUNT(*) FILTER (WHERE emp_status = 'RETIRED')      AS retired,
                COUNT(*) FILTER (WHERE emp_status = 'DRAFT')        AS draft,
                COUNT(*) FILTER (WHERE emp_type  = 'PERMANENT')     AS permanent,
                COUNT(*) FILTER (WHERE emp_type  = 'CONTRACT')      AS contract,
                COUNT(*) FILTER (WHERE emp_type  = 'DAILY_WAGES')   AS daily_wages,
                COUNT(*) FILTER (WHERE is_technical = TRUE)          AS technical,
                COUNT(*)                                              AS total
            FROM employee
            WHERE deleted_date IS NULL
        ")->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Lookup lists for filter dropdowns.
     */
    public function getFilterOptions(): array
    {
        $departments = $this->db->query("
            SELECT id,
                   CONCAT(COALESCE(sub_department_name, ''),
                          CASE WHEN sub_department_name IS NOT NULL THEN ' / ' ELSE '' END,
                          name) AS name
            FROM department WHERE status = true ORDER BY name
        ")->fetchAll(PDO::FETCH_ASSOC);

        $designations = $this->db->query("
            SELECT id, name FROM designation WHERE status = true ORDER BY name
        ")->fetchAll(PDO::FETCH_ASSOC);

        $levels = $this->db->query("
            SELECT id, name FROM level WHERE status = true ORDER BY display_order DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $fiscalYears = $this->db->query("
            SELECT id, fiscal_code FROM fiscal_years ORDER BY start_date DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $states = $this->db->query("
            SELECT DISTINCT state FROM employee
            WHERE state IS NOT NULL AND state != '' AND deleted_date IS NULL
            ORDER BY state
        ")->fetchAll(PDO::FETCH_COLUMN);

        return compact('departments', 'designations', 'levels', 'fiscalYears', 'states');
    }

    /**
     * Fetch family members, education, designation history, and documents for a profile.
     */
    public function findProfileDetails(int $employeeId): array
    {
        $family = $this->db->prepare("
            SELECT * FROM employee_family WHERE employee_id = :id ORDER BY id
        ");
        $family->execute([':id' => $employeeId]);

        $education = $this->db->prepare("
            SELECT * FROM education_details WHERE employee_id = :id ORDER BY id
        ");
        $education->execute([':id' => $employeeId]);

        $designationHistory = $this->db->prepare("
            SELECT ed.*, d.name AS designation_name, l.name AS level_name
            FROM employee_designation ed
            LEFT JOIN designation d ON ed.designation_id = d.id
            LEFT JOIN level       l ON ed.level_id       = l.id
            WHERE ed.employee_id = :id
            ORDER BY ed.effective_date DESC
        ");
        $designationHistory->execute([':id' => $employeeId]);

        $documents = $this->db->prepare("
            SELECT * FROM employee_documents WHERE employee_id = :id ORDER BY id
        ");
        $documents->execute([':id' => $employeeId]);

        return [
            'family'             => $family->fetchAll(PDO::FETCH_ASSOC),
            'education'          => $education->fetchAll(PDO::FETCH_ASSOC),
            'designation_history'=> $designationHistory->fetchAll(PDO::FETCH_ASSOC),
            'documents'          => $documents->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    /**
     * Soft-delete an employee.
     */
    public function softDelete(int $id, int $deletedBy): bool
    {
        $stmt = $this->db->prepare("
            UPDATE employee
            SET deleted_date = NOW(), updated_by = :deleted_by
            WHERE id = :id AND deleted_date IS NULL
        ");
        return $stmt->execute([':id' => $id, ':deleted_by' => $deletedBy]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $params  = [];

        if (!empty($filters['search'])) {
            $clauses[] = "(
                e.code ILIKE :search OR e.name ILIKE :search OR
                e.name_eng ILIKE :search OR e.name_nep ILIKE :search OR
                e.email ILIKE :search OR e.mobile_number ILIKE :search OR
                e.pan_no ILIKE :search OR e.card_id ILIKE :search
            )";
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        foreach ([
            'department_id'  => 'e.department_id',
            'designation_id' => 'e.designation_id',
            'level_id'       => 'e.level_id',
            'fiscal_year_id' => 'e.fiscal_year_id',
        ] as $key => $col) {
            if (isset($filters[$key]) && $filters[$key] !== '' && is_numeric($filters[$key])) {
                $clauses[]        = "$col = :$key";
                $params[":$key"]  = (int) $filters[$key];
            }
        }

        foreach (['emp_status', 'emp_type'] as $key) {
            if (!empty($filters[$key])) {
                $clauses[]       = "e.$key = :$key";
                $params[":$key"] = $filters[$key];
            }
        }

        if (isset($filters['is_technical']) && $filters['is_technical'] !== '') {
            $clauses[]              = 'e.is_technical = :is_technical';
            $params[':is_technical'] = $filters['is_technical'] === '1' || $filters['is_technical'] === true;
        }

        if (!empty($filters['state'])) {
            $clauses[]       = 'e.state ILIKE :state';
            $params[':state'] = '%' . $filters['state'] . '%';
        }

        $where = $clauses ? 'AND ' . implode(' AND ', $clauses) : '';
        return [$where, $params];
    }
}
