<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureActiveSystemRole
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user() && ! in_array($request->user()->role, ['admin', 'faculty', 'guard'], true)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'This account is disabled. Contact an administrator to assign an active role.']);
        }

        if ($request->user()?->role === 'guard' && ! $request->routeIs('dashboard', 'entrance.kiosk', 'entrance.kiosk.store', 'entrance.kiosk.preview', 'logout', 'account.password.*')) {
            abort(403, 'Guard accounts can only use the entrance kiosk and change their own password.');
        }

        return $next($request);
    }
}
