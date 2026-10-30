<?php

namespace App\Http\Middleware;

use App\Services\SemesterManagementService;
use App\Services\SemesterScheduleService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SemesterWriteLock
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->isMethod('GET') || ! SemesterScheduleService::managed()
            || ! $request->routeIs('calendar.events.*', 'leave.*', 'settings.dtr-signatories.*')) {
            return $next($request);
        }

        return DB::transaction(function () use ($request, $next) {
            app(SemesterManagementService::class)->lock();

            return $next($request);
        });
    }
}
