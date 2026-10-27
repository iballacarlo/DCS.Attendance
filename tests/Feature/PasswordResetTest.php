<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_request_and_complete_a_password_reset(): void
    {
        config([
            'services.google_mail.webhook_url' => 'https://script.google.com/test/exec',
            'services.google_mail.webhook_secret' => 'test-secret',
        ]);
        Http::fake([
            'https://script.google.com/*' => Http::response(['ok' => true]),
        ]);
        $user = User::factory()->create(['must_change_password' => true]);

        $this->get(route('password.request'))->assertOk();
        $this->post(route('password.email'), ['email' => $user->email])
            ->assertSessionHas('success');

        $request = Http::recorded()->first()[0];
        $this->assertSame($user->email, $request['recipient']);
        $this->assertStringContainsString('Reset your DCS Attendance & DTR password', $request['subject']);
        $this->assertMatchesRegularExpression('/reset-password\/[A-Za-z0-9]+\?email=/', $request['message']);
        preg_match('/reset-password\/([A-Za-z0-9]+)\?email=/', $request['message'], $matches);

        $this->get(route('password.reset', ['token' => $matches[1], 'email' => $user->email]))
            ->assertOk();
        $this->post(route('password.update'), [
            'token' => $matches[1],
            'email' => $user->email,
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
        ])->assertRedirect(route('login'));

        $user->refresh();
        $this->assertTrue(Hash::check('NewSecurePassword123!', $user->password));
        $this->assertFalse($user->must_change_password);
    }

    public function test_unknown_email_receives_the_same_safe_response(): void
    {
        Http::fake();

        $this->post(route('password.email'), ['email' => 'missing@example.com'])
            ->assertSessionHas('success');

        Http::assertNothingSent();
    }
}
