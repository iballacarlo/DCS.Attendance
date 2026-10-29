<?php

namespace App\Http\Controllers;

use App\Rules\PasswordByteLimit;
use App\Services\AccountSecurityService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AccountPasswordController extends Controller
{
    public function edit()
    {
        return Inertia::render('Auth/ChangePassword');
    }

    public function update(Request $request, AccountSecurityService $security)
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'min:12', 'max:72', new PasswordByteLimit, 'confirmed', 'different:current_password'],
        ]);

        $user = $request->user();
        $user->update(['password' => $data['password'], 'must_change_password' => false]);
        $security->revokeSessions($user, $request);

        return redirect()->route(match ($user->role) {
            'guard' => 'entrance.kiosk',
            'generator' => 'generator.index',
            default => 'dashboard',
        })->with('success', 'Password changed. Other sessions have been signed out.');
    }
}
