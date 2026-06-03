<?php

namespace Administrator\Deno2\Payroll;

use PDO;

class PayrollRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ── Payroll processing header ──────────────────────────────

    public function findAllHeaders(int $limit = 24): array
    {
        $stmt = $this->db->prepare("
            SELECT pp.*, fy.fiscal_code,
                   creator.name AS created_by_name
            FROM payroll_processing pp
            LEFT JOIN fiscal_years fy ON pp.fiscal_year_id = fy.id
            LEFT JOIN employee creator ON pp.created_by = creator.id
            ORDER BY pp.payroll_year DESC, pp.payroll_month DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findHeaderById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT pp.*, fy.fiscal_code
            FROM payroll_processing pp
            LEFT JOIN fiscal_years fy ON pp.fiscal_year_id = fy.id
            WHERE pp.id = :id
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findHeaderByMonthYear(int $month, int $year): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM payroll_processing
            WHERE payroll_month = :month AND payroll_year = :year
            LIMIT 1
        ");
        $stmt->execute([':month' => $month, ':year' => $year]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function createHeader(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO payroll_processing (
                payroll_code, payroll_month, payroll_year, fiscal_year_id,
                from_date, to_date, status, created_by, created_at
            ) VALUES (
                :code, :month, :year, :fy_id,
                :from_date, :to_date, 'DRAFT', :created_by, CURRENT_TIMESTAMP
            ) RETURNING id
        ");
        $stmt->execute([
            ':code'       => $data['payroll_code'],
            ':month'      => $data['payroll_month'],
            ':year'       => $data['payroll_year'],
            ':fy_id'      => $data['fiscal_year_id'],
            ':from_date'  => $data['from_date'],
            ':to_date'    => $data['to_date'],
            ':created_by' => $data['created_by'],
        ]);
        return (int) $stmt->fetchColumn();
    }

    public function updateHeaderTotals(int $id, array $totals): bool
    {
        $stmt = $this->db->prepare("
            UPDATE payroll_processing SET
                total_employees  = :employees,
                total_gross      = :gross,
                total_deductions = :deductions,
                total_net_payable= :net,
                status           = :status,
                processed_by     = :processed_by,
                processed_at     = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        return $stmt->execute([
            ':id'          => $id,
            ':employees'   => $totals['total_employees'],
            ':gross'       => $totals['total_gross'],
            ':deductions'  => $totals['total_deductions'],
            ':net'         => $totals['total_net_payable'],
            ':status'      => $totals['status'] ?? 'CALCULATED',
            ':processed_by'=> $totals['processed_by'],
        ]);
    }

    // ── Payroll detail lines ──────────────────────────────────

    public function findDetailsByHeader(int $payrollProcessingId): array
    {
        $stmt = $this->db->prepare("
            SELECT
                pd.*,
                e.code AS employee_code,
                e.name AS employee_name,
                e.name_nep,
                e.emp_type,
                d.name AS designation_name,
                dep.name AS department_name
            FROM payroll_details pd
            JOIN employee      e   ON pd.employee_id = e.id
            LEFT JOIN designation d   ON e.designation_id = d.id
            LEFT JOIN department  dep ON e.department_id  = dep.id
            WHERE pd.payroll_processing_id = :pp_id
            ORDER BY e.code
        ");
        $stmt->execute([':pp_id' => $payrollProcessingId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function insertDetail(array $d): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO payroll_details (
                payroll_processing_id, employee_id,
                total_working_days, total_present_days, total_absent_days,
                total_leaves, total_holidays, total_paid_days,
                overtime_hours, overtime_amount,
                basic_salary, total_earnings, gross_salary,
                ssf_employee, ssf_employer,
                pf_employee,  pf_employer,
                income_tax,   other_deductions, total_deductions,
                net_payable,  status, created_by, created_at
            ) VALUES (
                :pp_id, :emp_id,
                :working_days, :present_days, :absent_days,
                :leaves, :holidays, :paid_days,
                :ot_hours, :ot_amount,
                :basic, :total_earnings, :gross,
                :ssf_emp, :ssf_er,
                :pf_emp,  :pf_er,
                :tax,     :other_ded, :total_ded,
                :net,     'CALCULATED', :created_by, CURRENT_TIMESTAMP
            ) RETURNING id
        ");
        $stmt->execute([
            ':pp_id'        => $d['payroll_processing_id'],
            ':emp_id'       => $d['employee_id'],
            ':working_days' => $d['total_working_days'],
            ':present_days' => $d['total_present_days'],
            ':absent_days'  => $d['total_absent_days'],
            ':leaves'       => $d['total_leaves'],
            ':holidays'     => $d['total_holidays'],
            ':paid_days'    => $d['total_paid_days'],
            ':ot_hours'     => $d['overtime_hours'],
            ':ot_amount'    => $d['overtime_amount'],
            ':basic'        => $d['basic_salary'],
            ':total_earnings'=> $d['total_earnings'],
            ':gross'        => $d['gross_salary'],
            ':ssf_emp'      => $d['ssf_employee'],
            ':ssf_er'       => $d['ssf_employer'],
            ':pf_emp'       => $d['pf_employee'],
            ':pf_er'        => $d['pf_employer'],
            ':tax'          => $d['income_tax'],
            ':other_ded'    => $d['other_deductions'] ?? 0,
            ':total_ded'    => $d['total_deductions'],
            ':net'          => $d['net_payable'],
            ':created_by'   => $d['created_by'],
        ]);
        return (int) $stmt->fetchColumn();
    }

    // ── Employee salary ───────────────────────────────────────

    public function getCurrentSalary(int $employeeId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT es.*,
                   e.emp_type, e.is_ssf_enrolled, e.taxpayer_type,
                   e.name, e.code
            FROM employee_salary es
            JOIN employee e ON es.employee_id = e.id
            WHERE es.employee_id = :id AND es.is_current = true
            LIMIT 1
        ");
        $stmt->execute([':id' => $employeeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // ── Active employees for payroll run ─────────────────────

    public function getActiveEmployeesForPayroll(): array
    {
        return $this->db->query("
            SELECT
                e.id, e.code, e.name, e.emp_type,
                e.is_ssf_enrolled, e.taxpayer_type,
                es.basic_salary, es.id AS salary_id
            FROM employee e
            JOIN employee_salary es ON es.employee_id = e.id AND es.is_current = true
            WHERE e.emp_status = 'ACTIVE'
              AND e.deleted_date IS NULL
            ORDER BY e.code
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Attendance summary for payroll ────────────────────────

    /**
     * Summarise attendance for a month (Nepali YYYY.MM prefix).
     */
    public function getAttendanceSummaryForPayroll(int $employeeId, string $yearMonth): array
    {
        $stmt = $this->db->prepare("
            SELECT
                COUNT(*)                                                                AS working_days,
                COUNT(CASE WHEN ast.status_code IN ('P','HD') THEN 1 END)              AS present_days,
                COUNT(CASE WHEN ast.status_code = 'A' THEN 1 END)                      AS absent_days,
                COUNT(CASE WHEN ast.status_code = 'L' THEN 1 END)                      AS leave_days,
                COUNT(CASE WHEN ast.status_code = 'H' THEN 1 END)                      AS holidays,
                COALESCE(SUM(a.ot_hours), 0)                                           AS ot_hours
            FROM attendance a
            LEFT JOIN attendance_status ast ON a.status_id = ast.id
            WHERE a.employee_id = :emp_id
              AND a.attendance_date_nep LIKE :month
        ");
        $stmt->execute([':emp_id' => $employeeId, ':month' => $yearMonth . '%']);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [
            'working_days' => 0, 'present_days' => 0, 'absent_days' => 0,
            'leave_days' => 0, 'holidays' => 0, 'ot_hours' => 0,
        ];
    }
}
