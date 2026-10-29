<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureActiveSystemRole
{
    public function handle(Request $request, Closure $next)
    {
        $roles = ['admin', 'faculty', 'guard'];
        if (config('attendance_generator.enabled')) {
            $roles[] = 'generator';
        }
        if ($request->user() && ! in_array($request->user()->role, $roles, true)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                return response()->json(['message' => 'This account is disabled. Sign in again.'], 401);
            }

            return redirect()->route('login')->withErrors(['email' => 'This account is disabled. Contact an administrator to assign an active role.']);
        }

        if ($request->user()?->role === 'guard' && ! $request->routeIs('dashboard', 'entrance.kiosk', 'entrance.kiosk.store', 'entrance.kiosk.preview', 'logout', 'account.password.*')) {
            abort(403, 'Guard accounts can only use the entrance kiosk and change their own password.');
        }

        if ($request->user()?->role === 'generator' && ! $request->routeIs('generator.*', 'dashboard', 'logout', 'account.password.*')) {
            abort(403, 'This account can only use the attendance generator and change its own password.');
        }

        return $next($request);
    }
}
