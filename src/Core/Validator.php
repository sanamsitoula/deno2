<?php

namespace Administrator\Deno2\Core;

class Validator
{
    private array $errors = [];

    public function required(string $field, $value): self
    {
        if ($value === null || $value === '') {
            $this->errors[$field] = "$field is required";
        }
        return $this;
    }

    public function numeric(string $field, $value): self
    {
        if ($value !== null && $value !== '' && !is_numeric($value)) {
            $this->errors[$field] = "$field must be a number";
        }
        return $this;
    }

    public function positiveInt(string $field, $value): self
    {
        if ($value !== null && $value !== '' && (!ctype_digit((string) $value) || (int) $value <= 0)) {
            $this->errors[$field] = "$field must be a positive integer";
        }
        return $this;
    }

    public function maxLength(string $field, $value, int $max): self
    {
        if ($value !== null && mb_strlen((string) $value) > $max) {
            $this->errors[$field] = "$field must not exceed $max characters";
        }
        return $this;
    }

    public function minLength(string $field, $value, int $min): self
    {
        if ($value !== null && mb_strlen((string) $value) < $min) {
            $this->errors[$field] = "$field must be at least $min characters";
        }
        return $this;
    }

    public function inList(string $field, $value, array $allowed): self
    {
        if ($value !== null && $value !== '' && !in_array($value, $allowed, true)) {
            $this->errors[$field] = "$field must be one of: " . implode(', ', $allowed);
        }
        return $this;
    }

    public function email(string $field, $value): self
    {
        if ($value !== null && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field] = "$field must be a valid email address";
        }
        return $this;
    }

    /**
     * Validate Bikram Sambat date string (YYYY-MM-DD).
     * Checks format and approximate range (2000-2099 BS).
     */
    public function nepaliDate(string $field, $value): self
    {
        if ($value === null || $value === '') {
            return $this;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value)) {
            $this->errors[$field] = "$field must be a valid BS date (YYYY-MM-DD)";
            return $this;
        }
        [$year, $month, $day] = explode('-', (string) $value);
        if ((int) $year < 2000 || (int) $year > 2200) {
            $this->errors[$field] = "$field year is out of valid BS range";
        } elseif ((int) $month < 1 || (int) $month > 12) {
            $this->errors[$field] = "$field month must be between 1 and 12";
        } elseif ((int) $day < 1 || (int) $day > 32) {
            $this->errors[$field] = "$field day must be between 1 and 32";
        }
        return $this;
    }

    /**
     * Validate standard Gregorian date (YYYY-MM-DD).
     */
    public function date(string $field, $value): self
    {
        if ($value === null || $value === '') {
            return $this;
        }
        $d = \DateTime::createFromFormat('Y-m-d', (string) $value);
        if (!$d || $d->format('Y-m-d') !== (string) $value) {
            $this->errors[$field] = "$field must be a valid date (YYYY-MM-DD)";
        }
        return $this;
    }

    public function fails(): bool
    {
        return !empty($this->errors);
    }

    public function passes(): bool
    {
        return empty($this->errors);
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): string
    {
        return reset($this->errors) ?: '';
    }
}
