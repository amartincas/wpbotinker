<?php

namespace App\Platform\Memory;

use InvalidArgumentException;

/**
 * The Core catalog of person facts (config('botinker.person_facts')) and
 * the validation of their four basic types. Domains reference these facts;
 * they never redefine or validate them.
 *
 * Validation messages describe the expected format and never repeat the
 * customer's input (they may reach logs).
 */
class PersonFacts
{
    public const TYPES = ['date', 'time', 'text', 'number'];

    private const TEXT_MAX_LENGTH = 255;

    /**
     * @param  array<string, array{type: string, question: string, sensitive?: bool}>  $catalog
     */
    public function __construct(private readonly array $catalog) {}

    public function has(string $key): bool
    {
        return isset($this->catalog[$key]);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->catalog);
    }

    public function type(string $key): string
    {
        return $this->entry($key)['type'];
    }

    public function question(string $key): string
    {
        return $this->entry($key)['question'];
    }

    public function isSensitive(string $key): bool
    {
        return (bool) ($this->entry($key)['sensitive'] ?? false);
    }

    public function validate(string $key, string $value): FactValidation
    {
        $value = trim($value);

        return match ($this->type($key)) {
            'date' => $this->validateDate($value),
            'time' => $this->validateTime($value),
            'text' => $this->validateText($value),
            'number' => $this->validateNumber($value),
        };
    }

    /**
     * @return array{type: string, question: string, sensitive?: bool}
     */
    private function entry(string $key): array
    {
        $entry = $this->catalog[$key] ?? throw new InvalidArgumentException("Unknown person fact [{$key}].");

        if (! in_array($entry['type'] ?? null, self::TYPES, true)) {
            throw new InvalidArgumentException("Person fact [{$key}] has an invalid type; expected one of: ".implode(', ', self::TYPES).'.');
        }

        if (! is_string($entry['question'] ?? null) || trim($entry['question']) === '') {
            throw new InvalidArgumentException("Person fact [{$key}] needs a question.");
        }

        return $entry;
    }

    /** day/month/year (separators / . -) or ISO year-month-day → "Y-m-d". */
    private function validateDate(string $value): FactValidation
    {
        if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})$/', $value, $m)) {
            [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $m)) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return FactValidation::invalid('Escribe la fecha como día/mes/año, por ejemplo 15/03/1990.');
        }

        if (! checkdate($month, $day, $year)) {
            return FactValidation::invalid('Esa fecha no existe en el calendario. Escríbela como día/mes/año, por ejemplo 15/03/1990.');
        }

        return FactValidation::valid(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }

    /** "14:30", "2:30 pm", "2.30 p.m." → "H:i" (24 h). */
    private function validateTime(string $value): FactValidation
    {
        $invalid = FactValidation::invalid('Escribe la hora como horas:minutos, por ejemplo 14:30 o 2:30 pm.');

        if (! preg_match('/^(\d{1,2})[:.](\d{2})\s*(am|pm|a\.\s?m\.|p\.\s?m\.)?$/i', $value, $m)) {
            return $invalid;
        }

        [$hour, $minute] = [(int) $m[1], (int) $m[2]];
        $meridiem = isset($m[3]) ? strtolower(str_replace(['.', ' '], '', $m[3])) : null;

        if ($minute > 59 || ($meridiem === null && $hour > 23) || ($meridiem !== null && ($hour < 1 || $hour > 12))) {
            return $invalid;
        }

        if ($meridiem === 'pm' && $hour !== 12) {
            $hour += 12;
        } elseif ($meridiem === 'am' && $hour === 12) {
            $hour = 0;
        }

        return FactValidation::valid(sprintf('%02d:%02d', $hour, $minute));
    }

    private function validateText(string $value): FactValidation
    {
        if ($value === '' || mb_strlen($value) > self::TEXT_MAX_LENGTH) {
            return FactValidation::invalid('Escribe una respuesta de máximo '.self::TEXT_MAX_LENGTH.' caracteres.');
        }

        return FactValidation::valid($value);
    }

    /**
     * Plain number with optional decimals ("1500", "1500.50", "1500,50").
     * Thousands separators are rejected on purpose: "1.500" is ambiguous.
     */
    private function validateNumber(string $value): FactValidation
    {
        $compact = str_replace(' ', '', $value);

        if (! preg_match('/^-?\d+(?:[.,]\d{1,2})?$/', $compact)) {
            return FactValidation::invalid('Escribe solo el número, sin puntos de miles, por ejemplo 1500000.');
        }

        $number = (float) str_replace(',', '.', $compact);

        return FactValidation::valid(floor($number) === $number ? (int) $number : $number);
    }
}
