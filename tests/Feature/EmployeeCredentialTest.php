<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\FacultyRank;
use App\Models\HardwareEnrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EmployeeCredentialTest extends TestCase
{
    use RefreshDatabase;

    public function test_hardware_can_observe_cancelled_and_expired_enrollments(): void
    {
        config(['services.hardware.api_key' => 'device-secret']);
        $enrollment = HardwareEnrollment::create([
            'employee_id' => $this->employee()->id,
            'method' => 'rfid',
            'status' => 'cancelled',
            'expires_at' => now()->addMinute(),
        ]);
        $url = "/api/hardware/enrollments/{$enrollment->id}/status";

        $this->getJson($url)->assertUnauthorized();
        $this->withHeader('X-Hardware-Key', 'device-secret')->getJson($url)
            ->assertOk()->assertJsonPath('status', 'cancelled');

        $enrollment->update(['status' => 'processing', 'expires_at' => now()->subMinute()]);
        $this->withHeader('X-Hardware-Key', 'device-secret')->getJson($url)
            ->assertOk()->assertJsonPath('status', 'expired');
        $this->assertDatabaseHas('hardware_enrollments', ['id' => $enrollment->id, 'status' => 'expired']);
    }

    public function test_editing_faculty_details_does_not_change_device_registration_time(): void
    {
        $employee = $this->employee();
        $registeredAt = Carbon::now()->subDay()->startOfSecond();
        $employee->rfidCards()->create([
            'rfid_uid' => 'CARD-1001',
            'status' => 'active',
            'registered_at' => $registeredAt,
        ]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->put(route('employees.update', $employee), $this->employeePayload($employee, [
                'rfid_uid' => 'CARD-1001',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            $registeredAt->toDateTimeString(),
            $employee->rfidCards()->firstOrFail()->registered_at
        );
    }

    public function test_admin_can_reregister_an_existing_rfid_card(): void
    {
        $employee = $this->employee();
        $registeredAt = Carbon::now()->subDay()->startOfSecond();
        $employee->rfidCards()->create([
            'rfid_uid' => 'CARD-1001',
            'status' => 'active',
            'registered_at' => $registeredAt,
        ]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->put(route('employees.update', $employee), $this->employeePayload($employee, [
                'rfid_uid' => 'CARD-1001',
                'rfid_reregister' => true,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertTrue(
            Carbon::parse($employee->rfidCards()->firstOrFail()->registered_at)->greaterThan($registeredAt)
        );
    }

    public function test_esp32_can_complete_an_rfid_enrollment_request(): void
    {
        config(['services.hardware.api_key' => 'device-secret']);
        $employee = $this->employee();
        $admin = User::factory()->create(['role' => 'admin']);

        $start = $this->actingAs($admin)
            ->postJson(route('employees.credentials.enrollments.store', $employee), [
                'method' => 'rfid',
            ])
            ->assertCreated()
            ->json();

        $this->withHeader('X-Hardware-Key', 'device-secret')
            ->getJson('/api/hardware/enrollment')
            ->assertOk()
            ->assertJsonPath('id', (string) $start['id'])
            ->assertJsonPath('method', 'rfid');

        $this->withHeader('X-Hardware-Key', 'device-secret')
            ->postJson("/api/hardware/enrollments/{$start['id']}/result", [
                'status' => 'completed',
                'identifier' => 'A1B2C3D4',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $this->assertDatabaseHas('rfid_cards', [
            'employee_id' => $employee->id,
            'rfid_uid' => 'A1B2C3D4',
            'status' => 'active',
        ]);
    }

    public function test_hardware_cannot_assign_a_duplicate_credential(): void
    {
        config(['services.hardware.api_key' => 'device-secret']);
        $firstEmployee = $this->employee();
        $firstEmployee->rfidCards()->create(['rfid_uid' => 'TAKEN-CARD', 'status' => 'active']);
        $secondEmployee = $this->employee('COS-9002');
        $admin = User::factory()->create(['role' => 'admin']);

        $enrollmentId = $this->actingAs($admin)
            ->postJson(route('employees.credentials.enrollments.store', $secondEmployee), ['method' => 'rfid'])
            ->json('id');

        $this->withHeader('X-Hardware-Key', 'device-secret')
            ->postJson("/api/hardware/enrollments/{$enrollmentId}/result", [
                'status' => 'completed',
                'identifier' => 'TAKEN-CARD',
            ])
            ->assertUnprocessable();

        $this->assertDatabaseMissing('rfid_cards', ['employee_id' => $secondEmployee->id]);
        $this->assertDatabaseHas('hardware_enrollments', ['id' => $enrollmentId, 'status' => 'failed']);
    }

    public function test_replacement_sensor_can_reuse_the_faculty_members_existing_fingerprint_slot(): void
    {
        config(['services.hardware.api_key' => 'device-secret']);
        $employee = $this->employee();
        $employee->fingerprintTemplates()->create([
            'fingerprint_code' => 'FP-1',
            'finger_label' => 'Right thumb',
            'status' => 'active',
        ]);
        $otherEmployee = $this->employee('COS-9002');
        $otherEmployee->fingerprintTemplates()->create([
            'fingerprint_code' => 'FP-2',
            'finger_label' => 'Right index',
            'status' => 'inactive',
        ]);

        $this->getJson('/api/hardware/fingerprint-slots')->assertUnauthorized();
        $this->withHeader('X-Hardware-Key', 'device-secret')
            ->getJson('/api/hardware/fingerprint-slots')
            ->assertOk()
            ->assertJsonPath('reserved_slots', '1,2');

        $enrollmentId = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('employees.credentials.enrollments.store', $employee), [
                'method' => 'fingerprint',
                'finger_label' => 'Right thumb',
            ])
            ->assertCreated()
            ->json('id');

        $this->withHeader('X-Hardware-Key', 'device-secret')
            ->getJson('/api/hardware/enrollment')
            ->assertOk()
            ->assertJsonPath('current_identifier', 'FP-1');

        $this->withHeader('X-Hardware-Key', 'device-secret')
            ->postJson("/api/hardware/enrollments/{$enrollmentId}/result", [
                'status' => 'completed',
                'identifier' => 'FP-1',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $this->assertDatabaseHas('fingerprint_templates', [
            'employee_id' => $employee->id,
            'fingerprint_code' => 'FP-1',
            'status' => 'active',
        ]);
    }

    public function test_replacement_sensor_cannot_assign_another_faculty_members_fingerprint_slot(): void
    {
        config(['services.hardware.api_key' => 'device-secret']);
        $owner = $this->employee();
        $owner->fingerprintTemplates()->create(['fingerprint_code' => 'FP-1', 'status' => 'active']);
        $otherEmployee = $this->employee('COS-9002');

        $enrollmentId = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('employees.credentials.enrollments.store', $otherEmployee), [
                'method' => 'fingerprint',
                'finger_label' => 'Right thumb',
            ])
            ->json('id');

        $this->withHeader('X-Hardware-Key', 'device-secret')
            ->postJson("/api/hardware/enrollments/{$enrollmentId}/result", [
                'status' => 'completed',
                'identifier' => 'FP-1',
            ])
            ->assertUnprocessable();

        $this->assertDatabaseMissing('fingerprint_templates', ['employee_id' => $otherEmployee->id]);
        $this->assertDatabaseHas('fingerprint_templates', ['employee_id' => $owner->id, 'fingerprint_code' => 'FP-1']);
    }

    public function test_admin_cannot_change_faculty_owned_email_and_phone(): void
    {
        $employee = $this->employee();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->put(route('employees.update', $employee), $this->employeePayload($employee, [
                'email' => 'not-an-email@gmail.com',
                'contact_no' => '09171234567',
            ]))
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->put(route('employees.update', $employee), $this->employeePayload($employee, [
                'email' => 'another.faculty@cvsu.edu.ph',
                'contact_no' => '+639171234567',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'email' => $employee->email,
            'contact_no' => $employee->contact_no,
        ]);
    }

    public function test_admin_cannot_replace_faculty_owned_employment_history(): void
    {
        $employee = $this->employee();

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->put(route('employees.update', $employee), $this->employeePayload($employee, [
                'employment_history' => [[
                    'employer' => 'Previous State University',
                    'position' => 'Instructor',
                    'started_on' => '2018-06-01',
                    'ended_on' => '2020-05-01',
                ]],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('employment_histories', ['employee_id' => $employee->id]);
    }

    public function test_admin_creating_faculty_also_creates_a_temporary_login(): void
    {
        config([
            'services.google_mail.webhook_url' => 'https://script.google.com/test/exec',
            'services.google_mail.webhook_secret' => 'test-secret',
        ]);
        Http::fake([
            'https://script.google.com/*' => Http::response(['ok' => true]),
        ]);
        $rank = FacultyRank::where('is_active', true)->firstOrFail();
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->post(route('employees.store'), [
            'first_name' => 'New',
            'middle_name' => 'Faculty',
            'last_name' => 'Member',
            'email' => 'new.faculty@cvsu.edu.ph',
            'faculty_rank_id' => $rank->id,
            'rate_amount' => $rank->rate_amount,
            'status' => 'active',
            'employment_history' => [],
        ]);

        $employee = Employee::where('email', 'new.faculty@cvsu.edu.ph')->firstOrFail();
        $response->assertRedirect(route('employees.edit', $employee).'#attendance-identifiers')
            ->assertSessionMissing('temporary_credentials');

        $user = User::where('email', 'new.faculty@cvsu.edu.ph')->firstOrFail();
        $this->assertSame('faculty', $user->role);
        $this->assertTrue($user->must_change_password);
        $this->assertNotNull($user->employee_id);
        Http::assertSent(fn ($request) => $request['recipient'] === $user->email
            && str_contains($request['subject'], 'temporary password')
            && str_contains($request['message'], 'Temporary password:')
            && ! str_contains($request['message'], 'email/verify')
            && ! str_contains($request['html'], 'Verify email'));
    }

    public function test_creating_faculty_does_not_require_faculty_owned_profile_fields(): void
    {
        $rank = FacultyRank::where('is_active', true)->firstOrFail();
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post(route('employees.store'), [
                'first_name' => 'Incomplete',
                'last_name' => 'Faculty',
                'email' => 'incomplete.faculty@cvsu.edu.ph',
                'faculty_rank_id' => $rank->id,
                'status' => 'active',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('employees', ['email' => 'incomplete.faculty@cvsu.edu.ph']);
        $this->assertDatabaseHas('users', ['email' => 'incomplete.faculty@cvsu.edu.ph']);
    }

    public function test_rank_changes_and_submitted_salary_do_not_change_retained_historical_rates(): void
    {
        $employee = $this->employee();
        $oldRate = (float) $employee->rate_amount;
        $rank = FacultyRank::where('is_active', true)
            ->where('id', '!=', $employee->faculty_rank_id)
            ->firstOrFail();

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->put(route('employees.update', $employee), $this->employeePayload($employee, [
                'faculty_rank_id' => $rank->id,
                'rate_amount' => 999999.99,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame($oldRate, (float) $employee->fresh()->rate_amount);
    }

    public function test_fingerprint_hardware_registration_requires_a_finger_label(): void
    {
        $employee = $this->employee();

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('employees.credentials.enrollments.store', $employee), [
                'method' => 'fingerprint',
                'finger_label' => '',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('finger_label');
    }

    public function test_completed_fingerprint_result_can_be_retried_without_duplicate_registration(): void
    {
        config(['services.hardware.api_key' => 'device-secret']);
        $employee = $this->employee();
        $id = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('employees.credentials.enrollments.store', $employee), [
                'method' => 'fingerprint', 'finger_label' => 'Right thumb',
            ])->assertCreated()->json('id');
        $url = "/api/hardware/enrollments/{$id}/result";
        $payload = ['status' => 'completed', 'identifier' => 'FP-1'];
        $this->withHeader('X-Hardware-Key', 'device-secret')->postJson($url, $payload)->assertOk();
        $registeredAt = $employee->fingerprintTemplates()->firstOrFail()->registered_at;
        $this->travel(6)->minutes();
        $this->withHeader('X-Hardware-Key', 'device-secret')->postJson($url, $payload)
            ->assertOk()->assertJsonPath('identifier', 'FP-1');
        $this->assertSame($registeredAt, $employee->fingerprintTemplates()->firstOrFail()->registered_at);
        $this->assertDatabaseCount('fingerprint_templates', 1);
        $this->withHeader('X-Hardware-Key', 'device-secret')
            ->getJson("/api/hardware/enrollments/{$id}/status")
            ->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('identifier', 'FP-1');
        $this->withHeader('X-Hardware-Key', 'device-secret')
            ->postJson($url, ['status' => 'completed', 'identifier' => 'FP-2'])->assertConflict();
    }

    public function test_sensor_replacement_reset_removes_only_fingerprint_credentials_and_active_requests(): void
    {
        $employee = $this->employee();
        $employee->fingerprintTemplates()->create([
            'fingerprint_code' => 'FP-1', 'finger_label' => 'Right thumb', 'status' => 'active',
        ]);
        $other = $this->employee('COS-9002');
        $other->fingerprintTemplates()->create([
            'fingerprint_code' => 'FP-2', 'finger_label' => 'Right thumb', 'status' => 'inactive',
        ]);
        $employee->rfidCards()->create(['rfid_uid' => 'KEEP-CARD', 'status' => 'active']);
        foreach (['pending', 'processing', 'completed'] as $status) {
            HardwareEnrollment::create([
                'employee_id' => $employee->id, 'method' => 'fingerprint',
                'status' => $status, 'current_identifier' => 'FP-1', 'expires_at' => now()->addMinutes(5),
            ]);
        }
        $rfid = HardwareEnrollment::create([
            'employee_id' => $employee->id, 'method' => 'rfid',
            'status' => 'pending', 'expires_at' => now()->addMinutes(5),
        ]);
        $migration = require database_path('migrations/2026_10_12_000001_clear_fingerprint_registrations_for_sensor_replacement.php');
        $migration->up();
        $this->assertDatabaseCount('fingerprint_templates', 0);
        $this->assertDatabaseHas('rfid_cards', ['rfid_uid' => 'KEEP-CARD']);
        $this->assertNotNull($employee->fresh());
        $this->assertNotNull($other->fresh());
        $this->assertSame('pending', $rfid->fresh()->status);
        $this->assertSame(2, HardwareEnrollment::where('method', 'fingerprint')->where('status', 'cancelled')->count());
        $this->assertSame(1, HardwareEnrollment::where('method', 'fingerprint')->where('status', 'completed')->count());
    }

    private function employee(string $employeeNumber = 'COS-9001'): Employee
    {
        $rank = FacultyRank::where('is_active', true)->firstOrFail();

        return Employee::create([
            'employee_no' => $employeeNumber,
            'first_name' => 'Test',
            'last_name' => 'Faculty',
            'faculty_rank_id' => $rank->id,
            'position' => 'COS Faculty Member',
            'department' => 'Department of Computer Studies',
            'employment_type' => 'Contract of Service',
            'rate_type' => 'hourly',
            'rate_amount' => $rank->rate_amount,
            'status' => 'active',
        ]);
    }

    private function employeePayload(Employee $employee, array $overrides = []): array
    {
        return array_merge([
            'employee_no' => $employee->employee_no,
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
            'email' => $employee->email ?: 'test.faculty@cvsu.edu.ph',
            'highest_educational_attainment' => "Master's Degree",
            'service_start_date' => '2020-06-01',
            'faculty_rank_id' => $employee->faculty_rank_id,
            'rate_amount' => $employee->rate_amount,
            'status' => $employee->status,
            'rfid_uid' => '',
            'fingerprint_code' => '',
            'finger_label' => '',
        ], $overrides);
    }
}
