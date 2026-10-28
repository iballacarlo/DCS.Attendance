<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureAccountSecurity
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        if ((int) $request->session()->get('account_security_version', 0) !== (int) $user->security_version) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $request->expectsJson()
                ? response()->json(['message' => 'Your account changed. Please sign in again.'], 401)
                : redirect()->route('login')->withErrors(['email' => 'Your account changed. Please sign in again.']);
        }

        if ($user->must_change_password && ! $request->routeIs('dashboard', 'logout', 'account.password.*', 'profile.edit', 'profile.update')) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Change your temporary password before continuing.', 'redirect' => route('account.password.edit')], 423)
                : redirect()->route('account.password.edit');
        }

        return $next($request);
    }
}
