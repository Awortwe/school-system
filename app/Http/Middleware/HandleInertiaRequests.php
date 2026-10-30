<?php

namespace App\Http\Middleware;

use App\Models\AcademicYear;
use App\Models\Setting;
use App\Models\Term;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $user = $request->user();

        $currentYear = null;
        $currentTerm = null;

        try {
            $currentYear = AcademicYear::where('is_current', true)->first(['id', 'name']);
            $currentTerm = Term::where('is_current', true)->first(['id', 'name', 'academic_year_id']);
        } catch (\Throwable) {
            //
        }

        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'employee_no' => $user->employee_no,
                    'phone' => $user->phone,
                    'photo_url' => $user->photo_path ? '/storage/'.ltrim($user->photo_path, '/') : null,
                    'is_active' => (bool) $user->is_active,
                    'must_change_password' => (bool) $user->must_change_password,
                    'roles' => $user->getRoleNames()->values()->all(),
                    'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
                    'department' => $user->department?->name,
                    'designation' => $user->designation?->name,
                ] : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
                'bulk_errors' => fn () => $request->session()->get('bulk_errors'),
            ],
            'school' => Setting::school(),
            'currentYear' => $currentYear,
            'currentTerm' => $currentTerm,
        ]);
    }
}
