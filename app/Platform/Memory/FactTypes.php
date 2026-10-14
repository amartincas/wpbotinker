<?php

namespace App\Platform\Memory;

use InvalidArgumentException;

/**
 * The basic fact types and their validation, shared by the person catalog
 * and by facts declared in configured topics. A value is accepted only in
 * the formats below and stored normalized.
 *
 * Validation messages describe the expected format and never repeat the
 * customer's input (they may reach logs).
 */
class FactTypes
{
    public const TYPES = ['date', 'time', 'text', 'number'];

    private const TEXT_MAX_LENGTH = 255;

    public function validate(string $type, string $value): FactValidation
    {
        $value = trim($value);

        return match ($type) {
            'date' => $this->validateDate($value),
            'time' => $this->validateTime($value),
            'text' => $this->validateText($value),
            'number' => $this->validateNumber($value),
            default => throw new InvalidArgumentException("Unknown fact type [{$type}]; expected one of: ".implode(', ', self::TYPES).'.'),
        };
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
