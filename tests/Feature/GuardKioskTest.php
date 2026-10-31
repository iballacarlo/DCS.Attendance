<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use App\Services\EntranceAttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GuardKioskTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-28 10:00', 'Asia/Manila'));
    }

    public function test_future_dates_are_rejected_but_later_times_today_are_allowed(): void
    {
        $employee = Employee::create(['employee_no' => 'DATE-LIMIT', 'first_name' => 'Test', 'last_name' => 'Faculty', 'rate_amount' => 0, 'status' => 'active']);
        $employee->rfidCards()->create(['rfid_uid' => 'DATE-CARD', 'status' => 'active']);
        $this->actingAs(User::factory()->create(['role' => 'guard']));
        $future = ['identifier' => 'DATE-CARD', 'occurred_at' => '2026-10-29T00:00'];
        $this->postJson('/entrance-kiosk/preview', $future)->assertUnprocessable()->assertJsonValidationErrors('occurred_at');
        foreach (['IN', 'OUT'] as $action) {
            $this->post('/entrance-kiosk', [...$future, 'expected_action' => $action])->assertSessionHasErrors('occurred_at');
        }
        $this->assertDatabaseCount('entrance_attendances', 0);
        $today = ['identifier' => 'DATE-CARD', 'occurred_at' => '2026-10-28T20:00'];
        $this->postJson('/entrance-kiosk/preview', $today)->assertOk()->assertJsonPath('next_action', 'IN');
        $this->post('/entrance-kiosk', [...$today, 'expected_action' => 'IN'])->assertSessionHasNoErrors();
        $this->post('/entrance-kiosk', [...$today, 'occurred_at' => '2026-10-28T23:59', 'expected_action' => 'OUT'])->assertSessionHasNoErrors();
        $this->post('/entrance-kiosk', [...$future, 'expected_action' => 'OUT'])->assertSessionHasErrors('occurred_at');
        $this->assertDatabaseHas('entrance_attendances', ['employee_id' => $employee->id, 'attendance_date' => '2026-10-28', 'time_in' => '20:00:00', 'time_out' => '23:59:00']);
        $this->assertDatabaseCount('entrance_attendances', 1);
        $this->assertDatabaseCount('attendance_logs', 0);
    }

    public function test_date_limit_uses_manila_day_and_service_calls_cannot_bypass_it(): void
    {
        $this->travelTo(Carbon::parse('2026-10-28 16:30', 'UTC')); // October 29, 00:30 in Manila.
        $employee = Employee::create(['employee_no' => 'MANILA-DATE', 'first_name' => 'Test', 'last_name' => 'Faculty', 'rate_amount' => 0, 'status' => 'active']);
        $employee->rfidCards()->create(['rfid_uid' => 'MANILA-CARD', 'status' => 'active']);
        $guard = User::factory()->create(['role' => 'guard']);
        $this->actingAs($guard)->post('/entrance-kiosk', ['identifier' => 'MANILA-CARD', 'occurred_at' => '2026-10-29T23:59', 'expected_action' => 'IN'])->assertSessionHasNoErrors();
        $this->postJson('/entrance-kiosk/preview', ['identifier' => 'MANILA-CARD', 'occurred_at' => '2026-10-30T00:00'])->assertUnprocessable()->assertJsonValidationErrors('occurred_at');
        try {
            app(EntranceAttendanceService::class)->record('MANILA-CARD', 'IN', Carbon::parse('2026-10-29 16:00', 'UTC'), $guard->id);
            $this->fail('A UTC timestamp falling on a future Manila date must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('occurred_at', $exception->errors());
        }
        $this->assertDatabaseCount('entrance_attendances', 1);
    }

    public function test_guard_login_goes_to_kiosk_even_if_an_admin_url_was_requested(): void
    {
        $guard = User::factory()->create(['role' => 'guard', 'password' => 'guard-password']);
        $this->withSession(['url.intended' => '/settings/accounts'])->post('/login', ['email' => $guard->email, 'password' => 'guard-password'])->assertRedirect(route('entrance.kiosk'))->assertSessionMissing('url.intended');
        $this->assertAuthenticatedAs($guard);
        $this->get('/')->assertRedirect(route('entrance.kiosk'));
        $this->get('/entrance-kiosk')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Entrance/Kiosk')->where('auth.user.role', 'guard')->has('facultyChoices', 0)->missing('logs')->missing('employees'));
    }

    public function test_guard_cannot_access_administration_reports_or_faculty_records(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'guard']));
        foreach (['/entrance', '/attendance', '/attendance/dtr', '/employees', '/class-reports', '/settings/accounts', '/settings/backup', '/profile', '/school-calendar', '/leave'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post('/settings/accounts', ['role' => 'admin'])->assertForbidden();
        $this->put('/profile', ['role' => 'admin'])->assertForbidden();
    }

    public function test_guard_can_record_a_school_pair_and_is_saved_as_the_actor(): void
    {
        $employee = Employee::create(['employee_no' => 'GUARD-TEST', 'first_name' => 'Test', 'last_name' => 'Faculty', 'rate_amount' => 0, 'status' => 'active']);
        $employee->rfidCards()->create(['rfid_uid' => 'GUARD-RFID', 'status' => 'active']);
        $guard = User::factory()->create(['role' => 'guard']);
        $this->actingAs($guard)->post('/entrance-kiosk', ['identifier' => 'GUARD-RFID', 'expected_action' => 'IN', 'occurred_at' => '2026-10-05T08:00'])->assertRedirect('/entrance-kiosk')->assertSessionHasNoErrors();
        $this->post('/entrance-kiosk', ['identifier' => 'GUARD-RFID', 'expected_action' => 'OUT', 'occurred_at' => '2026-10-05T17:00'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('entrance_attendances', ['employee_id' => $employee->id, 'recorded_by' => $guard->id, 'out_recorded_by' => $guard->id, 'time_in' => '08:00:00', 'time_out' => '17:00:00']);
        $this->assertDatabaseCount('attendance_logs', 0);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_admin_and_faculty_cannot_record_at_the_guard_station(): void
    {
        $this->get('/entrance-kiosk')->assertRedirect('/login');
        foreach (['admin', 'faculty'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->get('/entrance-kiosk')->assertForbidden();
            $this->post('/entrance-kiosk', ['identifier' => 'ANY', 'expected_action' => 'IN'])->assertForbidden();
            $this->get('/entrance')->assertInertia(fn (Assert $p) => $p->where('canRecord', false));
        }
        $this->assertDatabaseCount('entrance_attendances', 0);
    }

    public function test_admin_can_create_a_guard_without_a_faculty_profile(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post('/settings/accounts', ['name' => 'Entrance Guard', 'email' => 'guard@example.test', 'role' => 'guard'])->assertSessionHasNoErrors()->assertRedirect('/settings/accounts');
        $this->assertDatabaseHas('users', ['email' => 'guard@example.test', 'role' => 'guard', 'employee_id' => null]);
        $this->get('/settings/accounts?role=guard')->assertOk()->assertInertia(fn (Assert $p) => $p->has('users.data', 1)->where('users.data.0.role', 'guard'));
    }

    public function test_new_account_does_not_receive_the_signed_in_users_credentials(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'admin@example.test']);
        $this->actingAs($admin)->get('/settings/accounts/create')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Users/Form')->where('user', [])->missing('user.email')->missing('user.password')
            ->where('auth.user.email', $admin->email));
        $this->get('/settings/accounts/'.$admin->id.'/edit')->assertInertia(fn (Assert $p) => $p
            ->where('user.email', $admin->email)->missing('user.password'));
    }

    public function test_kiosk_automatically_advances_per_card_and_date_and_rejects_stale_submissions(): void
    {
        $employee = Employee::create(['employee_no' => 'AUTO-IN-OUT', 'first_name' => 'Auto', 'last_name' => 'Faculty', 'rate_amount' => 0, 'status' => 'active']);
        $employee->rfidCards()->create(['rfid_uid' => 'AUTO-CARD', 'status' => 'active']);
        $this->actingAs(User::factory()->create(['role' => 'guard']));
        $data = ['identifier' => 'AUTO-CARD', 'occurred_at' => '2026-10-05T08:00'];
        $this->postJson('/entrance-kiosk/preview', $data)->assertOk()->assertJsonPath('next_action', 'IN');
        $this->assertDatabaseCount('entrance_attendances', 0);
        $this->post('/entrance-kiosk', [...$data, 'expected_action' => 'IN', 'action' => 'OUT'])->assertSessionHasNoErrors();
        $this->postJson('/entrance-kiosk/preview', $data)->assertOk()->assertJsonPath('next_action', 'OUT');
        $this->post('/entrance-kiosk', [...$data, 'occurred_at' => '2026-10-05T09:00', 'expected_action' => 'IN'])->assertSessionHasErrors('action');
        $this->assertDatabaseHas('entrance_attendances', ['employee_id' => $employee->id, 'time_out' => null]);
        $this->post('/entrance-kiosk', [...$data, 'occurred_at' => '2026-10-05T07:00', 'expected_action' => 'OUT'])->assertSessionHasErrors('action');
        $this->post('/entrance-kiosk', [...$data, 'occurred_at' => '2026-10-05T17:00', 'expected_action' => 'OUT'])->assertSessionHasNoErrors();
        $this->postJson('/entrance-kiosk/preview', $data)->assertOk()->assertJsonPath('next_action', null);
        $this->post('/entrance-kiosk', [...$data, 'occurred_at' => '2026-10-05T18:00', 'expected_action' => 'OUT'])->assertSessionHasErrors('action');
        $this->postJson('/entrance-kiosk/preview', [...$data, 'occurred_at' => '2026-10-06T08:00'])->assertOk()->assertJsonPath('next_action', 'IN');
        $this->assertDatabaseCount('entrance_attendances', 1);
        $this->assertDatabaseCount('attendance_logs', 0);
    }

    public function test_automatic_direction_is_not_shared_between_faculty_and_preview_is_guard_only(): void
    {
        $guard = User::factory()->create(['role' => 'guard']);
        foreach (['ONE', 'TWO'] as $number) {
            $employee = Employee::create(['employee_no' => 'AUTO-'.$number, 'first_name' => $number, 'last_name' => 'Faculty', 'rate_amount' => 0, 'status' => 'active']);
            $employee->rfidCards()->create(['rfid_uid' => 'CARD-'.$number, 'status' => 'active']);
        }
        $this->actingAs($guard)->post('/entrance-kiosk', ['identifier' => 'CARD-ONE', 'occurred_at' => '2026-10-05T08:00', 'expected_action' => 'IN'])->assertSessionHasNoErrors();
        foreach (['ONE' => 'OUT', 'TWO' => 'IN'] as $number => $direction) {
            $this->postJson('/entrance-kiosk/preview', ['identifier' => 'CARD-'.$number, 'occurred_at' => '2026-10-05T09:00'])->assertJsonPath('next_action', $direction);
        }
        $this->postJson('/entrance-kiosk/preview', ['identifier' => 'UNKNOWN'])->assertUnprocessable()->assertJsonValidationErrors('identifier');
        foreach (['admin', 'faculty'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->postJson('/entrance-kiosk/preview', ['identifier' => 'CARD-ONE'])->assertForbidden();
        }
    }
}
