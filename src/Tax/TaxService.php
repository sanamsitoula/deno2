<?php

namespace Administrator\Deno2\Tax;

use PDO;

/**
 * Orchestrates SSF + PF + Income Tax calculation for a single employee-month.
 * Returns a flat array consumed directly by PayrollService.
 */
class TaxService
{
    private SSFCalculator $ssf;
    private PFCalculator $pf;
    private IncomeTaxCalculator $incomeTax;

    public function __construct(PDO $db)
    {
        $this->ssf       = new SSFCalculator();
        $this->pf        = new PFCalculator();
        $this->incomeTax = new IncomeTaxCalculator($db);
    }

    /**
     * Calculate all statutory deductions for one employee in one payroll month.
     *
     * @param array $employee   Must contain: basic_salary, is_ssf_enrolled, emp_type, taxpayer_type
     * @param float $grossSalary  Basic + all earnings + OT
     * @param int   $fiscalYearId
     * @param int   $monthsRemaining  Months left in fiscal year (for TDS spreading)
     * @param float $ytdIncomeTaxPaid  Year-to-date tax already deducted
     *
     * @return array{
     *   ssf_employee: float, ssf_employer: float,
     *   pf_employee: float,  pf_employer: float,
     *   taxable_income_annual: float,
     *   annual_income_tax: float,
     *   income_tax_monthly: float,
     *   total_employee_deductions: float,
     *   total_employer_contributions: float
     * }
     */
    public function calculateAllDeductions(
        array $employee,
        float $grossSalary,
        int $fiscalYearId,
        int $monthsRemaining = 12,
        float $ytdIncomeTaxPaid = 0.0
    ): array {
        $basic = (float) $employee['basic_salary'];

        // SSF
        $ssfResult = $this->ssf->calculate($basic, (bool) $employee['is_ssf_enrolled']);

        // PF
        $pfResult = $this->pf->calculate($basic, $employee['emp_type']);

        // Taxable income (monthly → annualize for slab calculation)
        $monthlyTaxable  = $grossSalary - $ssfResult['employee'] - $pfResult['employee'];
        $annualTaxable   = $monthlyTaxable * 12;

        // Income tax
        $annualTax      = $this->incomeTax->calculateAnnualTax(
            $annualTaxable,
            $employee['taxpayer_type'] ?? 'SINGLE',
            $fiscalYearId
        );
        $monthlyTDS     = $this->incomeTax->calculateMonthlyTDS(
            $annualTax,
            $monthsRemaining,
            $ytdIncomeTaxPaid
        );

        $totalEmployeeDeductions   = $ssfResult['employee'] + $pfResult['employee'] + $monthlyTDS;
        $totalEmployerContributions = $ssfResult['employer'] + $pfResult['employer'];

        return [
            'ssf_employee'               => $ssfResult['employee'],
            'ssf_employer'               => $ssfResult['employer'],
            'pf_employee'                => $pfResult['employee'],
            'pf_employer'                => $pfResult['employer'],
            'taxable_income_annual'      => round($annualTaxable, 2),
            'annual_income_tax'          => $annualTax,
            'income_tax_monthly'         => $monthlyTDS,
            'total_employee_deductions'  => round($totalEmployeeDeductions, 2),
            'total_employer_contributions'=> round($totalEmployerContributions, 2),
        ];
    }
}
