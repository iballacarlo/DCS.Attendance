<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Employee;
use App\Models\FacultySchedule;
use App\Models\Subject;
use App\Models\User;
use App\Support\ScheduleOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubjectManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_add_edit_and_deactivate_a_subject(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get('/settings/subjects')->assertOk();
        $this->post('/settings/subjects', [
            'course_scope' => 'BSIT',
            'code' => ' itec   120 ',
            'name' => ' mobile application development ',
            'year_level' => 3,
            'semester' => 'First Semester',
            'lecture_only' => false,
            'is_active' => true,
        ])->assertSessionHasNoErrors();

        $subject = Subject::where('code', 'ITEC 120')->firstOrFail();
        $this->assertSame('MOBILE APPLICATION DEVELOPMENT', $subject->name);
        $this->assertContains('ITEC 120', ScheduleOptions::subjectsFor('BSIT 3A', 'First Semester'));

        $this->put('/settings/subjects/'.$subject->id, [
            'course_scope' => 'BOTH',
            'code' => 'ITEC 120',
            'name' => 'MOBILE APPLICATION DEVELOPMENT 2',
            'year_level' => 4,
            'semester' => 'Second Semester',
            'lecture_only' => true,
            'is_active' => true,
        ])->assertSessionHasNoErrors();
        $this->assertContains('ITEC 120', ScheduleOptions::subjectsFor('BSIT 4A', 'Second Semester'));
        $this->assertContains('ITEC 120', ScheduleOptions::lectureOnlySubjects());

        $this->patch('/settings/subjects/'.$subject->id.'/status')->assertSessionHasNoErrors();
        $this->assertFalse($subject->fresh()->is_active);
        $this->assertNotContains('ITEC 120', ScheduleOptions::subjectsFor('BSIT 4A', 'Second Semester'));
    }

    public function test_faculty_cannot_manage_subjects_and_duplicate_codes_are_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $faculty = User::factory()->create(['role' => 'faculty']);

        $this->actingAs($admin)->post('/settings/subjects', [
            'course_scope' => 'BSIT',
            'code' => 'DCIT 21A',
            'name' => 'DUPLICATE',
            'year_level' => 1,
            'semester' => 'First Semester',
            'lecture_only' => false,
            'is_active' => true,
        ])->assertSessionHasErrors('code');

        $this->actingAs($faculty)->get('/settings/subjects')->assertForbidden();
    }

    public function test_subject_rules_protect_saved_and_open_schedules(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $employee = Employee::create(['employee_no' => 'SUB-001', 'first_name' => 'Subject', 'last_name' => 'Faculty', 'rate_type' => 'hourly', 'rate_amount' => 100, 'status' => 'active']);
        $term = AcademicTerm::create(['school_year' => '2026-2027', 'semester' => 'First Semester', 'start_date' => '2026-08-10', 'end_date' => '2026-12-12', 'status' => 'active']);
        $subject = Subject::create(['course_scope' => 'BSIT', 'code' => 'ITEC 120', 'name' => 'MOBILE APPLICATION DEVELOPMENT', 'year_level' => 3, 'semester' => 'First Semester', 'lecture_only' => false, 'is_active' => true]);
        FacultySchedule::create(['employee_id' => $employee->id, 'academic_term_id' => $term->id, 'day_of_week' => 1, 'schedule_type' => 'class', 'subject' => $subject->code, 'section' => 'BSIT 3A', 'class_mode' => 'laboratory', 'start_time' => '08:00', 'end_time' => '10:00']);
        $this->actingAs($admin);

        $payload = ['course_scope' => 'BSCS', 'code' => 'ITEC 121', 'name' => $subject->name, 'year_level' => 4, 'semester' => 'Second Semester', 'lecture_only' => true, 'is_active' => true];
        $this->put('/settings/subjects/'.$subject->id, $payload)->assertSessionHasErrors('code');

        $payload['code'] = $subject->code;
        $this->put('/settings/subjects/'.$subject->id, $payload)->assertSessionHasErrors('year_level');

        $payload['year_level'] = 3;
        $payload['semester'] = 'First Semester';
        $this->put('/settings/subjects/'.$subject->id, $payload)->assertSessionHasErrors('course_scope');
        $payload['course_scope'] = 'BOTH';
        $this->put('/settings/subjects/'.$subject->id, $payload)->assertSessionHasErrors('lecture_only');
        $this->patch('/settings/subjects/'.$subject->id.'/status')->assertSessionHasErrors('subject');
        $this->assertTrue($subject->fresh()->is_active);

        $term->update(['status' => 'closed']);
        $this->patch('/settings/subjects/'.$subject->id.'/status')->assertSessionHasNoErrors();
        $this->assertFalse($subject->fresh()->is_active);
    }

    public function test_subject_code_and_duplicate_placement_names_are_validated(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $payload = ['course_scope' => 'BSIT', 'code' => 'SUBJECT', 'name' => 'INTRODUCTION TO TESTING', 'year_level' => 1, 'semester' => 'First Semester', 'lecture_only' => false, 'is_active' => true];
        $this->post('/settings/subjects', $payload)->assertSessionHasErrors('code');

        $payload['code'] = 'ITEC 121';
        $this->post('/settings/subjects', $payload)->assertSessionHasNoErrors();
        $payload['code'] = 'ITEC 122';
        $this->post('/settings/subjects', $payload)->assertSessionHasErrors('name');

        $payload['semester'] = 'Second Semester';
        $this->post('/settings/subjects', $payload)->assertSessionHasNoErrors();
    }

    public function test_course_scopes_control_subject_visibility_for_bsit_and_bscs(): void
    {
        Subject::create(['course_scope' => 'BSIT', 'code' => 'ITEC 150', 'name' => 'IT COURSE', 'year_level' => 2, 'semester' => 'First Semester', 'lecture_only' => false, 'is_active' => true]);
        Subject::create(['course_scope' => 'BSCS', 'code' => 'COSC 150', 'name' => 'CS COURSE', 'year_level' => 2, 'semester' => 'First Semester', 'lecture_only' => false, 'is_active' => true]);
        Subject::create(['course_scope' => 'BOTH', 'code' => 'GNED 150', 'name' => 'SHARED COURSE', 'year_level' => 2, 'semester' => 'First Semester', 'lecture_only' => false, 'is_active' => true]);

        $this->assertContains('ITEC 150', ScheduleOptions::subjectsFor('BSIT 2A', 'First Semester'));
        $this->assertNotContains('ITEC 150', ScheduleOptions::subjectsFor('BSCS 2A', 'First Semester'));
        $this->assertContains('COSC 150', ScheduleOptions::subjectsFor('BSCS 2F', 'First Semester'));
        $this->assertNotContains('COSC 150', ScheduleOptions::subjectsFor('BSIT 2F', 'First Semester'));
        $this->assertContains('GNED 150', ScheduleOptions::subjectsFor('BSIT 2A', 'First Semester'));
        $this->assertContains('GNED 150', ScheduleOptions::subjectsFor('BSCS 2F', 'First Semester'));
    }
}
