<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureAttendanceGeneratorEnabled
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(config('attendance_generator.enabled'), 404);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
