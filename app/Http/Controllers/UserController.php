<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\User;
use App\Services\AccountSecurityService;
use App\Services\GoogleAppsScriptMailer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'in:admin,faculty,guard'],
        ]);
        $users = User::with('employee')->where('role', '!=', 'generator')->latest();
        $terms = preg_split('/\s+/', trim($filters['search'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($terms as $term) {
            $users->where(function ($userQuery) use ($term) {
                $userQuery->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
                    ->orWhereHas('employee', function ($employeeQuery) use ($term) {
                        $employeeQuery->where('employee_no', 'like', "%{$term}%")
                            ->orWhere('first_name', 'like', "%{$term}%")
                            ->orWhere('middle_name', 'like', "%{$term}%")
                            ->orWhere('last_name', 'like', "%{$term}%");
                    });
            });
        }
        $users->when($filters['role'] ?? null, fn ($query, $role) => $query->where('role', $role));

        return Inertia::render('Users/Index', [
            'users' => $users->paginate(10)->withQueryString(),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'role' => $filters['role'] ?? '',
            ],
        ]);
    }

    public function create()
    {
        return Inertia::render('Users/Form', [
            'user' => new User,
            'employees' => Employee::whereDoesntHave('user')->orderBy('last_name')->get(),
        ]);
    }

    public function store(Request $request, GoogleAppsScriptMailer $mailer)
    {
        $temporaryPassword = Str::random(20);
        $data = $this->validated($request);
        $data['password'] = Hash::make($temporaryPassword);
        $data['must_change_password'] = true;
        $user = User::create($data);
        $emailSent = $mailer->sendTemporaryPassword($user, $temporaryPassword);

        return redirect()->route('settings.accounts.index')
            ->with($emailSent ? 'success' : 'error', $emailSent
                ? 'User account created. The temporary login was sent directly to the account owner’s email.'
                : 'User account created, but the temporary login email was not sent. Check the Google mail configuration before asking the account owner to use Forgot Password.');
    }

    public function edit(User $user)
    {
        abort_if($user->role === 'generator', 404);

        return Inertia::render('Users/Form', [
            'user' => $user,
            'employees' => Employee::whereDoesntHave('user', fn ($query) => $query->where('users.id', '!=', $user->id))
                ->orderBy('last_name')->get(),
        ]);
    }

    public function update(Request $request, User $user, AccountSecurityService $security, GoogleAppsScriptMailer $mailer)
    {
        abort_if($user->role === 'generator', 404);

        $data = $this->validated($request, $user);
        $resetPassword = (bool) ($data['reset_password'] ?? false);
        unset($data['reset_password']);
        if ($resetPassword && ! $user->must_change_password) {
            throw ValidationException::withMessages([
                'reset_password' => 'This account already has a private password. The account owner must use Forgot Password instead.',
            ]);
        }
        $temporaryPassword = $resetPassword ? Str::random(20) : null;
        $previousPassword = $user->password;
        $previousMustChange = $user->must_change_password;
        if ($temporaryPassword) {
            $data['password'] = Hash::make($temporaryPassword);
            $data['must_change_password'] = true;
        }
        $user->fill($data);
        $otherAccessChanged = $user->isDirty(['email', 'role', 'employee_id']);
        $accessChanged = $user->isDirty(['password', 'email', 'role', 'employee_id']);
        $user->save();
        if ($temporaryPassword && ! $mailer->sendTemporaryPassword($user, $temporaryPassword)) {
            $user->forceFill(['password' => $previousPassword, 'must_change_password' => $previousMustChange])->save();
            if ($otherAccessChanged) {
                $security->revokeSessions($user);
            }

            return back()->with('error', 'The account was updated, but the password was not reset because the temporary login email could not be sent. Check the Google mail configuration and try again.');
        }
        if ($accessChanged) {
            $security->revokeSessions($user);
        }

        return redirect()->route('settings.accounts.index')->with('success', $temporaryPassword
            ? 'User account updated. A new temporary login was sent directly to the account owner’s email.'
            : 'User account updated.');
    }

    public function destroy(User $user, AccountSecurityService $security)
    {
        abort_if($user->role === 'generator', 404);

        if (auth()->id() === $user->id) {
            return back()->withErrors(['user' => 'You cannot delete your own account while logged in.']);
        }

        $security->revokeSessions($user);
        $user->delete();

        return redirect()->route('settings.accounts.index')->with('success', 'User account deleted.');
    }

    private function validated(Request $request, ?User $user = null): array
    {
        $rules = [
            'name' => [$request->role === 'faculty' ? 'nullable' : 'required', 'max:255'],
            'email' => $request->role === 'faculty'
                ? ['nullable']
                : ['required', 'email', 'max:255', Rule::unique('users')->ignore($user?->id)],
            'role' => ['required', Rule::in($user ? ['admin', 'faculty', 'guard'] : ['admin', 'guard'])],
            'employee_id' => ['nullable', Rule::exists('employees', 'id')->whereNull('archived_at')],
            'password' => ['prohibited'],
            'reset_password' => ['sometimes', 'boolean'],
        ];

        if ($request->role === 'faculty') {
            $rules['employee_id'] = [
                $user ? 'nullable' : 'required',
                Rule::exists('employees', 'id')->whereNull('archived_at'),
                Rule::unique('users', 'employee_id')->ignore($user?->id),
            ];
        }

        $data = $request->validate($rules, [
            'employee_id.unique' => 'This faculty is already linked to another account.',
        ]);

        if ($data['role'] === 'faculty') {
            unset($data['email']);
        }

        if ($data['role'] === 'faculty' && ! empty($data['employee_id'])) {
            $employee = Employee::findOrFail($data['employee_id']);
            if (User::where('email', $employee->email)->when($user, fn ($query) => $query->whereKeyNot($user->id))->exists()) {
                throw ValidationException::withMessages([
                    'employee_id' => 'This faculty email is already used by another account.',
                ]);
            }
            $data['name'] = implode(' ', array_filter([
                $employee->first_name,
                $employee->middle_name,
                $employee->last_name,
                $employee->suffix,
            ]));
            $data['email'] = strtolower($employee->email);
        } elseif ($data['role'] === 'faculty') {
            $data['employee_id'] = null;
            $data['name'] = $user?->name ?: 'Unlinked Faculty Account';
            $data['email'] = $user?->email;
        } else {
            $data['employee_id'] = null;
        }

        return $data;
    }
}
