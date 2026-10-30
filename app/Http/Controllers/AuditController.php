<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'event', 'user_id', 'from', 'to']);

        $logs = AuditLog::with('user')
            ->when(! $request->user()->can('salaries.view'), function ($query) {
                $query->where('event', 'not like', 'payroll%')->where('event', 'not like', 'salary%')
                    ->where(fn ($query) => $query->whereNull('auditable_type')->orWhere('auditable_type', '!=', \App\Models\Expense::class)->orWhereNotIn('auditable_id', \Illuminate\Support\Facades\DB::table('salary_payments')->select('expense_id')));
            })
            ->when($filters['search'] ?? null, function ($query, string $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('description', 'like', "%{$search}%")
                        ->orWhere('event', 'like', "%{$search}%")
                        ->orWhere('auditable_type', 'like', "%{$search}%");
                });
            })
            ->when($filters['event'] ?? null, fn ($query, $event) => $query->where('event', $event))
            ->when($filters['user_id'] ?? null, fn ($query, $id) => $query->where('user_id', $id))
            ->when($filters['from'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['to'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
            ->latest('id')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (AuditLog $log) => [
                'id' => $log->id,
                'event' => $log->event,
                'description' => $log->description,
                'user' => $log->user?->name ?? 'System',
                'auditable_type' => $log->auditable_type ? class_basename($log->auditable_type) : null,
                'auditable_id' => $log->auditable_id,
                'old_values' => $log->old_values,
                'new_values' => $log->new_values,
                'reason' => $log->reason,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at?->toDateTimeString(),
            ]);

        return Inertia::render('Audit/Index', [
            'logs' => $logs,
            'filters' => $filters,
            'events' => AuditLog::distinct()->orderBy('event')->pluck('event'),
            'users' => User::orderBy('name')->get(['id', 'name']),
        ]);
    }
}
