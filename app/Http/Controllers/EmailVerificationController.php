<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\User;
use App\Services\GoogleAppsScriptMailer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class EmailVerificationController extends Controller
{
    public function show(Request $request, User $user, string $hash)
    {
        abort_unless(hash_equals($hash, sha1($user->getEmailForVerification())), 403);
        abort_if($user->must_change_password, 403, 'Create your private password before verifying your email address.');

        if ($user->hasVerifiedEmail()) {
            return redirect()->route($request->user() ? 'dashboard' : 'login')->with('success', 'This email address is already verified.');
        }

        return Inertia::render('Auth/VerifyEmail', [
            'verificationEmail' => $user->email,
            'verificationAction' => $request->fullUrl(),
        ]);
    }

    public function verify(Request $request, User $user, string $hash, GoogleAppsScriptMailer $mailer)
    {
        abort_unless(hash_equals($hash, sha1($user->getEmailForVerification())), 403);
        abort_if($user->must_change_password, 403, 'Create your private password before verifying your email address.');

        $data = $request->validate(['password' => ['required', 'string']]);
        if (! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => 'The password does not match this account.']);
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        $confirmationSent = $mailer->sendVerificationConfirmed($user);

        return redirect()->route($request->user() ? 'dashboard' : 'login')->with(
            $confirmationSent ? 'success' : 'error',
            $confirmationSent
                ? 'Email address verified. A confirmation email was sent to you.'
                : 'Email address verified, but the confirmation email could not be sent.'
        );
    }

    public function send(Request $request, GoogleAppsScriptMailer $mailer)
    {
        if ($request->user()->must_change_password) {
            return back()->with('error', 'Create your private password before requesting email verification.');
        }
        $sent = $mailer->sendVerification($request->user());

        return back()->with(
            $sent ? 'success' : 'error',
            $sent ? 'A new verification link was sent.' : 'The verification email could not be sent. Check the mail service configuration.'
        );
    }

    public function resendForEmployee(Employee $employee, GoogleAppsScriptMailer $mailer)
    {
        abort_unless($employee->user, 404);
        if ($employee->user->must_change_password) {
            return back()->with('error', 'This faculty must create a private password before email verification can be sent.');
        }
        $sent = $mailer->sendVerification($employee->user);

        return back()->with(
            $sent ? 'success' : 'error',
            $sent ? 'Verification email sent to '.$employee->user->email.'.' : 'The verification email could not be sent.'
        );
    }
}
