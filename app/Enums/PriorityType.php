<?php

namespace App\Enums;

/**
 * Statutory priority categories.
 *
 * RA 9994  -> Senior Citizens (60 years old and older)
 * RA 10754 -> Persons with Disability
 * Plus the pregnant-client category honoured by the office.
 */
enum PriorityType: string
{
    case SeniorCitizen = 'senior_citizen';
    case Pwd = 'pwd';
    case Pregnant = 'pregnant';

    public function label(): string
    {
        return match ($this) {
            self::SeniorCitizen => 'Senior Citizen',
            self::Pwd => 'Person with Disability',
            self::Pregnant => 'Pregnant Client',
        };
    }

    public function basis(): string
    {
        return match ($this) {
            self::SeniorCitizen => 'RA 9994',
            self::Pwd => 'RA 10754',
            self::Pregnant => 'Office priority policy',
        };
    }
}
