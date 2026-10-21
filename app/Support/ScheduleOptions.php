<?php

namespace App\Support;

class ScheduleOptions
{
    public const SUBJECTS = ['COSC 50', 'DCIT 21', 'DCIT 22', 'DCIT 24', 'DCIT 26', 'DCIT 50', 'INSY 55', 'ITEC 55', 'ITEC 80', 'ITEC 85', 'ITEC 90', 'ITEC 110', 'ITEC 111', 'ITEC 116'];

    public const CLASS_MODES = ['lecture' => 'Lecture', 'laboratory' => 'Laboratory'];

    public static function sections(): array
    {
        $sections = [];
        foreach (range(1, 4) as $year) {
            foreach (range('A', 'F') as $letter) {
                $sections[] = "BSIT {$year}{$letter}";
            }
        }

        return $sections;
    }

    public static function all(): array
    {
        return ['subjects' => self::SUBJECTS, 'sections' => self::sections(), 'classModes' => self::CLASS_MODES];
    }
}
