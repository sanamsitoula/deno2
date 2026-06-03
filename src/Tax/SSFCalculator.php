<?php

namespace Administrator\Deno2\Tax;

/**
 * Social Security Fund (SSF) calculation.
 * Nepal rates (FY 2081/82):
 *   Employee  : 11% of basic salary
 *   Employer  : 20% of basic salary
 */
class SSFCalculator
{
    private float $employeeRate;
    private float $employerRate;

    public function __construct(float $employeeRate = 0.11, float $employerRate = 0.20)
    {
        $this->employeeRate = $employeeRate;
        $this->employerRate = $employerRate;
    }

    /**
     * @return array{employee: float, employer: float}
     */
    public function calculate(float $basicSalary, bool $isEnrolled): array
    {
        if (!$isEnrolled) {
            return ['employee' => 0.0, 'employer' => 0.0];
        }
        return [
            'employee' => round($basicSalary * $this->employeeRate, 2),
            'employer' => round($basicSalary * $this->employerRate, 2),
        ];
    }
}
