<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Rules\PasswordByteLimit;
use App\Services\AccountSecurityService;
use App\Services\GoogleAppsScriptMailer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ProfileController extends Controller
{
    public function edit(Request $request)
    {
        $user = $request->user()->load(['employee.facultyRank', 'employee.employmentHistories']);

        return Inertia::render('Profile/Edit', [
            'profileUser' => $user,
        ]);
    }

    public function update(Request $request, GoogleAppsScriptMailer $mailer, AccountSecurityService $security)
    {
        /** @var User $user */
        $user = $request->user();
        $employee = $user->employee;

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'current_password' => ['nullable', 'required_with:password', 'current_password'],
            'password' => ['nullable', 'required_with:current_password', 'string', 'min:12', 'max:72', new PasswordByteLimit, 'confirmed', 'different:current_password'],
        ];

        if ($employee) {
            $rules = [
                ...$rules,
                'first_name' => ['required', 'string', 'max:100'],
                'middle_name' => ['nullable', 'string', 'max:100'],
                'last_name' => ['required', 'string', 'max:100'],
                'suffix' => ['nullable', 'string', 'max:20'],
                'email' => [
                    'required',
                    'email:rfc',
                    'ends_with:@cvsu.edu.ph',
                    'max:255',
                    Rule::unique('users', 'email')->ignore($user->id),
                    Rule::unique('employees', 'email')->ignore($employee->id),
                ],
                'contact_no' => ['nullable', 'regex:/^\+639\d{9}$/'],
                'highest_educational_attainment' => ['nullable', Rule::in([
                    "Bachelor's Degree",
                    'Post-Baccalaureate Certificate or Diploma',
                    "Master's Degree Units",
                    "Master's Degree",
                    'Doctorate Degree Units',
                    'Doctorate Degree',
                    'Postdoctoral Studies',
                ])],
                'service_start_date' => ['nullable', 'date', 'before_or_equal:today'],
                'tin_no' => ['nullable', 'string', 'max:30'],
                'gsis_no' => ['nullable', 'string', 'max:30'],
                'pag_ibig_no' => ['nullable', 'string', 'max:30'],
                'philhealth_no' => ['nullable', 'string', 'max:30'],
                'employment_history' => ['nullable', 'array', 'max:20'],
                'employment_history.*.employer' => ['required', 'string', 'max:150'],
                'employment_history.*.position' => ['required', 'string', 'max:150'],
                'employment_history.*.started_on' => ['required', 'date'],
                'employment_history.*.ended_on' => ['nullable', 'date'],
            ];
        }

        $data = $request->validate($rules);
        $emailChanged = strtolower($data['email']) !== strtolower($user->email);

        DB::transaction(function () use ($data, $user, $employee, $emailChanged) {
            if ($employee) {
                $employee->update([
                    'first_name' => $data['first_name'],
                    'middle_name' => $data['middle_name'] ?? null,
                    'last_name' => $data['last_name'],
                    'suffix' => $data['suffix'] ?? null,
                    'email' => strtolower($data['email']),
                    'contact_no' => $data['contact_no'] ?? null,
                    'highest_educational_attainment' => $data['highest_educational_attainment'] ?? null,
                    'service_start_date' => $data['service_start_date'] ?? null,
                    'tin_no' => $data['tin_no'] ?? null,
                    'gsis_no' => $data['gsis_no'] ?? null,
                    'pag_ibig_no' => $data['pag_ibig_no'] ?? null,
                    'philhealth_no' => $data['philhealth_no'] ?? null,
                ]);

                $employee->employmentHistories()->delete();
                foreach ($data['employment_history'] ?? [] as $history) {
                    if (($history['ended_on'] ?? null) && $history['ended_on'] < $history['started_on']) {
                        throw ValidationException::withMessages([
                            'employment_history' => 'Employment end month must be after its start month.',
                        ]);
                    }
                    $employee->employmentHistories()->create($history);
                }

                $user->name = implode(' ', array_filter([
                    $data['first_name'],
                    $data['middle_name'] ?? null,
                    $data['last_name'],
                    $data['suffix'] ?? null,
                ]));
            } else {
                $user->name = $data['name'];
            }

            $user->email = strtolower($data['email']);
            if ($emailChanged) {
                $user->email_verified_at = null;
            }
            if (! empty($data['password'])) {
                $user->password = Hash::make($data['password']);
                $user->must_change_password = false;
            }
            $user->save();
        });

        if ($emailChanged || ! empty($data['password'])) {
            $security->revokeSessions($user, $request);
        }

        $verificationSent = $emailChanged ? $mailer->sendVerification($user) : null;

        return redirect()->route('profile.edit')->with('success', $emailChanged
            ? ($verificationSent ? 'Profile updated. Verify your new email using the link we sent.' : 'Profile updated, but the verification email could not be sent.')
            : 'Your profile has been updated.');
    }
}
