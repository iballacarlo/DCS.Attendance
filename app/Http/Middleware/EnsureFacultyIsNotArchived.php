<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class EnsureFacultyIsNotArchived
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user?->employee_id && DB::table('employees')->where('id', $user->employee_id)->whereNotNull('archived_at')->exists()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'This faculty account is archived. Contact an administrator to restore it.']);
        }

        return $next($request);
    }
}
