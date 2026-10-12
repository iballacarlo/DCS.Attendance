<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\FacultyRank;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EmployeeCredentialTest extends TestCase
{
    use RefreshDatabase;

    public function test_hardware_can_observe_cancelled_and_expired_enrollments(): void
    {
        config(['services.hardware.api_key' => 'device-secret']);
        $enrollment = \App\Models\HardwareEnrollment::create([
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

    public function test_faculty_email_and_phone_use_institutional_formats(): void
    {
        $employee = $this->employee();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->put(route('employees.update', $employee), $this->employeePayload($employee, [
                'email' => 'not-an-email@gmail.com',
                'contact_no' => '09171234567',
            ]))
            ->assertSessionHasErrors(['email', 'contact_no']);

        $this->actingAs($admin)
            ->put(route('employees.update', $employee), $this->employeePayload($employee, [
                'email' => 'test.faculty@cvsu.edu.ph',
                'contact_no' => '+639171234567',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'email' => 'test.faculty@cvsu.edu.ph',
            'contact_no' => '+639171234567',
        ]);
    }

    public function test_admin_can_save_structured_employment_history(): void
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

        $this->assertDatabaseHas('employment_histories', [
            'employee_id' => $employee->id,
            'employer' => 'Previous State University',
            'position' => 'Instructor',
            'started_on' => '2018-06-01 00:00:00',
            'ended_on' => '2020-05-01 00:00:00',
        ]);
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
            'highest_educational_attainment' => "Master's Degree",
            'service_start_date' => '2020-06-01',
            'faculty_rank_id' => $rank->id,
            'rate_amount' => $rank->rate_amount,
            'status' => 'active',
            'employment_history' => [],
        ]);

        $employee = Employee::where('email', 'new.faculty@cvsu.edu.ph')->firstOrFail();
        $this->assertSame("Master's Degree", $employee->highest_educational_attainment);
        $this->assertSame('2020-06-01', Carbon::parse($employee->service_start_date)->format('Y-m-d'));
        $response->assertRedirect(route('employees.edit', $employee).'#attendance-identifiers')
            ->assertSessionHas('temporary_credentials');

        $user = User::where('email', 'new.faculty@cvsu.edu.ph')->firstOrFail();
        $credentials = session('temporary_credentials');
        $this->assertSame('faculty', $user->role);
        $this->assertTrue($user->must_change_password);
        $this->assertNotNull($user->employee_id);
        $this->assertSame(14, strlen($credentials['password']));
        $this->assertTrue(Hash::check($credentials['password'], $user->password));
        Http::assertSent(fn ($request) => $request['recipient'] === $user->email
            && str_contains($request['subject'], 'account is ready')
            && str_contains($request['message'], $credentials['password'])
            && str_contains($request['html'], e($credentials['password'])));
    }

    public function test_creating_faculty_requires_attainment_and_service_start_before_registration(): void
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
            ->assertSessionHasErrors(['highest_educational_attainment', 'service_start_date']);

        $this->assertDatabaseMissing('employees', ['email' => 'incomplete.faculty@cvsu.edu.ph']);
        $this->assertDatabaseMissing('users', ['email' => 'incomplete.faculty@cvsu.edu.ph']);
    }

    public function test_selected_rank_automatically_controls_the_employee_hourly_rate(): void
    {
        $employee = $this->employee();
        $rank = FacultyRank::where('is_active', true)
            ->where('id', '!=', $employee->faculty_rank_id)
            ->firstOrFail();

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->put(route('employees.update', $employee), $this->employeePayload($employee, [
                'faculty_rank_id' => $rank->id,
                'rate_amount' => 999999.99,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame((float) $rank->rate_amount, (float) $employee->fresh()->rate_amount);
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
