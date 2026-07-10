<?php
declare(strict_types=1);

final class PedagogicalCalendar
{
    /** @var array<int, string> */
    private const MONTH_LABELS = [
        1 => 'Janvier',
        2 => 'Février',
        3 => 'Mars',
        4 => 'Avril',
        5 => 'Mai',
        6 => 'Juin',
        7 => 'Juillet',
        8 => 'Août',
        9 => 'Septembre',
        10 => 'Octobre',
        11 => 'Novembre',
        12 => 'Décembre',
    ];

    public static function getWeekIndex(DateTimeInterface $date): int
    {
        $day = (int) $date->format('j');

        if ($day <= 7) {
            return 1;
        }

        if ($day <= 14) {
            return 2;
        }

        if ($day <= 21) {
            return 3;
        }

        return 4;
    }

    public static function getMonthLabel(DateTimeInterface $date): string
    {
        $monthNumber = (int) $date->format('n');

        return self::MONTH_LABELS[$monthNumber] ?? $date->format('F');
    }

    public static function getMonthNumber(DateTimeInterface $date): int
    {
        return (int) $date->format('n');
    }

    /**
     * @return array{month:string, month_number:int, week:int}
     */
    public static function getPedagogicalWeek(DateTimeInterface $date): array
    {
        return [
            'month' => self::getMonthLabel($date),
            'month_number' => self::getMonthNumber($date),
            'week' => self::getWeekIndex($date),
        ];
    }
}
