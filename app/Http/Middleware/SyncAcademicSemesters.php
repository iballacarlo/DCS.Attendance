<?php

namespace App\Http\Middleware;

use App\Services\SemesterLifecycleService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SyncAcademicSemesters
{
    public function handle(Request $request, Closure $next): Response
    {
        app(SemesterLifecycleService::class)->synchronize();

        return $next($request);
    }
}
