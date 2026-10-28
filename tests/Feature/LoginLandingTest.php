<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LoginLandingTest extends TestCase
{
    use RefreshDatabase;

    public static function loginClients(): array
    {
        $cases = [];
        foreach (['admin', 'faculty', 'guard'] as $role) {
            foreach (['desktop' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', 'mobile' => 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/130.0.0.0 Mobile Safari/537.36'] as $client => $agent) {
                $cases[$role.' '.$client] = [$role, $agent];
            }
        }

        return $cases;
    }

    #[DataProvider('loginClients')]
    public function test_login_uses_the_role_landing_page_instead_of_a_saved_destination(string $role, string $agent): void
    {
        $user = User::factory()->create(['role' => $role, 'password' => 'login-test-password']);
        $this->withHeader('User-Agent', $agent)->withSession(['url.intended' => '/attendance/dtr'])
            ->post('/login', ['email' => $user->email, 'password' => 'login-test-password'])
            ->assertRedirect(route($role === 'guard' ? 'entrance.kiosk' : 'dashboard'))->assertSessionMissing('url.intended');
        $this->assertAuthenticatedAs($user);
        if ($role === 'guard') {
            $this->get('/')->assertRedirect(route('entrance.kiosk'));
            $this->get('/entrance-kiosk')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Entrance/Kiosk'));
        } else {
            $this->get('/')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Dashboard'));
        }
    }
}
