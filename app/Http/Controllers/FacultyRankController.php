<?php

namespace App\Http\Controllers;

use App\Models\FacultyRank;
use Inertia\Inertia;

class FacultyRankController extends Controller
{
    public function index()
    {
        return Inertia::render('Ranks/Index', ['ranks' => FacultyRank::withCount('employees')->whereNotNull('salary_grade')->get()
            ->sortBy(fn ($rank) => (int) preg_replace('/\D/', '', $rank->salary_grade))->values()]);
    }
}
