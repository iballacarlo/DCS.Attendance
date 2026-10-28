<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccountSecurityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_temporary_guard_password_blocks_recording_until_changed_then_opens_kiosk(): void
    {
        $guard = User::factory()->create(['role' => 'guard', 'password' => 'temporary-password', 'must_change_password' => true]);
        $this->post('/login', ['email' => $guard->email, 'password' => 'temporary-password'])->assertRedirect('/entrance-kiosk');
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
        $target = User::factory()->create(['role' => 'guard']);
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
        $admin = User::factory()->create();
        $target = User::factory()->create(['role' => 'guard']);
        $data = ['name' => $target->name, 'email' => $target->email, 'role' => 'guard', 'password' => 'new-temporary-password'];
        $this->actingAs($admin)->put('/settings/accounts/'.$target->id, $data)->assertSessionHasNoErrors();
        $this->assertTrue($target->fresh()->must_change_password);
        $this->assertSame(1, $target->fresh()->security_version);
        $this->post('/settings/accounts', [...$data, 'email' => 'short@example.test', 'password' => 'short'])->assertSessionHasErrors('password');
        $this->post('/settings/accounts', [...$data, 'email' => 'new@example.test'])->assertSessionHasNoErrors();
        $this->assertTrue(User::where('email', 'new@example.test')->firstOrFail()->must_change_password);
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
