<?php

namespace Administrator\Deno2\Attendance;

use PDO;

class AttendanceRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Get attendance records for a date range (Nepali date string YYYY.MM.DD).
     */
    public function findByDateRange(string $fromNep, string $toNep, ?int $employeeId = null): array
    {
        $sql = "
            SELECT
                a.*,
                e.code   AS employee_code,
                e.name   AS employee_name,
                e.name_nep,
                d.name   AS department_name,
                ast.status_name, ast.status_code
            FROM attendance a
            JOIN employee          e   ON a.employee_id = e.id
            LEFT JOIN department   d   ON e.department_id = d.id
            LEFT JOIN attendance_status ast ON a.status_id = ast.id
            WHERE a.attendance_date_nep BETWEEN :from AND :to
        ";
        $params = [':from' => $fromNep, ':to' => $toNep];

        if ($employeeId !== null) {
            $sql .= ' AND a.employee_id = :emp_id';
            $params[':emp_id'] = $employeeId;
        }

        $sql .= ' ORDER BY a.attendance_date_nep, e.code';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get a single day's attendance for one employee.
     */
    public function findByEmployeeAndDate(int $employeeId, string $dateNep): ?array
    {
        $stmt = $this->db->prepare("
            SELECT a.*, ast.status_name, ast.status_code
            FROM attendance a
            LEFT JOIN attendance_status ast ON a.status_id = ast.id
            WHERE a.employee_id = :emp_id AND a.attendance_date_nep = :date
        ");
        $stmt->execute([':emp_id' => $employeeId, ':date' => $dateNep]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Daily summary stats for a given Nepali date.
     */
    public function getDailySummary(string $dateNep): array
    {
        $stmt = $this->db->prepare("
            SELECT
                COUNT(*)                                                        AS total,
                COUNT(CASE WHEN ast.status_code = 'P'  THEN 1 END)             AS present,
                COUNT(CASE WHEN ast.status_code = 'A'  THEN 1 END)             AS absent,
                COUNT(CASE WHEN ast.status_code = 'HD' THEN 1 END)             AS half_day,
                COUNT(CASE WHEN ast.status_code = 'L'  THEN 1 END)             AS on_leave,
                COUNT(CASE WHEN a.check_in_time  IS NOT NULL THEN 1 END)        AS checked_in,
                COUNT(CASE WHEN a.check_out_time IS NOT NULL THEN 1 END)        AS checked_out,
                COALESCE(SUM(a.ot_hours), 0)                                   AS total_ot_hours
            FROM attendance a
            LEFT JOIN attendance_status ast ON a.status_id = ast.id
            WHERE a.attendance_date_nep = :date
        ");
        $stmt->execute([':date' => $dateNep]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Monthly summary for one employee (Nepali YYYY.MM prefix).
     */
    public function getMonthlyEmployeeSummary(int $employeeId, string $yearMonth): array
    {
        $stmt = $this->db->prepare("
            SELECT
                COUNT(*)                                                        AS working_days,
                COUNT(CASE WHEN ast.status_code = 'P'  THEN 1 END)             AS present_days,
                COUNT(CASE WHEN ast.status_code = 'A'  THEN 1 END)             AS absent_days,
                COUNT(CASE WHEN ast.status_code = 'HD' THEN 1 END)             AS half_days,
                COUNT(CASE WHEN ast.status_code = 'L'  THEN 1 END)             AS leave_days,
                COALESCE(SUM(a.ot_hours), 0)                                   AS total_ot_hours,
                COALESCE(SUM(a.worked_minutes), 0)                             AS total_worked_minutes
            FROM attendance a
            LEFT JOIN attendance_status ast ON a.status_id = ast.id
            WHERE a.employee_id = :emp_id
              AND a.attendance_date_nep LIKE :month
        ");
        $stmt->execute([':emp_id' => $employeeId, ':month' => $yearMonth . '%']);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Upsert a manual attendance record.
     */
    public function upsert(array $data): bool
    {
        $existing = $this->findByEmployeeAndDate(
            (int) $data['employee_id'],
            $data['attendance_date_nep']
        );

        if ($existing) {
            $stmt = $this->db->prepare("
                UPDATE attendance SET
                    check_in_time  = :check_in,
                    check_out_time = :check_out,
                    status_id      = :status_id,
                    remarks        = :remarks,
                    updated_at     = CURRENT_TIMESTAMP,
                    marked_by      = :marked_by
                WHERE id = :id
            ");
            return $stmt->execute([
                ':id'        => $existing['id'],
                ':check_in'  => $data['check_in_time']  ?? null,
                ':check_out' => $data['check_out_time'] ?? null,
                ':status_id' => $data['status_id'],
                ':remarks'   => $data['remarks'] ?? null,
                ':marked_by' => $data['marked_by'] ?? 0,
            ]);
        }

        $stmt = $this->db->prepare("
            INSERT INTO attendance (
                employee_id, attendance_date_nep, attendance_date_eng,
                status_id, check_in_time, check_out_time, break_hours,
                remarks, marked_by, created_at, data_source, shift_type
            ) VALUES (
                :employee_id, :date_nep, :date_eng,
                :status_id, :check_in, :check_out, :break_hours,
                :remarks, :marked_by, CURRENT_TIMESTAMP, :source, :shift_type
            )
        ");
        return $stmt->execute([
            ':employee_id' => $data['employee_id'],
            ':date_nep'    => $data['attendance_date_nep'],
            ':date_eng'    => $data['attendance_date_eng'] ?? null,
            ':status_id'   => $data['status_id'],
            ':check_in'    => $data['check_in_time']  ?? null,
            ':check_out'   => $data['check_out_time'] ?? null,
            ':break_hours' => $data['break_hours'] ?? 1.0,
            ':remarks'     => $data['remarks'] ?? null,
            ':marked_by'   => $data['marked_by'] ?? 0,
            ':source'      => $data['data_source'] ?? 'MANUAL',
            ':shift_type'  => $data['shift_type']  ?? 'REGULAR',
        ]);
    }
}
