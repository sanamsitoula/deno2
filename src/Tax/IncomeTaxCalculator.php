<?php

namespace Administrator\Deno2\Tax;

use PDO;

class IncomeTaxCalculator
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Calculate annual income tax using slab table.
     *
     * @param float  $annualTaxableIncome  Gross annual - SSF employee - PF employee
     * @param string $taxpayerType         'SINGLE' | 'COUPLE'
     * @param int    $fiscalYearId
     */
    public function calculateAnnualTax(
        float $annualTaxableIncome,
        string $taxpayerType,
        int $fiscalYearId
    ): float {
        $slabs = $this->getSlabs($fiscalYearId, $taxpayerType);

        // Fallback: if no slabs in DB yet, use hardcoded FY 2081/82 values
        if (empty($slabs)) {
            $slabs = $this->defaultSlabs($taxpayerType);
        }

        $tax      = 0.0;
        $remaining = $annualTaxableIncome;

        foreach ($slabs as $slab) {
            if ($remaining <= 0) break;

            $from  = (float) $slab['income_from'];
            $to    = $slab['income_to'] !== null ? (float) $slab['income_to'] : PHP_INT_MAX;
            $width = $to - $from;

            $taxable = min($remaining, $width);
            $tax    += $taxable * (float) $slab['tax_rate'];
            $remaining -= $taxable;
        }

        return round($tax, 2);
    }

    /**
     * Monthly TDS = annual tax / 12.
     * In the last month of the fiscal year, pass $ytdTaxPaid to auto-adjust.
     */
    public function calculateMonthlyTDS(
        float $annualTax,
        int $monthsRemaining = 12,
        float $ytdTaxPaid = 0.0
    ): float {
        if ($monthsRemaining <= 0) {
            return max(0.0, round($annualTax - $ytdTaxPaid, 2));
        }
        return round(($annualTax - $ytdTaxPaid) / $monthsRemaining, 2);
    }

    // ── Private ────────────────────────────────────────────────

    private function getSlabs(int $fiscalYearId, string $taxpayerType): array
    {
        $stmt = $this->db->prepare("
            SELECT income_from, income_to, tax_rate
            FROM tax_slabs
            WHERE fiscal_year_id = :fy AND taxpayer_type = :type AND is_active = true
            ORDER BY slab_order
        ");
        $stmt->execute([':fy' => $fiscalYearId, ':type' => $taxpayerType]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Nepal income tax slabs — FY 2081/82 (verify each year with IRD).
     * Single taxpayer: first bracket 0–500,000 @ 1%.
     * Couple taxpayer: first bracket 0–600,000 @ 1%.
     */
    private function defaultSlabs(string $taxpayerType): array
    {
        $firstLimit = ($taxpayerType === 'COUPLE') ? 600000 : 500000;
        return [
            ['income_from' =>       0, 'income_to' => $firstLimit, 'tax_rate' => 0.01],
            ['income_from' => $firstLimit, 'income_to' => 700000,  'tax_rate' => 0.10],
            ['income_from' => 700000, 'income_to' => 1000000,      'tax_rate' => 0.20],
            ['income_from' => 1000000,'income_to' => 2000000,      'tax_rate' => 0.30],
            ['income_from' => 2000000,'income_to' => null,         'tax_rate' => 0.36],
        ];
    }
}
