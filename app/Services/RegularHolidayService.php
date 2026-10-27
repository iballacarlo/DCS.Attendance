<?php

namespace App\Services;

use App\Models\CalendarEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class RegularHolidayService
{
    // Only dates confirmed by the cited official issuances belong here.
    // Do not calculate provisional Eid dates or assume substitute weekdays.
    private const DATES = [
        2026 => [
            '01-01' => "New Year's Day", '03-20' => "Eid'l Fitr",
            '04-02' => 'Maundy Thursday', '04-03' => 'Good Friday',
            '04-09' => 'Araw ng Kagitingan', '05-01' => 'Labor Day',
            '05-27' => "Eid'l Adha", '06-12' => 'Independence Day',
            '08-31' => 'National Heroes Day', '11-30' => 'Bonifacio Day',
            '12-25' => 'Christmas Day', '12-30' => 'Rizal Day',
        ],
        2027 => [
            '01-01' => "New Year's Day", '03-25' => 'Maundy Thursday',
            '03-26' => 'Good Friday', '04-09' => 'Araw ng Kagitingan',
            '05-01' => 'Labor Day', '06-12' => 'Independence Day',
            '08-30' => 'National Heroes Day', '11-30' => 'Bonifacio Day',
            '12-25' => 'Christmas Day', '12-30' => 'Rizal Day',
        ],
    ];

    public static function info(int $year): array
    {
        return [
            'year' => $year, 'supported' => isset(self::DATES[$year]),
            'pending' => $year === 2027 ? ["Eid'l Fitr", "Eid'l Adha"] : [],
            'sources' => match ($year) {
                2026 => [
                    ['title' => 'Proclamation No. 1006 (2026 holidays)', 'url' => 'https://elibrary.judiciary.gov.ph/thebookshelf/showdocs/7/99678'],
                    ['title' => 'Proclamation No. 1189 (Eid\'l Fitr)', 'url' => 'https://www.prc.gov.ph/article/prc-observes-feast-ramadhan'],
                    ['title' => 'Proclamation No. 1264 (Eid\'l Adha)', 'url' => 'https://elibrary.judiciary.gov.ph/thebookshelf/showdocs/7/101531'],
                ],
                2027 => [['title' => 'Proclamation No. 1427 (2027 holidays)', 'url' => 'https://pco.gov.ph/news_releases/pbbm-nagdeklara-ng-mga-regular-at-special-holiday-sa-2027/']],
                default => [],
            },
        ];
    }

    public static function events(int $year): Collection
    {
        return collect(self::DATES[$year] ?? [])->map(function ($title, $day) use ($year) {
            return new CalendarEvent([
                'title' => $title, 'type' => 'regular_holiday',
                'start_date' => $year.'-'.$day, 'end_date' => $year.'-'.$day,
                'scope_type' => 'campus', 'is_paid' => false,
            ]);
        })->values();
    }

    public static function eventFor(Carbon|string $date): ?CalendarEvent
    {
        $date = $date instanceof Carbon ? $date : Carbon::parse($date, 'Asia/Manila');
        $title = self::DATES[$date->year][$date->format('m-d')] ?? null;

        return $title ? new CalendarEvent([
            'title' => $title, 'type' => 'regular_holiday',
            'start_date' => $date->toDateString(), 'end_date' => $date->toDateString(),
            'scope_type' => 'campus', 'is_paid' => false,
        ]) : null;
    }

    public static function calendarRows(int $year): Collection
    {
        return self::events($year)->map(fn ($event) => [
            ...$event->toArray(), 'id' => 'regular-holiday-'.$event->start_date->toDateString(),
            'automatic' => true, 'employees' => [], 'remarks' => 'Official regular holiday preset.',
        ]);
    }
}
