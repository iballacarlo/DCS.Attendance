<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

        $roles = ['admin', 'faculty', 'guard'];
        if (config('attendance_generator.enabled')) {
            $roles[] = 'generator';
        }
        if (Auth::attempt([...$credentials, fn ($query) => $query->whereIn('role', $roles)])) {
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

        return back()->withErrors([
            'email' => 'The provided credentials do not match our records.',
        ])->onlyInput('email');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
