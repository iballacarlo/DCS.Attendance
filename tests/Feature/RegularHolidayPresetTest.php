<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\Employee;
use App\Models\User;
use App\Services\ClassReportService;
use App\Services\DtrService;
use App\Services\RegularHolidayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RegularHolidayPresetTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_years_have_correct_movable_dates_and_no_guessed_eid_dates(): void
    {
        $this->assertCount(12, RegularHolidayService::events(2026));
        $this->assertCount(10, RegularHolidayService::events(2027));
        foreach (['2026-04-02' => 'Maundy Thursday', '2026-04-03' => 'Good Friday', '2026-08-31' => 'National Heroes Day', '2027-03-25' => 'Maundy Thursday', '2027-03-26' => 'Good Friday', '2027-08-30' => 'National Heroes Day', '2026-03-20' => "Eid'l Fitr", '2026-05-27' => "Eid'l Adha"] as $date => $title) {
            $this->assertSame($title, RegularHolidayService::eventFor($date)->title);
        }
        $this->assertSame(["Eid'l Fitr", "Eid'l Adha"], RegularHolidayService::info(2027)['pending']);
        $this->assertCount(0, RegularHolidayService::events(2028));
        $this->assertFalse(RegularHolidayService::info(2028)['supported']);
        $this->assertNull(RegularHolidayService::eventFor('2026-02-25')); // Special working day, not a regular holiday.
        $this->assertNull(RegularHolidayService::eventFor('2026-04-04')); // Black Saturday is special, not regular.
    }

    public function test_calendar_presets_are_available_without_database_writes_or_duplicates(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        for ($i = 0; $i < 2; $i++) {
            $this->get('/school-calendar?month=2026-12')->assertOk()->assertInertia(fn (Assert $p) => $p->has('regularHolidays', 12)->has('events', 0)->where('holidayPreset.supported', true)->where('regularHolidays.0.automatic', true));
        }
        $this->assertDatabaseCount('calendar_events', 0);
        $this->get('/school-calendar?month=2027-03')->assertInertia(fn (Assert $p) => $p->has('regularHolidays', 10)->has('holidayPreset.pending', 2));
        $this->get('/school-calendar?month=2028-01')->assertInertia(fn (Assert $p) => $p->has('regularHolidays', 0)->where('holidayPreset.supported', false));
    }

    public function test_regular_holiday_excludes_classes_without_opening_calendar_or_creating_an_event(): void
    {
        $employee = Employee::create(['employee_no' => 'HOLIDAY-AUTO', 'first_name' => 'Holiday', 'last_name' => 'Faculty', 'rate_amount' => 0, 'status' => 'active']);
        $employee->schedules()->create(['day_of_week' => 1, 'schedule_type' => 'class', 'start_time' => '08:00', 'end_time' => '10:00', 'subject' => 'DCIT 21', 'section' => 'BSIT 1A', 'class_mode' => 'lecture']);
        $this->travelTo(Carbon::parse('2026-12-01 12:00', 'Asia/Manila'));
        $report = app(ClassReportService::class)->build(['start_date' => '2026-11-30', 'end_date' => '2026-11-30']);
        $this->assertSame(1, $report['summary']['excluded']);
        $this->assertSame(0, $report['summary']['missed']);
        $this->assertSame(0, $report['summary']['ended']);
        $this->assertSame('Bonifacio Day', $report['rows'][0]['exclusion']);
        $dtr = app(DtrService::class)->build($employee, '2026-11-16', '2026-11-30');
        $row = $dtr['rows']->firstWhere('date', '2026-11-30');
        $this->assertSame('Bonifacio Day', $row['event']['title']);
        $this->assertSame('calendar_event', $row['status']);
        $this->assertNull($row['time_in']);
        $this->assertNull($row['worked_hours']);
        $this->assertEquals(0, $dtr['summary']['total_hours']);
        $this->assertDatabaseCount('calendar_events', 0);
        $this->assertDatabaseCount('entrance_attendances', 0);
    }

    public function test_manual_events_remain_available_alongside_presets(): void
    {
        $manual = CalendarEvent::create(['title' => 'Department suspension', 'type' => 'suspension', 'start_date' => '2026-12-02', 'end_date' => '2026-12-02', 'scope_type' => 'campus', 'is_paid' => false]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/school-calendar?month=2026-12')->assertInertia(fn (Assert $p) => $p->has('events', 1)->where('events.0.id', $manual->id)->has('regularHolidays', 12));
        $this->assertDatabaseCount('calendar_events', 1);
    }
}
