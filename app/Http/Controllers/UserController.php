<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'in:admin,payroll_staff,faculty'],
        ]);
        $users = User::with('employee')->latest();
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
            'employees' => Employee::orderBy('last_name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        User::create($this->validated($request));

        return redirect()->route('settings.accounts.index')->with('success', 'User account created.');
    }

    public function edit(User $user)
    {
        return Inertia::render('Users/Form', [
            'user' => $user,
            'employees' => Employee::orderBy('last_name')->get(),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $user->update($this->validated($request, $user));

        return redirect()->route('settings.accounts.index')->with('success', 'User account updated.');
    }

    public function destroy(User $user)
    {
        if (auth()->id() === $user->id) {
            return back()->withErrors(['user' => 'You cannot delete your own account while logged in.']);
        }

        $user->delete();

        return redirect()->route('settings.accounts.index')->with('success', 'User account deleted.');
    }

    private function validated(Request $request, ?User $user = null): array
    {
        $rules = [
            'name' => ['required', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user?->id)],
            'role' => ['required', 'in:admin,payroll_staff,faculty'],
            'employee_id' => ['nullable', Rule::exists('employees', 'id')->whereNull('archived_at')],
            'password' => [$user ? 'nullable' : 'required', 'min:6'],
        ];

        if ($request->role === 'faculty') {
            $rules['employee_id'] = ['required', Rule::exists('employees', 'id')->whereNull('archived_at')];
        }

        $data = $request->validate($rules);

        if ($data['role'] !== 'faculty') {
            $data['employee_id'] = null;
        }

        if (! empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        return $data;
    }
}
