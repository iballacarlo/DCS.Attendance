<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Rules\PasswordByteLimit;
use App\Services\AccountSecurityService;
use App\Services\GoogleAppsScriptMailer;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Inertia\Inertia;

class PasswordResetController extends Controller
{
    public function create()
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    public function store(Request $request, GoogleAppsScriptMailer $mailer)
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $user = User::where('email', $data['email'])->where('role', '!=', 'generator')->first();

        if ($user) {
            $token = Password::broker()->createToken($user);

            if (! $mailer->sendPasswordReset($user, $token)) {
                return back()->withErrors([
                    'email' => 'The password reset email could not be sent. Please try again later.',
                ])->onlyInput('email');
            }
        }

        return back()->with('success', 'If an account exists for that email address, a password reset link has been sent.');
    }

    public function edit(Request $request, string $token)
    {
        return Inertia::render('Auth/ResetPassword', [
            'email' => $request->string('email')->toString(),
            'token' => $token,
        ]);
    }

    public function update(Request $request, AccountSecurityService $security)
    {
        $data = $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'confirmed', 'max:72', new PasswordByteLimit, PasswordRule::min(12)],
        ]);

        if (User::where('email', $data['email'])->where('role', 'generator')->exists()) {
            return back()->withErrors(['email' => __(Password::INVALID_USER)])->onlyInput('email');
        }

        $status = Password::reset($data, function (User $user, string $password) use ($security) {
            $user->forceFill([
                'password' => $password,
                'must_change_password' => false,
            ])->setRememberToken(Str::random(60));
            $user->save();
            $security->revokeSessions($user);

            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withErrors(['email' => __($status)])->onlyInput('email');
        }

        return redirect()->route('login')->with('success', 'Your password has been reset. You can now sign in.');
    }
}
