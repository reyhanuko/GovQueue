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

    /**
     * A short name, for a line that has to say which claim is which.
     *
     * "Person with Disability" is the right name on a form and the wrong
     * name in a reference that has to fit next to another one.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::SeniorCitizen => 'Senior Citizen',
            self::Pwd => 'PWD',
            self::Pregnant => 'Pregnant',
        };
    }

    /**
     * The categories whose evidence cannot be inferred and so needs a
     * reference of its own.
     *
     * A senior citizen claim is recognised from the date of birth on file, so
     * it needs nothing from the client. PWD and pregnancy cannot be inferred
     * from anything, and each has its own document.
     *
     * @return array<int, self>
     */
    public static function requiringReference(): array
    {
        return [self::Pwd, self::Pregnant];
    }

    /**
     * Combine the references of several claimed categories into one line.
     *
     * A client may claim more than one category, and each has its own
     * document, so each has its own reference. The appointment carries one
     * reference field, so when there is more than one they are kept together
     * with the category they belong to - "PWD-1221, Pregnant: PREG-2212" -
     * rather than as two numbers the staff have to guess between.
     *
     * A single reference is left exactly as it was typed: labelling the only
     * reference on the ticket would be noise.
     *
     * @param  array<string, string|null>  $references  keyed by category value
     */
    public static function joinReferences(array $references): ?string
    {
        $given = [];

        foreach ($references as $value => $reference) {
            $reference = trim((string) $reference);

            if ($reference !== '') {
                $given[$value] = $reference;
            }
        }

        if ($given === []) {
            return null;
        }

        if (count($given) === 1) {
            return reset($given);
        }

        $labelled = [];

        foreach ($given as $value => $reference) {
            $type = self::tryFrom((string) $value);

            $labelled[] = ($type?->shortLabel() ?? (string) $value).': '.$reference;
        }

        return implode(', ', $labelled);
    }
}
