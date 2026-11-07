<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Inertia;

class AuthController extends Controller
{
    public function showLogin()
    {
        return Inertia::render('Auth/Login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $loginKey = $this->loginKey($request);
        $attemptKey = 'login-attempts:'.$loginKey;
        $cooldownKey = 'login-cooldown:'.$loginKey;
        $maxAttempts = (int) config('auth.login.max_attempts', 5);
        $decaySeconds = (int) config('auth.login.decay_seconds', 900);

        if (RateLimiter::tooManyAttempts($cooldownKey, 1)) {
            return $this->cooldownResponse($cooldownKey);
        }

        $roles = ['admin', 'faculty', 'guard'];
        if (config('attendance_generator.enabled')) {
            $roles[] = 'generator';
        }
        if (Auth::attempt([...$credentials, fn ($query) => $query->whereIn('role', $roles)])) {
            RateLimiter::clear($attemptKey);
            RateLimiter::clear($cooldownKey);
            $request->session()->regenerate();
            $request->session()->put('account_security_version', Auth::user()->security_version);

            $request->session()->forget('url.intended');

            if (Auth::user()->must_change_password) {
                return redirect()->route('account.password.edit');
            }

            return redirect()->route(match (Auth::user()->role) {
                'guard' => 'entrance.kiosk',
                'generator' => 'generator.index',
                default => 'dashboard',
            });
        }

        RateLimiter::hit($attemptKey, $decaySeconds);

        if (RateLimiter::tooManyAttempts($attemptKey, $maxAttempts)) {
            RateLimiter::clear($attemptKey);
            RateLimiter::hit($cooldownKey, $decaySeconds);

            return $this->cooldownResponse($cooldownKey);
        }

        $remainingAttempts = RateLimiter::remaining($attemptKey, $maxAttempts);

        return back()->withErrors([
            'email' => 'The provided credentials do not match our records. '.$remainingAttempts.' '.Str::plural('attempt', $remainingAttempts).' remaining.',
        ])->onlyInput('email');
    }

    private function loginKey(Request $request): string
    {
        return Str::lower(trim((string) $request->input('email'))).'|'.$request->ip();
    }

    private function cooldownResponse(string $throttleKey)
    {
        $seconds = max(1, RateLimiter::availableIn($throttleKey));

        return back()
            ->withErrors([
                'email' => 'Too many failed sign-in attempts. Try again when the cooldown ends.',
            ])
            ->with('login_cooldown_until', now()->addSeconds($seconds)->timestamp)
            ->onlyInput('email');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
