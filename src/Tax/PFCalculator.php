<?php

namespace Administrator\Deno2\Tax;

/**
 * Provident Fund (PF / CIT) calculation.
 * Applicable to PERMANENT employees only.
 * Nepal rate: 10% employee + 10% employer of basic salary.
 */
class PFCalculator
{
    private float $employeeRate;
    private float $employerRate;

    public function __construct(float $employeeRate = 0.10, float $employerRate = 0.10)
    {
        $this->employeeRate = $employeeRate;
        $this->employerRate = $employerRate;
    }

    /**
     * @param string $empType  'PERMANENT' | 'CONTRACT' | 'DAILY_WAGES'
     * @return array{employee: float, employer: float}
     */
    public function calculate(float $basicSalary, string $empType): array
    {
        if ($empType !== 'PERMANENT') {
            return ['employee' => 0.0, 'employer' => 0.0];
        }
        return [
            'employee' => round($basicSalary * $this->employeeRate, 2),
            'employer' => round($basicSalary * $this->employerRate, 2),
        ];
    }
}
