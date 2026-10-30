<?php

namespace App\Http\Controllers;

class GeneralSettingsController extends Controller
{
    public function edit()
    {
        return redirect()->route('settings.semesters.index');
    }

    public function update()
    {
        // Old forms must not bypass the explicit semester activation workflow.
        return redirect()->route('settings.semesters.index');
    }
}
