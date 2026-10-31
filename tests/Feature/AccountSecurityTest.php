<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use App\Services\AccountSecurityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_temporary_guard_password_blocks_recording_until_changed_then_opens_kiosk(): void
    {
        config([
            'services.google_mail.webhook_url' => 'https://mail.test/send',
            'services.google_mail.webhook_secret' => 'test-secret',
            'services.google_mail.logo_url' => 'https://assets.test/cvsu-logo.png',
            'services.google_mail.dcs_logo_url' => 'https://assets.test/dcs-logo.png',
        ]);
        Http::fake(['https://mail.test/*' => Http::response(['ok' => true])]);
        $guard = User::factory()->create(['role' => 'guard', 'password' => 'temporary-password', 'must_change_password' => true, 'email_verified_at' => null]);
        $this->post('/login', ['email' => $guard->email, 'password' => 'temporary-password'])->assertRedirect('/account/password');
        $this->get('/')->assertRedirect('/entrance-kiosk');
        $this->get('/account/password')->assertOk();
        $this->get('/entrance-kiosk')->assertRedirect('/account/password');
        $this->postJson('/entrance-kiosk', ['identifier' => '12345678'])->assertStatus(423);
        $this->assertDatabaseCount('entrance_attendances', 0);

        $this->put('/account/password', [
            'current_password' => 'temporary-password',
            'password' => 'a-new-private-passphrase',
            'password_confirmation' => 'a-new-private-passphrase',
        ])->assertRedirect('/entrance-kiosk')->assertSessionHasNoErrors();

        $this->assertFalse($guard->fresh()->must_change_password);
        $this->assertTrue(Hash::check('a-new-private-passphrase', $guard->fresh()->password));
        Http::assertSent(fn ($request) => str_contains($request['subject'], 'Verify your DCS Attendance System email address')
            && ! str_contains($request['message'], 'Temporary password:'));
        $this->get('/entrance-kiosk')->assertOk();
        $this->get('/settings/accounts')->assertForbidden();
    }

    public function test_password_change_requires_current_password_and_rejects_short_or_reused_passwords(): void
    {
        $user = User::factory()->create(['password' => 'original-private-password']);
        $this->actingAs($user);
        foreach ([
            ['wrong-password', 'a-valid-new-passphrase', 'current_password'],
            ['original-private-password', 'short', 'password'],
            ['original-private-password', 'original-private-password', 'password'],
            ['original-private-password', str_repeat('x', 73), 'password'],
            ['original-private-password', str_repeat('é', 40), 'password'],
        ] as [$current, $password, $error]) {
            $this->put('/account/password', ['current_password' => $current, 'password' => $password, 'password_confirmation' => $password])
                ->assertSessionHasErrors($error);
        }
        $this->assertTrue(Hash::check('original-private-password', $user->fresh()->password));
    }

    public function test_admin_access_changes_revoke_the_targets_existing_sessions(): void
    {
        $admin = User::factory()->create();
        $target = User::factory()->create(['role' => 'guard', 'must_change_password' => true]);
        $oldToken = $target->remember_token;
        $this->actingAs($admin)->put('/settings/accounts/'.$target->id, [
            'name' => $target->name, 'email' => $target->email, 'role' => 'admin',
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, $target->fresh()->security_version);
        $this->assertNotSame($oldToken, $target->fresh()->remember_token);
        $this->actingAs($target->fresh())->withSession(['account_security_version' => 0])->get('/')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_admin_password_reset_requires_change_and_new_accounts_reject_short_passwords(): void
    {
        config(['services.google_mail.webhook_url' => 'https://mail.test/send', 'services.google_mail.webhook_secret' => 'test-secret']);
        Http::fake(['https://mail.test/*' => Http::response(['ok' => true])]);
        $admin = User::factory()->create();
        $target = User::factory()->create(['role' => 'guard', 'must_change_password' => true]);
        $data = ['name' => $target->name, 'email' => $target->email, 'role' => 'guard'];
        $oldPassword = $target->password;
        $data['reset_password'] = true;
        $this->actingAs($admin)->put('/settings/accounts/'.$target->id, $data)->assertSessionHasNoErrors();
        $this->assertTrue($target->fresh()->must_change_password);
        $this->assertSame(1, $target->fresh()->security_version);
        $this->assertNotSame($oldPassword, $target->fresh()->password);
        $this->post('/settings/accounts', [...$data, 'email' => 'manual@example.test', 'password' => 'known-by-admin'])->assertSessionHasErrors('password');
        $response = $this->post('/settings/accounts', ['name' => 'New Guard', 'email' => 'new@example.test', 'role' => 'guard']);
        $response->assertSessionHasNoErrors()->assertSessionMissing('temporary_credentials')->assertRedirect('/settings/accounts');
        $created = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertTrue($created->must_change_password);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request['to'] === 'new@example.test'
            && str_contains($request['message'], 'Temporary password:'));
    }

    public function test_admin_cannot_issue_another_temporary_password_after_faculty_sets_a_private_password(): void
    {
        config(['services.google_mail.webhook_url' => 'https://mail.test/send', 'services.google_mail.webhook_secret' => 'test-secret']);
        Http::fake(['https://mail.test/*' => Http::response(['ok' => true])]);
        $employee = Employee::create(['employee_no' => 'COS-RESET-001', 'first_name' => 'Reset', 'last_name' => 'Faculty', 'email' => 'reset.faculty@cvsu.edu.ph', 'rate_amount' => 0, 'status' => 'active']);
        $faculty = User::factory()->create(['role' => 'faculty', 'employee_id' => $employee->id, 'email' => $employee->email, 'password' => 'faculty-created-password', 'must_change_password' => false]);
        $admin = User::factory()->create(['role' => 'admin']);

        $originalPassword = $faculty->password;
        $this->actingAs($admin)->put('/settings/accounts/'.$faculty->id, [
            'role' => 'faculty', 'employee_id' => $employee->id, 'reset_password' => true,
        ])->assertSessionHasErrors('reset_password');

        $this->assertSame($originalPassword, $faculty->fresh()->password);
        $this->assertFalse($faculty->fresh()->must_change_password);
        Http::assertNothingSent();
    }

    public function test_generic_account_form_cannot_create_faculty_accounts(): void
    {
        config(['services.google_mail.webhook_url' => 'https://mail.test/send', 'services.google_mail.webhook_secret' => 'test-secret']);
        Http::fake(['https://mail.test/*' => Http::response(['ok' => true])]);
        $employee = Employee::create([
            'employee_no' => 'COS-NAME-001',
            'first_name' => 'Maria',
            'middle_name' => 'Santos',
            'last_name' => 'Reyes',
            'suffix' => 'Jr.',
            'email' => 'maria.reyes@cvsu.edu.ph',
            'rate_amount' => 0,
            'status' => 'active',
        ]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post('/settings/accounts', [
                'name' => 'Different Typed Name',
                'role' => 'faculty',
                'employee_id' => $employee->id,
            ])->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('users', ['employee_id' => $employee->id]);
        Http::assertNothingSent();
    }

    public function test_admin_can_create_a_missing_faculty_account_from_the_faculty_list(): void
    {
        config([
            'services.google_mail.webhook_url' => 'https://mail.test/send',
            'services.google_mail.webhook_secret' => 'test-secret',
            'services.google_mail.logo_url' => 'https://assets.test/cvsu-logo.png',
            'services.google_mail.dcs_logo_url' => 'https://assets.test/dcs-logo.png',
        ]);
        Http::fake(['https://mail.test/*' => Http::response(['ok' => true])]);
        $employee = Employee::create([
            'employee_no' => 'COS-RECOVER-001',
            'first_name' => 'Maria',
            'middle_name' => 'Santos',
            'last_name' => 'Reyes',
            'suffix' => 'Jr.',
            'email' => 'maria.reyes@cvsu.edu.ph',
            'rate_amount' => 0,
            'status' => 'active',
        ]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post('/employees/'.$employee->id.'/account')
            ->assertSessionHasNoErrors();

        $account = User::where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame('Maria Santos Reyes Jr.', $account->name);
        $this->assertSame('maria.reyes@cvsu.edu.ph', $account->email);
        $this->assertTrue($account->must_change_password);
        Http::assertSent(fn ($request) => $request['to'] === 'maria.reyes@cvsu.edu.ph'
            && str_contains($request['message'], 'Temporary password:')
            && str_contains($request['html'], 'https://assets.test/cvsu-logo.png')
            && str_contains($request['html'], 'https://assets.test/dcs-logo.png')
            && str_contains($request['html'], 'alt="DCS logo"'));
    }

    public function test_admin_can_unlink_a_faculty_account_and_its_existing_session_is_revoked(): void
    {
        $employee = Employee::create([
            'employee_no' => 'COS-UNLINK-001',
            'first_name' => 'Linked',
            'last_name' => 'Faculty',
            'email' => 'linked.faculty@cvsu.edu.ph',
            'rate_amount' => 0,
            'status' => 'active',
        ]);
        $faculty = User::factory()->create([
            'role' => 'faculty',
            'employee_id' => $employee->id,
            'name' => 'Linked Faculty',
            'email' => 'linked.account@cvsu.edu.ph',
        ]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->put('/settings/accounts/'.$faculty->id, [
                'name' => $faculty->name,
                'email' => $faculty->email,
                'role' => 'faculty',
                'employee_id' => null,
            ])->assertSessionHasNoErrors();

        $faculty->refresh();
        $this->assertNull($faculty->employee_id);
        $this->assertSame(1, $faculty->security_version);
    }

    public function test_one_faculty_record_cannot_be_linked_to_two_accounts(): void
    {
        $employee = Employee::create([
            'employee_no' => 'COS-UNIQUE-001',
            'first_name' => 'One',
            'last_name' => 'Faculty',
            'email' => 'one.faculty@cvsu.edu.ph',
            'rate_amount' => 0,
            'status' => 'active',
        ]);
        $linked = User::factory()->create([
            'role' => 'faculty',
            'employee_id' => $employee->id,
            'email' => 'first.account@cvsu.edu.ph',
        ]);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post('/employees/'.$employee->id.'/account')
            ->assertSessionHas('error', 'This faculty already has a linked account.');

        $this->get('/settings/accounts/create')->assertInertia(fn ($page) => $page
            ->where('employees', fn ($employees) => collect($employees)->doesntContain('id', $employee->id)));
        $this->get('/settings/accounts/'.$linked->id.'/edit')->assertInertia(fn ($page) => $page
            ->where('employees', fn ($employees) => collect($employees)->contains('id', $employee->id)));
        $this->assertSame(1, User::where('employee_id', $employee->id)->count());
    }

    public function test_password_recovery_revokes_sessions_and_stale_session_cannot_resume(): void
    {
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);
        $this->post('/reset-password', [
            'token' => $token, 'email' => $user->email,
            'password' => 'a-recovered-private-passphrase', 'password_confirmation' => 'a-recovered-private-passphrase',
        ])->assertRedirect('/login');
        $this->assertSame(1, $user->fresh()->security_version);
        $this->actingAs($user->fresh())->withSession(['account_security_version' => 0])->getJson('/')->assertUnauthorized();
    }

    public function test_database_sessions_are_deleted_only_for_the_affected_account(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create();
        $other = User::factory()->create();
        foreach (['first' => $user->id, 'second' => $user->id, 'other' => $other->id] as $id => $userId) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'payload' => '', 'last_activity' => time()]);
        }
        app(AccountSecurityService::class)->revokeSessions($user);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('sessions', ['id' => 'other']);
    }

    public function test_full_legacy_restore_cannot_reactivate_an_old_session(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $backup = json_decode($this->get('/settings/backup/download')->streamedContent(), true);
        foreach ($backup['tables']['users'] as &$row) {
            unset($row['security_version']);
        }
        unset($row);
        app(AccountSecurityService::class)->revokeSessions($user);
        $this->withSession(['account_security_version' => 1])->post('/settings/backup/restore', [
            'backup_file' => UploadedFile::fake()->createWithContent('legacy.json', json_encode($backup)),
        ])->assertRedirect('/login')->assertSessionHasNoErrors();
        $this->assertGuest();
        $this->assertSame(2, $user->fresh()->security_version);
        $this->actingAs($user->fresh())->withSession(['account_security_version' => 0])->get('/')->assertRedirect('/login');
    }

    public function test_production_security_check_fails_for_unsafe_settings_without_exposing_keys(): void
    {
        config(['app.debug' => true, 'session.secure' => false]);
        $this->artisan('security:check')->expectsOutputToContain('FAIL Debug disabled')
            ->expectsOutputToContain('FAIL Secure session cookie')->assertExitCode(1);
    }

    public function test_production_security_check_accepts_secure_settings(): void
    {
        $this->app->instance('env', 'production');
        config([
            'app.debug' => false, 'app.url' => 'https://attendance.example.test',
            'session.secure' => true, 'session.http_only' => true, 'session.same_site' => 'lax',
            'session.encrypt' => true, 'session.driver' => 'database', 'session.lifetime' => 120,
            'services.hardware.api_key' => str_repeat('test', 16),
        ]);
        $this->artisan('security:check')->expectsOutputToContain('PASS Production environment')->assertExitCode(0);
    }
}
