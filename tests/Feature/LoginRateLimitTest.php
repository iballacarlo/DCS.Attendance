<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class LoginRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        foreach (['faculty@cvsu.edu.ph|127.0.0.1', 'other@cvsu.edu.ph|127.0.0.1', 'faculty@cvsu.edu.ph|10.0.0.2'] as $key) {
            RateLimiter::clear('login-attempts:'.$key);
            RateLimiter::clear('login-cooldown:'.$key);
        }
        parent::tearDown();
    }

    public function test_failed_logins_show_remaining_attempts_and_the_fifth_starts_the_cooldown(): void
    {
        User::factory()->create(['email' => 'faculty@cvsu.edu.ph', 'password' => 'correct-password']);

        foreach ([4, 3, 2, 1] as $remaining) {
            $attemptLabel = $remaining === 1 ? 'attempt' : 'attempts';
            $this->post('/login', ['email' => 'faculty@cvsu.edu.ph', 'password' => 'wrong-password'])
                ->assertSessionHasErrors(['email' => "The provided credentials do not match our records. {$remaining} {$attemptLabel} remaining."])
                ->assertSessionMissing('login_cooldown_until');
        }

        $this->post('/login', ['email' => 'faculty@cvsu.edu.ph', 'password' => 'wrong-password'])
            ->assertSessionHasErrors(['email' => 'Too many failed sign-in attempts. Try again when the cooldown ends.'])
            ->assertSessionHas('login_cooldown_until');
    }

    public function test_correct_password_is_blocked_during_cooldown_and_allowed_after_it_expires(): void
    {
        $user = User::factory()->create(['email' => 'faculty@cvsu.edu.ph', 'password' => 'correct-password']);

        foreach (range(1, 5) as $attempt) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'correct-password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->travel(15)->minutes();

        $this->post('/login', ['email' => $user->email, 'password' => 'correct-password'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_cooldown_is_a_full_fifteen_minutes_from_the_fifth_failure(): void
    {
        $user = User::factory()->create(['email' => 'faculty@cvsu.edu.ph', 'password' => 'correct-password']);

        foreach (range(1, 4) as $attempt) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
        }

        $this->travel(10)->minutes();
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertSessionHas('login_cooldown_until');

        $this->travel(6)->minutes();
        $this->post('/login', ['email' => $user->email, 'password' => 'correct-password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->travel(9)->minutes();
        $this->post('/login', ['email' => $user->email, 'password' => 'correct-password'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_successful_login_clears_failures_and_validation_errors_do_not_count(): void
    {
        $user = User::factory()->create(['email' => 'faculty@cvsu.edu.ph', 'password' => 'correct-password']);

        $this->post('/login', ['email' => 'invalid-email', 'password' => 'wrong-password'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertSessionHasErrors(['email' => 'The provided credentials do not match our records. 4 attempts remaining.']);

        $this->post('/login', ['email' => $user->email, 'password' => 'correct-password'])->assertRedirect(route('dashboard'));
        $this->post('/logout');

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertSessionHasErrors(['email' => 'The provided credentials do not match our records. 4 attempts remaining.']);
    }

    public function test_different_email_and_ip_have_independent_counters(): void
    {
        User::factory()->create(['email' => 'faculty@cvsu.edu.ph', 'password' => 'correct-password']);

        foreach (range(1, 5) as $attempt) {
            $this->post('/login', ['email' => 'faculty@cvsu.edu.ph', 'password' => 'wrong-password']);
        }

        $this->post('/login', ['email' => 'other@cvsu.edu.ph', 'password' => 'wrong-password'])
            ->assertSessionHasErrors(['email' => 'The provided credentials do not match our records. 4 attempts remaining.']);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->post('/login', ['email' => 'faculty@cvsu.edu.ph', 'password' => 'wrong-password'])
            ->assertSessionHasErrors(['email' => 'The provided credentials do not match our records. 4 attempts remaining.']);
    }
}
