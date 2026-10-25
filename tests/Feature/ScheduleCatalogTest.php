<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\ScheduleOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_lecture_only_subjects_cannot_be_saved_as_laboratory(): void
    {
        foreach (['academic_term_mode' => 'manual', 'academic_semester' => 'First Semester', 'academic_year' => '2026-2027'] as $key => $value) {
            SystemSetting::updateOrCreate(['key' => $key], ['value' => $value]);
        }
        $thirdYear = ScheduleOptions::subjectsFor('BSIT 3A');
        $this->assertContains('ITEC 80', $thirdYear);
        $this->assertNotContains('ITEC 80A', $thirdYear);
        $this->assertSame('INTRODUCTION TO HUMAN COMPUTER INTERACTION', ScheduleOptions::all()['subjectNames']['ITEC 80']);
        $this->assertSame(['NSTP 1', 'CVSU 101', 'NSTP 2'], ScheduleOptions::all()['lectureOnlySubjects']);
        $employee = Employee::create(['employee_no' => 'LAB', 'first_name' => 'Lecture', 'last_name' => 'Faculty', 'rate_type' => 'hourly', 'rate_amount' => 100, 'status' => 'active']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach (['NSTP 1', 'CVSU 101', 'NSTP 2'] as $subject) {
            SystemSetting::where('key', 'academic_semester')->update(['value' => $subject === 'NSTP 2' ? 'Second Semester' : 'First Semester']);
            $block = ['day_of_week' => 1, 'schedule_type' => 'class', 'start_time' => '08:00', 'end_time' => '10:00', 'subject' => $subject, 'section' => 'BSIT 1A', 'class_mode' => 'lecture'];
            $this->put('/employees/'.$employee->id.'/schedule', ['blocks' => [$block]])->assertSessionHasNoErrors();
            $existing = $employee->schedules()->firstOrFail();
            $this->put('/employees/'.$employee->id.'/schedule', ['blocks' => [[...$block, 'id' => $existing->id, 'class_mode' => 'laboratory']]])->assertSessionHasErrors('blocks.0.subject');
            $this->assertSame('lecture', $existing->fresh()->class_mode);
            $block['subject'] = $subject === 'NSTP 2' ? 'DCIT 23A' : 'DCIT 21A';
            $block['class_mode'] = 'laboratory';
            $this->put('/employees/'.$employee->id.'/schedule', ['blocks' => [$block]])->assertSessionHasNoErrors();
        }
    }

    public function test_catalog_follows_section_year_and_configured_semester_and_validates_submissions(): void
    {
        foreach (['academic_term_mode' => 'manual', 'academic_semester' => 'First Semester', 'academic_year' => '2026-2027'] as $key => $value) {
            SystemSetting::updateOrCreate(['key' => $key], ['value' => $value]);
        }
        $this->assertSame(['COSC 50A', 'CVSU 101', 'DCIT 21A', 'DCIT 22A', 'NSTP 1'], ScheduleOptions::subjectsFor('BSIT 1A'));
        $this->assertSame(ScheduleOptions::subjectsFor('BSIT 1A'), ScheduleOptions::subjectsFor('BSIT 1F'));
        $this->assertSame(['DCIT 24', 'DCIT50', 'ITEC 55'], ScheduleOptions::subjectsFor('BSIT 2B'));
        $this->assertContains('DCIT 65', ScheduleOptions::subjectsFor('BSIT 4A'));
        $this->assertNotContains('DCIT 65A', ScheduleOptions::subjectsFor('BSIT 4A'));
        $this->assertSame(['DCIT 23A', 'ITEC 50A', 'NSTP 2'], ScheduleOptions::subjectsFor('BSIT 1A', 'Second Semester'));
        $this->assertSame(['ITEC 75A'], ScheduleOptions::subjectsFor('BSIT 2F', 'Midyear Term'));
        $this->assertSame([], ScheduleOptions::subjectsFor('BSIT 1A', 'Midyear Term'));
        $this->assertSame(['ITEC 199'], ScheduleOptions::subjectsFor('BSIT 4A', 'Second Semester'));
        $this->assertSame('INTRODUCTION TO COMPUTING', ScheduleOptions::all()['subjectNames']['DCIT 21A']);
        $this->assertSame('SYSTEM INTEGRATION AND ARCHITECTURE 1', ScheduleOptions::all()['subjectNames']['ITEC 75A']);
        $employee = Employee::create(['employee_no' => 'CAT', 'first_name' => 'Catalog', 'last_name' => 'Faculty', 'rate_type' => 'hourly', 'rate_amount' => 100, 'status' => 'active']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $block = ['day_of_week' => 1, 'schedule_type' => 'class', 'start_time' => '08:00', 'end_time' => '10:00', 'subject' => 'DCIT 21A', 'section' => 'BSIT 2A', 'class_mode' => 'lecture'];
        $this->put('/employees/'.$employee->id.'/schedule', ['blocks' => [$block]])->assertSessionHasErrors('blocks.0.subject');
        $block['section'] = 'BSIT 1A';
        $this->put('/employees/'.$employee->id.'/schedule', ['blocks' => [$block]])->assertSessionHasNoErrors();
        SystemSetting::where('key', 'academic_semester')->update(['value' => 'Second Semester']);
        $this->assertSame(['DCIT 23A', 'ITEC 50A', 'NSTP 2'], ScheduleOptions::all()['subjectsBySection']['BSIT 1A']);
        $this->put('/employees/'.$employee->id.'/schedule', ['blocks' => [$block]])->assertSessionHasErrors('blocks.0.subject');
        $block['subject'] = 'DCIT 23A';
        $this->put('/employees/'.$employee->id.'/schedule', ['blocks' => [$block]])->assertSessionHasNoErrors();
    }
}
