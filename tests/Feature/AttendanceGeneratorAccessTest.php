<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\RfidCard;
use App\Models\User;
use App\Services\AttendanceGenerationService;
use App\Services\GoogleAppsScriptMailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AttendanceGeneratorAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['attendance_generator.enabled' => true]);
        // Access and overwrite tests need stable pairs; distribution is tested separately.
        $this->app->bind(AttendanceGenerationService::class, fn () => new class extends AttendanceGenerationService
        {
            protected function pick(string $seed, string $key, int $min, int $max): int
            {
                return str_ends_with($key, ':scenario') ? 0 : parent::pick($seed, $key, $min, $max);
            }
        });

        $this->travelTo(Carbon::parse('2026-10-05 18:00', 'Asia/Manila'));
    }

    private function account(array $attributes = []): User
    {
        return User::factory()->create(['role' => 'generator', 'employee_id' => null, 'password' => 'private-generator-passphrase', 'must_change_password' => false, ...$attributes]);
    }

    private function faculty(): Employee
    {
        $employee = Employee::create(['employee_no' => 'GEN-ONE', 'first_name' => 'Test', 'last_name' => 'Faculty', 'status' => 'active', 'rate_amount' => 0]);
        $employee->schedules()->create(['day_of_week' => 1, 'schedule_type' => 'class', 'subject' => 'DCIT 24', 'section' => 'BSIT 1A', 'start_time' => '08:00', 'end_time' => '10:00']);
        RfidCard::create(['employee_id' => $employee->id, 'rfid_uid' => 'ABC12345', 'status' => 'active']);

        return $employee;
    }

    public function test_feature_is_opt_in_and_disabling_it_blocks_login_and_existing_sessions(): void
    {
        $user = $this->account();
        config(['attendance_generator.enabled' => false]);
        $this->post('/login', ['email' => $user->email, 'password' => 'private-generator-passphrase'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->actingAs($user)->get('/tools/attendance-generator')->assertRedirect('/login');
        $this->assertGuest();
        $this->actingAs($user)->postJson('/tools/attendance-generator/preview', [])->assertUnauthorized();
        $this->assertGuest();
        $this->assertDatabaseCount('attendance_logs', 0);
        $this->assertDatabaseCount('entrance_attendances', 0);
    }

    public function test_enabled_login_lands_on_the_isolated_page_and_production_is_supported(): void
    {
        $user = $this->account();
        $this->withSession(['url.intended' => '/settings/accounts'])->post('/login', ['email' => $user->email, 'password' => 'private-generator-passphrase'])
            ->assertRedirect(route('generator.index'))->assertSessionMissing('url.intended');
        $this->get('/')->assertRedirect(route('generator.index'));
        $this->get('/tools/attendance-generator')->assertInertia(fn (Assert $p) => $p->component('Generator/Index')
            ->where('auth.user.role', 'generator')->has('facultyChoices', 0)->missing('logs')->where('filters.mode', 'class'));
        $this->app->instance('env', 'production');
        $this->get('/tools/attendance-generator')->assertOk();
    }

    public function test_generator_has_no_access_to_ordinary_application_routes(): void
    {
        $this->actingAs($this->account());
        foreach (['/attendance', '/entrance', '/attendance/dtr', '/class-reports', '/employees', '/settings/accounts', '/settings/backup', '/settings/backup/download', '/profile', '/leave', '/school-calendar', '/entrance-kiosk'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->get('/account/password')->assertOk();
        $this->post('/entrance-kiosk', ['identifier' => 'ABC12345', 'expected_action' => 'IN'])->assertForbidden();
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_other_roles_and_guests_cannot_use_generator_endpoints(): void
    {
        $this->get('/tools/attendance-generator')->assertRedirect('/login');
        foreach (['admin', 'faculty', 'guard'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->get('/tools/attendance-generator')->assertForbidden();
            $this->postJson('/tools/attendance-generator/preview', [])->assertForbidden();
            $this->postJson('/tools/attendance-generator/generate', [])->assertForbidden();
        }
        $this->assertDatabaseCount('attendance_logs', 0);
    }

    public function test_private_accounts_cannot_be_created_found_changed_or_deleted_in_account_management(): void
    {
        $generator = $this->account();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/settings/accounts')->assertInertia(fn (Assert $p) => $p->has('users.data', 1)->where('users.data.0.id', $admin->id));
        $this->get('/settings/accounts?search='.urlencode($generator->email))->assertInertia(fn (Assert $p) => $p->has('users.data', 0));
        $this->get('/settings/accounts/'.$generator->id.'/edit')->assertNotFound();
        $this->put('/settings/accounts/'.$generator->id, [])->assertNotFound();
        $this->delete('/settings/accounts/'.$generator->id)->assertNotFound();
        $this->post('/settings/accounts', ['name' => 'Other', 'email' => 'other-generator@example.test', 'password' => 'private-generator-passphrase', 'role' => 'generator'])->assertSessionHasErrors('role');
        $this->assertDatabaseHas('users', ['id' => $generator->id, 'role' => 'generator']);
    }

    public function test_temporary_password_gate_and_self_password_change_preserve_account_restrictions(): void
    {
        $user = $this->account(['must_change_password' => true]);
        $this->post('/login', ['email' => $user->email, 'password' => 'private-generator-passphrase'])->assertRedirect('/account/password');
        $this->postJson('/tools/attendance-generator/preview', [])->assertStatus(423);
        $this->put('/account/password', ['current_password' => 'private-generator-passphrase', 'password' => 'changed-generator-passphrase', 'password_confirmation' => 'changed-generator-passphrase'])
            ->assertRedirect(route('generator.index'))->assertSessionHasNoErrors();
        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertSame(1, $user->fresh()->security_version);
        $this->get('/tools/attendance-generator')->assertOk();
        $this->get('/settings/accounts')->assertForbidden();
    }

    public function test_cli_provisioning_has_no_default_account_does_not_promote_other_roles_and_revokes_old_sessions(): void
    {
        $this->assertDatabaseCount('users', 0);
        $this->assertSame(0, Artisan::call('attendance:generator-account', ['email' => 'generator@example.test']));
        preg_match('/Temporary password: ([^\r\n]+)/', Artisan::output(), $match);
        $user = User::where('email', 'generator@example.test')->firstOrFail();
        $this->assertSame('generator', $user->role);
        $this->assertTrue($user->must_change_password);
        $this->assertNull($user->employee_id);
        $this->assertSame(24, strlen($match[1]));
        $this->assertTrue(Hash::check($match[1], $user->password));
        $version = $user->security_version;
        $oldHash = $user->password;
        $this->assertSame(1, Artisan::call('attendance:generator-account', ['email' => $user->email]));
        $this->assertSame($oldHash, $user->fresh()->password);
        $this->assertSame(0, Artisan::call('attendance:generator-account', ['email' => $user->email, '--reset' => true]));
        $this->assertSame($version + 1, $user->fresh()->security_version);
        $this->assertNotSame($oldHash, $user->fresh()->password);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->assertSame(1, Artisan::call('attendance:generator-account', ['email' => $admin->email, '--reset' => true]));
        $this->assertSame('admin', $admin->fresh()->role);
        $this->assertDatabaseCount('attendance_logs', 0);
        $this->assertDatabaseCount('entrance_attendances', 0);
    }

    public function test_public_password_recovery_does_not_reset_generator_accounts(): void
    {
        $user = $this->account();
        $oldHash = $user->password;
        $this->mock(GoogleAppsScriptMailer::class)->shouldNotReceive('sendPasswordReset');
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $token = Password::broker()->createToken($user);
        $this->post('/reset-password', ['email' => $user->email, 'token' => $token, 'password' => 'recovered-generator-passphrase', 'password_confirmation' => 'recovered-generator-passphrase'])->assertSessionHasErrors('email');
        $this->assertSame($oldHash, $user->fresh()->password);
    }

    public function test_preview_is_read_only_bound_to_server_filters_and_consumed_after_generation(): void
    {
        $employee = $this->faculty();
        $this->actingAs($this->account());
        $this->postJson('/tools/attendance-generator/generate', ['preview_token' => 'missing'])->assertUnprocessable();
        $preview = $this->postJson('/tools/attendance-generator/preview', ['mode' => 'class', 'cutoff_start' => '2026-10-01', 'employee_id' => $employee->id])->assertOk()->assertJsonPath('preview.eligible', 1);
        $this->assertDatabaseCount('attendance_logs', 0);
        $this->postJson('/tools/attendance-generator/generate', ['preview_token' => $preview['token'], 'mode' => 'school'])->assertOk()->assertJsonPath('result.created', 1)->assertSessionMissing('attendance_generator_preview');
        $this->assertDatabaseCount('attendance_logs', 1);
        $this->assertDatabaseCount('entrance_attendances', 0);
        $this->postJson('/tools/attendance-generator/generate', ['preview_token' => $preview['token']])->assertUnprocessable();
        $again = $this->postJson('/tools/attendance-generator/preview', ['mode' => 'class', 'cutoff_start' => '2026-10-01', 'employee_id' => $employee->id])->assertOk()->assertJsonPath('preview.eligible', 0);
        $this->postJson('/tools/attendance-generator/generate', ['preview_token' => $again['token']])->assertOk()->assertJsonPath('result.created', 0);
        $this->assertDatabaseCount('attendance_logs', 1);
    }

    public function test_expired_replaced_and_invalid_previews_cannot_generate_records(): void
    {
        $employee = $this->faculty();
        $this->actingAs($this->account());
        $filters = ['mode' => 'school', 'cutoff_start' => '2026-10-01', 'employee_id' => $employee->id];
        $first = $this->postJson('/tools/attendance-generator/preview', $filters)->assertOk();
        $second = $this->postJson('/tools/attendance-generator/preview', $filters)->assertOk();
        $this->postJson('/tools/attendance-generator/generate', ['preview_token' => $first['token']])->assertUnprocessable();
        $this->travel(11)->minutes();
        $this->postJson('/tools/attendance-generator/generate', ['preview_token' => $second['token']])->assertUnprocessable();
        $this->postJson('/tools/attendance-generator/preview', [...$filters, 'mode' => 'both'])->assertUnprocessable();
        $this->postJson('/tools/attendance-generator/preview', [...$filters, 'cutoff_start' => '2026-10-05'])->assertUnprocessable();
        $this->assertDatabaseCount('entrance_attendances', 0);
    }

    public function test_preview_rate_limit_does_not_consume_the_generate_limit(): void
    {
        $employee = $this->faculty();
        $this->actingAs($this->account());
        $filters = ['mode' => 'class', 'cutoff_start' => '2026-10-01', 'employee_id' => $employee->id];
        for ($index = 0; $index < 15; $index++) {
            $response = $this->postJson('/tools/attendance-generator/preview', $filters)->assertOk();
        }
        $this->postJson('/tools/attendance-generator/preview', $filters)->assertStatus(429);
        $this->postJson('/tools/attendance-generator/generate', ['preview_token' => $response['token']])->assertOk()->assertJsonPath('result.created', 1);
    }

    public function test_full_backup_restore_preserves_generated_records_but_cannot_enable_a_disabled_generator(): void
    {
        $employee = $this->faculty();
        $generator = $this->account();
        $this->actingAs($generator);
        $preview = $this->postJson('/tools/attendance-generator/preview', ['mode' => 'school', 'cutoff_start' => '2026-10-01', 'employee_id' => $employee->id])->assertOk();
        $this->postJson('/tools/attendance-generator/generate', ['preview_token' => $preview['token']])->assertOk();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->flushSession();
        $this->actingAs($admin);
        $backup = $this->get('/settings/backup/download')->streamedContent();
        config(['attendance_generator.enabled' => false]);
        $this->post('/settings/backup/restore', ['backup_file' => UploadedFile::fake()->createWithContent('backup.json', $backup)])->assertRedirect('/login')->assertSessionHasNoErrors();
        $this->assertDatabaseCount('entrance_attendances', 1);
        $this->assertDatabaseHas('entrance_attendances', ['recorded_by' => $generator->id, 'out_recorded_by' => $generator->id]);
        $this->post('/login', ['email' => $generator->email, 'password' => 'private-generator-passphrase'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertSame(0, DB::table('audit_trails')->count());
    }
}
