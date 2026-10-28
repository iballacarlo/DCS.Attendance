<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\HardwareEnrollment;
use App\Models\User;
use App\Services\EntranceAttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class NfcRfidRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function faculty(string $number = 'NFC-001'): Employee
    {
        return Employee::create(['employee_no' => $number, 'first_name' => 'Test', 'last_name' => 'Faculty', 'rate_amount' => 0, 'status' => 'active']);
    }

    private function endpoint(Employee $employee): string
    {
        return "/employees/{$employee->id}/credentials/rfid/nfc";
    }

    public function test_admin_can_register_a_phone_serial_without_hardware_or_attendance_records(): void
    {
        $employee = $this->faculty();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->postJson($this->endpoint($employee), [
            'identifier' => '04:a1:02:ff', 'current_identifier' => null, 'confirmation' => 'REGISTER',
        ])->assertOk()->assertJsonPath('identifier', '04A102FF')->assertJsonPath('status', 'completed');
        $this->assertDatabaseHas('rfid_cards', ['employee_id' => $employee->id, 'rfid_uid' => '04A102FF', 'status' => 'active']);
        $this->assertNotNull($employee->rfidCards()->firstOrFail()->registered_at);
        $this->assertDatabaseHas('audit_trails', ['action' => 'credential.registered', 'record_id' => $employee->id]);
        $this->assertDatabaseCount('hardware_enrollments', 0);
        $this->assertDatabaseCount('attendance_logs', 0);
        $this->assertDatabaseCount('entrance_attendances', 0);
        $this->assertSame('IN', app(EntranceAttendanceService::class)->preview('04A102FF', Carbon::now('Asia/Manila'))['next_action']);
    }

    public function test_replacement_requires_the_current_card_and_preserves_the_old_card_on_stale_requests(): void
    {
        $employee = $this->faculty();
        $card = $employee->rfidCards()->create(['rfid_uid' => 'AABBCCDD', 'status' => 'active']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $payload = ['identifier' => '04112233', 'current_identifier' => null, 'confirmation' => 'REGISTER'];
        $this->postJson($this->endpoint($employee), $payload)->assertConflict();
        $this->assertSame('AABBCCDD', $card->fresh()->rfid_uid);
        $this->postJson($this->endpoint($employee), [...$payload, 'current_identifier' => 'AABBCCDD'])
            ->assertOk()->assertJsonPath('identifier', '04112233');
        $this->assertSame('04112233', $card->fresh()->rfid_uid);
        $this->assertDatabaseCount('rfid_cards', 1);
        $this->assertDatabaseHas('audit_trails', ['action' => 'credential.replaced', 'record_id' => $employee->id]);
        $this->postJson($this->endpoint($employee), [...$payload, 'current_identifier' => 'AABBCCDD'])->assertConflict();
    }

    public function test_duplicate_cards_including_archived_assignments_cannot_replace_another_faculty_card(): void
    {
        $owner = $this->faculty('NFC-OWNER');
        $owner->rfidCards()->create(['rfid_uid' => 'AABBCCDD', 'status' => 'active']);
        $employee = $this->faculty();
        $card = $employee->rfidCards()->create(['rfid_uid' => '04112233', 'status' => 'active']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $payload = ['identifier' => 'aa-bb-cc-dd', 'current_identifier' => '04112233', 'confirmation' => 'REGISTER'];
        $this->postJson($this->endpoint($employee), $payload)->assertUnprocessable()->assertJsonValidationErrors('identifier');
        $owner->forceFill(['archived_at' => now()])->save();
        $this->postJson($this->endpoint($employee), $payload)->assertUnprocessable()->assertJsonValidationErrors('identifier');
        $this->assertSame('04112233', $card->fresh()->rfid_uid);
        $this->assertDatabaseCount('audit_trails', 0);
        $this->postJson($this->endpoint($owner), $payload)->assertNotFound();
    }

    public function test_registration_validates_uid_and_confirmation_without_overwriting_the_card(): void
    {
        $employee = $this->faculty();
        $employee->rfidCards()->create(['rfid_uid' => 'AABBCCDD', 'status' => 'active']);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach (['', 'https://instagram.com/example', 'ABC', ['04A102FF']] as $identifier) {
            $this->postJson($this->endpoint($employee), ['identifier' => $identifier, 'current_identifier' => 'AABBCCDD', 'confirmation' => 'REGISTER'])
                ->assertUnprocessable()->assertJsonValidationErrors('identifier');
        }
        $this->postJson($this->endpoint($employee), ['identifier' => '04112233', 'current_identifier' => 'AABBCCDD'])->assertUnprocessable()->assertJsonValidationErrors('confirmation');
        $this->postJson($this->endpoint($employee), ['identifier' => '04112233', 'confirmation' => 'REGISTER'])->assertUnprocessable()->assertJsonValidationErrors('current_identifier');
        $this->assertDatabaseHas('rfid_cards', ['employee_id' => $employee->id, 'rfid_uid' => 'AABBCCDD']);
    }

    public function test_nfc_does_not_interfere_with_a_pending_hardware_rfid_enrollment(): void
    {
        $employee = $this->faculty();
        $enrollment = HardwareEnrollment::create(['employee_id' => $employee->id, 'method' => 'rfid', 'status' => 'pending', 'expires_at' => now()->addMinutes(5)]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $payload = ['identifier' => '04A102FF', 'current_identifier' => null, 'confirmation' => 'REGISTER'];
        $this->postJson($this->endpoint($employee), $payload)->assertConflict();
        $this->assertDatabaseCount('rfid_cards', 0);
        $enrollment->update(['status' => 'cancelled']);
        $this->postJson($this->endpoint($employee), $payload)->assertOk();
    }

    public function test_only_admin_can_register_cards_through_nfc(): void
    {
        $employee = $this->faculty();
        $payload = ['identifier' => '04A102FF', 'current_identifier' => null, 'confirmation' => 'REGISTER'];
        $this->postJson($this->endpoint($employee), $payload)->assertUnauthorized();
        foreach (['faculty', 'guard'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->postJson($this->endpoint($employee), $payload)->assertForbidden();
        }
        $this->assertDatabaseCount('rfid_cards', 0);
    }
}
