<?php

namespace Administrator\Deno2\Payroll;

use PDO;
use TCPDF;

/**
 * Generate payslip PDFs using TCPDF (already in vendor/).
 *
 * Usage:
 *   $gen = new PayslipGenerator($db);
 *   $gen->outputSingle($payrollDetailId);          // streams to browser
 *   $path = $gen->saveSingle($payrollDetailId);    // saves to disk, returns path
 *   $gen->outputBulk($payrollProcessingId);        // all slips for a run
 */
class PayslipGenerator
{
    private PDO $db;
    private PayrollRepository $repo;

    public function __construct(PDO $db)
    {
        $this->db   = $db;
        $this->repo = new PayrollRepository($db);
    }

    /**
     * Stream a single payslip PDF to the browser.
     */
    public function outputSingle(int $payrollDetailId): void
    {
        $detail = $this->fetchDetail($payrollDetailId);
        $pdf    = $this->buildPdf($detail);
        $pdf->Output('Payslip_' . $detail['employee_code'] . '.pdf', 'I');
    }

    /**
     * Save a single payslip to disk and return the file path.
     */
    public function saveSingle(int $payrollDetailId): string
    {
        $detail = $this->fetchDetail($payrollDetailId);
        $pdf    = $this->buildPdf($detail);

        $dir  = $_SERVER['DOCUMENT_ROOT'] . '/deno2/uploads/payslips/';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $file = $dir . 'payslip_' . $detail['employee_code'] . '_'
              . $detail['payroll_year'] . sprintf('%02d', $detail['payroll_month']) . '.pdf';
        $pdf->Output($file, 'F');
        return $file;
    }

    /**
     * Stream all payslips for a payroll run as a single merged PDF.
     */
    public function outputBulk(int $payrollProcessingId): void
    {
        $details = $this->repo->findDetailsByHeader($payrollProcessingId);
        if (empty($details)) {
            return;
        }

        // Build first slip as base PDF
        $detail = $this->fetchDetail((int) $details[0]['id']);
        $pdf    = $this->buildPdf($detail);

        // Append remaining slips
        foreach (array_slice($details, 1) as $row) {
            $detail = $this->fetchDetail((int) $row['id']);
            $pdf->AddPage();
            $this->renderSlipContent($pdf, $detail);
        }

        $header = $this->repo->findHeaderById($payrollProcessingId);
        $pdf->Output('Payroll_' . ($header['payroll_code'] ?? $payrollProcessingId) . '.pdf', 'I');
    }

    // ── Private ────────────────────────────────────────────────

    private function fetchDetail(int $id): array
    {
        $stmt = $this->db->prepare("
            SELECT
                pd.*,
                e.code   AS employee_code,
                e.name   AS employee_name,
                e.name_nep,
                e.pan_no,
                e.bank_name,
                e.bank_account_no,
                d.name   AS designation_name,
                dep.name AS department_name,
                pp.payroll_month, pp.payroll_year, pp.payroll_code
            FROM payroll_details pd
            JOIN employee         e   ON pd.employee_id = e.id
            LEFT JOIN designation d   ON e.designation_id = d.id
            LEFT JOIN department  dep ON e.department_id  = dep.id
            JOIN payroll_processing pp ON pd.payroll_processing_id = pp.id
            WHERE pd.id = :id
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \InvalidArgumentException("Payroll detail $id not found");
        }
        return $row;
    }

    private function buildPdf(array $detail): TCPDF
    {
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $pdf->SetCreator('JEMC Payroll System');
        $pdf->SetAuthor('JEMC');
        $pdf->SetTitle('Payslip – ' . $detail['employee_code']);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(15, 15, 15);
        $pdf->AddPage();
        $this->renderSlipContent($pdf, $detail);
        return $pdf;
    }

    private function renderSlipContent(TCPDF $pdf, array $d): void
    {
        $monthNames = [
            '', 'Baisakh', 'Jestha', 'Ashadh', 'Shrawan', 'Bhadra', 'Ashwin',
            'Kartik', 'Mangsir', 'Poush', 'Magh', 'Falgun', 'Chaitra',
        ];
        $monthLabel = ($monthNames[$d['payroll_month']] ?? '') . ' ' . $d['payroll_year'];

        // Header
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 8, 'Janak Education Materials Center', 0, 1, 'C');
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 6, 'SALARY SLIP — ' . strtoupper($monthLabel), 0, 1, 'C');
        $pdf->Ln(4);

        // Employee info table
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetFillColor(220, 230, 242);
        $labelW = 45;
        $valueW = 55;

        $fields = [
            'Employee Code'  => $d['employee_code'],
            'Name'           => $d['employee_name'],
            'Designation'    => $d['designation_name'] ?? '',
            'Department'     => $d['department_name']  ?? '',
            'PAN No.'        => $d['pan_no'] ?? '',
            'Bank / Account' => trim(($d['bank_name'] ?? '') . ' / ' . ($d['bank_account_no'] ?? ''), ' /'),
        ];

        $pdf->SetFont('helvetica', '', 9);
        $i = 0;
        $pairs = array_chunk(array_map(null, array_keys($fields), array_values($fields)), 2);
        foreach ($pairs as $pair) {
            foreach ($pair as $item) {
                if ($item === null) break;
                [$label, $value] = $item;
                $pdf->SetFont('helvetica', 'B', 9);
                $pdf->Cell($labelW, 6, $label . ':', 1, 0, 'L', true);
                $pdf->SetFont('helvetica', '', 9);
                $pdf->Cell($valueW, 6, $value, 1, 0, 'L');
            }
            $pdf->Ln();
        }

        $pdf->Ln(4);

        // Earnings | Deductions side by side
        $colW = 90;
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetFillColor(66, 103, 178);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell($colW, 7, 'EARNINGS', 1, 0, 'C', true);
        $pdf->Cell($colW, 7, 'DEDUCTIONS', 1, 1, 'C', true);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFillColor(240, 244, 250);

        $earnings = [
            'Basic Salary'   => $d['basic_salary'],
            'Overtime'       => $d['overtime_amount'],
            'Gross Salary'   => $d['gross_salary'],
        ];
        $deductions = [
            'SSF (Employee)' => $d['ssf_employee'],
            'PF (Employee)'  => $d['pf_employee'],
            'Income Tax'     => $d['income_tax'],
            'Other'          => $d['other_deductions'],
            'Total Deductions'=> $d['total_deductions'],
        ];

        $earningKeys   = array_keys($earnings);
        $deductionKeys = array_keys($deductions);
        $rows = max(count($earningKeys), count($deductionKeys));

        $pdf->SetFont('helvetica', '', 9);
        for ($r = 0; $r < $rows; $r++) {
            $eLabel = $earningKeys[$r]   ?? '';
            $eVal   = isset($earningKeys[$r])   ? number_format((float) $earnings[$earningKeys[$r]], 2) : '';
            $dLabel = $deductionKeys[$r] ?? '';
            $dVal   = isset($deductionKeys[$r]) ? number_format((float) $deductions[$deductionKeys[$r]], 2) : '';

            $fill = ($r % 2 === 0);
            $pdf->Cell($colW / 2, 6, $eLabel, 1, 0, 'L', $fill);
            $pdf->Cell($colW / 2, 6, $eVal,   1, 0, 'R', $fill);
            $pdf->Cell($colW / 2, 6, $dLabel, 1, 0, 'L', $fill);
            $pdf->Cell($colW / 2, 6, $dVal,   1, 1, 'R', $fill);
        }

        $pdf->Ln(3);

        // Net payable
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetFillColor(34, 139, 34);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell(130, 8, 'NET PAYABLE', 1, 0, 'L', true);
        $pdf->Cell(50,  8, 'NPR ' . number_format((float) $d['net_payable'], 2), 1, 1, 'R', true);
        $pdf->SetTextColor(0, 0, 0);

        $pdf->Ln(4);

        // Attendance summary
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(0, 6, 'ATTENDANCE SUMMARY', 0, 1);
        $pdf->SetFont('helvetica', '', 9);
        $attFields = [
            'Working Days' => $d['total_working_days'],
            'Present'      => $d['total_present_days'],
            'Absent'       => $d['total_absent_days'],
            'On Leave'     => $d['total_leaves'],
            'Holidays'     => $d['total_holidays'],
            'OT Hours'     => number_format((float) $d['overtime_hours'], 1),
        ];
        $w = 30;
        foreach ($attFields as $label => $val) {
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->Cell($w, 5, $label . ':', 0, 0);
            $pdf->SetFont('helvetica', '', 9);
            $pdf->Cell($w, 5, $val, 0, 0);
        }
        $pdf->Ln(8);

        // Signature line
        $pdf->SetFont('helvetica', '', 8);
        $pdf->Cell(90, 5, 'Employee Signature: ___________________', 0, 0);
        $pdf->Cell(90, 5, 'Authorized Signature: ___________________', 0, 1, 'R');
        $pdf->Ln(2);
        $pdf->SetFont('helvetica', 'I', 7);
        $pdf->Cell(0, 5, 'Generated by JEMC Payroll System | ' . date('Y-m-d H:i'), 0, 1, 'C');
    }
}
