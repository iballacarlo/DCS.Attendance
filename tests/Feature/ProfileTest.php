<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\FacultyRank;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_view_their_profile(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/profile')->assertOk();
    }

    public function test_user_can_update_their_account_and_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('old-password'),
            'must_change_password' => true,
        ]);

        $this->actingAs($user)->put('/profile', [
            'name' => 'Updated Administrator',
            'email' => 'updated@example.com',
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertRedirect('/profile');

        $user->refresh();
        $this->assertSame('Updated Administrator', $user->name);
        $this->assertSame('updated@example.com', $user->email);
        $this->assertTrue(Hash::check('new-password', $user->password));
        $this->assertFalse($user->must_change_password);
    }

    public function test_faculty_can_update_personal_but_not_employment_details(): void
    {
        $rank = FacultyRank::where('name', 'Instructor I')->firstOrFail();
        $employee = Employee::create([
            'employee_no' => '2026-0001',
            'first_name' => 'Old',
            'last_name' => 'Name',
            'email' => 'old.name@cvsu.edu.ph',
            'highest_educational_attainment' => "Bachelor's Degree",
            'service_start_date' => '2025-01-01',
            'position' => 'COS Faculty Member',
            'department' => 'Department of Computer Studies',
            'employment_type' => 'Contract of Service',
            'faculty_rank_id' => $rank->id,
            'rate_type' => 'hourly',
            'rate_amount' => 250,
            'status' => 'active',
        ]);
        $user = User::factory()->create([
            'role' => 'faculty',
            'employee_id' => $employee->id,
            'email' => 'old.name@cvsu.edu.ph',
        ]);

        $this->actingAs($user)->put('/profile', [
            'name' => $user->name,
            'first_name' => 'New',
            'middle_name' => 'Middle',
            'last_name' => 'Name',
            'suffix' => '',
            'email' => 'new.name@cvsu.edu.ph',
            'contact_no' => '+639171234567',
            'highest_educational_attainment' => "Master's Degree",
            'service_start_date' => '2024-06-01',
            'employment_history' => [[
                'employer' => 'Previous University',
                'position' => 'Instructor',
                'started_on' => '2020-06-01',
                'ended_on' => '2024-05-01',
            ]],
            'rate_amount' => 9999,
            'status' => 'inactive',
            'current_password' => '',
            'password' => '',
            'password_confirmation' => '',
        ])->assertRedirect('/profile');

        $employee->refresh();
        $this->assertSame('New', $employee->first_name);
        $this->assertSame('+639171234567', $employee->contact_no);
        $this->assertSame("Master's Degree", $employee->highest_educational_attainment);
        $this->assertDatabaseHas('employment_histories', [
            'employee_id' => $employee->id,
            'employer' => 'Previous University',
        ]);
        $this->assertEquals(250, $employee->rate_amount);
        $this->assertSame('active', $employee->status);
    }

    public function test_signed_verification_link_requires_the_account_password_and_sends_confirmation(): void
    {
        config([
            'services.google_mail.webhook_url' => 'https://script.google.com/test/exec',
            'services.google_mail.webhook_secret' => 'test-secret',
        ]);
        Http::fake(['https://script.google.com/*' => Http::response(['ok' => true])]);
        $user = User::factory()->unverified()->create(['password' => 'private-password']);
        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'user' => $user->id,
            'hash' => sha1($user->email),
        ]);

        $this->get($url)->assertOk()->assertInertia(fn ($page) => $page
            ->component('Auth/VerifyEmail')
            ->where('verificationEmail', $user->email));
        $this->post($url, ['password' => 'wrong-password'])->assertSessionHasErrors('password');
        $this->assertNull($user->fresh()->email_verified_at);
        $this->post($url, ['password' => 'private-password'])->assertRedirect(route('login'));

        $this->assertNotNull($user->fresh()->email_verified_at);
        Http::assertSent(fn ($request) => str_contains($request['subject'], 'email is verified')
            && str_contains($request['message'], 'successfully verified'));
    }

    public function test_verification_email_is_sent_through_google_apps_script(): void
    {
        config([
            'services.google_mail.webhook_url' => 'https://script.google.com/test/exec',
            'services.google_mail.webhook_secret' => 'test-secret',
        ]);
        Http::fake([
            'https://script.google.com/*' => Http::response(['ok' => true]),
        ]);
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->post(route('verification.send'))->assertSessionHas('success');

        Http::assertSent(fn ($request) => $request['secret'] === 'test-secret'
            && $request['to'] === $user->email
            && $request['recipient'] === $user->email
            && str_contains($request['message'], 'Verify your email address')
            && str_contains($request['html'], 'Verify email address')
            && ! str_contains($request['html'], 'Temporary password'));
    }
}
