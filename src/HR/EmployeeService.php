<?php

namespace Administrator\Deno2\HR;

use PDO;

class EmployeeService
{
    private EmployeeRepository $repo;
    private PDO $db;

    public function __construct(EmployeeRepository $repo, PDO $db)
    {
        $this->repo = $repo;
        $this->db   = $db;
    }

    /**
     * Return paginated employee list with stats and filter options.
     */
    public function listPage(array $filters, int $page, int $perPage = 50): array
    {
        $offset = ($page - 1) * $perPage;
        ['items' => $items, 'total' => $total] = $this->repo->findAll($filters, $perPage, $offset);
        $stats         = $this->repo->getStats();
        $filterOptions = $this->repo->getFilterOptions();

        return compact('items', 'total', 'stats', 'filterOptions', 'page', 'perPage');
    }

    /**
     * Return a single employee with all related profile data.
     */
    public function getProfile(int $id): ?array
    {
        $employee = $this->repo->findById($id);
        if (!$employee) {
            return null;
        }
        $details = $this->repo->findProfileDetails($id);
        return array_merge($employee, $details);
    }

    /**
     * Generate the next employee code in the series (e.g. EMP-001 → EMP-002).
     */
    public function generateNextCode(string $prefix = 'EMP'): string
    {
        $stmt = $this->db->prepare("
            SELECT code FROM employee
            WHERE code LIKE :prefix
            ORDER BY code DESC LIMIT 1
        ");
        $stmt->execute([':prefix' => $prefix . '-%']);
        $last = $stmt->fetchColumn();

        if ($last && preg_match('/(\d+)$/', $last, $m)) {
            $next = (int) $m[1] + 1;
        } else {
            $next = 1;
        }
        return $prefix . '-' . str_pad($next, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Check whether a given employee code is already taken.
     */
    public function isCodeTaken(string $code, ?int $excludeId = null): bool
    {
        $sql    = 'SELECT 1 FROM employee WHERE code = :code AND deleted_date IS NULL';
        $params = [':code' => $code];
        if ($excludeId) {
            $sql .= ' AND id != :exclude';
            $params[':exclude'] = $excludeId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Return all active employees as a simple id → name map (for select boxes).
     */
    public function getActiveEmployeeOptions(): array
    {
        return $this->db->query("
            SELECT id, COALESCE(name_eng, name) AS name, code
            FROM employee
            WHERE emp_status = 'ACTIVE' AND deleted_date IS NULL
            ORDER BY name
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
}
