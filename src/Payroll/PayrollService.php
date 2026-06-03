<?php

namespace Administrator\Deno2\Payroll;

use PDO;
use Administrator\Deno2\Tax\TaxService;
use Administrator\Deno2\Core\Logger;

/**
 * Core payroll generation engine.
 *
 * Usage:
 *   $service = new PayrollService($db);
 *   $ppId    = $service->generatePayroll(3, 2081, $fiscalYearId, $userId);
 */
class PayrollService
{
    private PayrollRepository $repo;
    private TaxService $taxService;
    private Logger $log;
    private PDO $db;

    // Working days per month assumption (used when no attendance data)
    private const DEFAULT_WORKING_DAYS = 26;

    public function __construct(PDO $db)
    {
        $this->db         = $db;
        $this->repo       = new PayrollRepository($db);
        $this->taxService = new TaxService($db);
        $this->log        = new Logger('payroll');
    }

    /**
     * Generate payroll for all active employees for a given BS month/year.
     *
     * @param int $month        BS month (1–12)
     * @param int $year         BS year  (e.g. 2081)
     * @param int $fiscalYearId Foreign key to fiscal_years.id
     * @param int $createdBy    User ID performing the run
     *
     * @return int              payroll_processing.id
     * @throws \RuntimeException if payroll already exists for that month/year
     */
    public function generatePayroll(int $month, int $year, int $fiscalYearId, int $createdBy): int
    {
        // Guard: no duplicate
        if ($this->repo->findHeaderByMonthYear($month, $year)) {
            throw new \RuntimeException("Payroll for {$year}-" . sprintf('%02d', $month) . " already exists.");
        }

        $nepMonth  = sprintf('%04d.%02d', $year, $month);
        $employees = $this->repo->getActiveEmployeesForPayroll();

        if (empty($employees)) {
            throw new \RuntimeException('No active employees with salary records found.');
        }

        // Months remaining in fiscal year (Shrawan=1 … Ashadh=12)
        // BS fiscal year starts Shrawan (month 4 in 0-indexed BS → month 1 of fiscal)
        $fiscalMonth    = (($month - 4 + 12) % 12) + 1; // 1=Shrawan … 12=Ashadh
        $monthsRemaining = 13 - $fiscalMonth;

        // Create header
        $code = 'PAY-' . $year . '-' . sprintf('%02d', $month);
        $ppId = $this->repo->createHeader([
            'payroll_code'   => $code,
            'payroll_month'  => $month,
            'payroll_year'   => $year,
            'fiscal_year_id' => $fiscalYearId,
            'from_date'      => null, // can be filled from BS calendar if needed
            'to_date'        => null,
            'created_by'     => $createdBy,
        ]);

        $this->log->info("Payroll run started: $code (payroll_processing_id=$ppId)");

        $totals = [
            'total_employees'   => 0,
            'total_gross'       => 0.0,
            'total_deductions'  => 0.0,
            'total_net_payable' => 0.0,
        ];

        foreach ($employees as $emp) {
            try {
                $detail = $this->calculateEmployeePayroll(
                    $emp, $ppId, $nepMonth, $fiscalYearId, $monthsRemaining, $createdBy
                );

                $this->repo->insertDetail($detail);

                $totals['total_employees']++;
                $totals['total_gross']       += $detail['gross_salary'];
                $totals['total_deductions']  += $detail['total_deductions'];
                $totals['total_net_payable'] += $detail['net_payable'];

                $this->log->info("Processed employee {$emp['code']}: gross={$detail['gross_salary']}, net={$detail['net_payable']}");
            } catch (\Throwable $e) {
                $this->log->error("Failed to process employee {$emp['code']}: " . $e->getMessage());
                // Continue with the rest — partial payroll rather than full failure
            }
        }

        $this->repo->updateHeaderTotals($ppId, array_merge($totals, [
            'status'       => 'CALCULATED',
            'processed_by' => $createdBy,
        ]));

        $this->log->info("Payroll run complete: $code | employees={$totals['total_employees']}, net={$totals['total_net_payable']}");

        return $ppId;
    }

    // ── Private helpers ────────────────────────────────────────

    private function calculateEmployeePayroll(
        array $emp,
        int $ppId,
        string $nepMonth,
        int $fiscalYearId,
        int $monthsRemaining,
        int $createdBy
    ): array {
        $basic = (float) $emp['basic_salary'];

        // Attendance summary
        $att = $this->repo->getAttendanceSummaryForPayroll($emp['id'], $nepMonth);

        $workingDays = (int) $att['working_days'] ?: self::DEFAULT_WORKING_DAYS;
        $presentDays = (int) $att['present_days'];
        $absentDays  = (int) $att['absent_days'];
        $leaveDays   = (int) $att['leave_days'];
        $holidays    = (int) $att['holidays'];
        $otHours     = (float) $att['ot_hours'];

        // Paid days = present + leaves (unpaid absences deducted)
        $paidDays = max(0, $workingDays - $absentDays);

        // Per-day and hourly rates
        $perDayRate  = $workingDays > 0 ? $basic / $workingDays : 0;
        $hourlyRate  = $perDayRate / 8;

        // Proportional basic (if any unpaid absence)
        $effectiveBasic = round($perDayRate * $paidDays, 2);

        // OT: 1.5× hourly rate
        $otAmount = round($otHours * $hourlyRate * 1.5, 2);

        // Gross = effective basic + OT (add other earnings components here later)
        $totalEarnings = $effectiveBasic;
        $grossSalary   = $totalEarnings + $otAmount;

        // Tax / statutory deductions
        $deductions = $this->taxService->calculateAllDeductions(
            $emp, $grossSalary, $fiscalYearId, $monthsRemaining
        );

        $totalDed  = $deductions['total_employee_deductions'];
        $netPayable = round($grossSalary - $totalDed, 2);

        return [
            'payroll_processing_id' => $ppId,
            'employee_id'           => $emp['id'],
            'total_working_days'    => $workingDays,
            'total_present_days'    => $presentDays,
            'total_absent_days'     => $absentDays,
            'total_leaves'          => $leaveDays,
            'total_holidays'        => $holidays,
            'total_paid_days'       => $paidDays,
            'overtime_hours'        => $otHours,
            'overtime_amount'       => $otAmount,
            'basic_salary'          => $effectiveBasic,
            'total_earnings'        => $totalEarnings,
            'gross_salary'          => $grossSalary,
            'ssf_employee'          => $deductions['ssf_employee'],
            'ssf_employer'          => $deductions['ssf_employer'],
            'pf_employee'           => $deductions['pf_employee'],
            'pf_employer'           => $deductions['pf_employer'],
            'income_tax'            => $deductions['income_tax_monthly'],
            'other_deductions'      => 0.0,
            'total_deductions'      => $totalDed,
            'net_payable'           => $netPayable,
            'created_by'            => $createdBy,
        ];
    }
}
