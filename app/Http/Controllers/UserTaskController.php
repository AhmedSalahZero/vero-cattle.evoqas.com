<?php

namespace App\Http\Controllers;

use App\Models\UserTask;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Carbon\Carbon;

class UserTaskController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // INDEX — main tasks page
    // ─────────────────────────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $user = Auth::user();

        $tasks = UserTask::where('user_id', $user->id)
            ->with('company:id,name_en,name_ar')
            ->orderByRaw("FIELD(status,'not_started','in_progress','completed','cancelled')")
            ->orderByRaw("FIELD(priority,'high','medium','low')")
            ->orderBy('expected_end_date')
            ->get()
            ->map(fn($t) => $this->formatTask($t));

        // All companies for the "related company" dropdown
        $companies = Company::orderBy('name_en')->get(['id', 'name_en', 'name_ar']);

        // Summary counts
        $counts = [
            'total'       => $tasks->count(),
            'not_started' => $tasks->where('status', 'not_started')->count(),
            'in_progress' => $tasks->where('status', 'in_progress')->count(),
            'completed'   => $tasks->where('status', 'completed')->count(),
            'overdue'     => $tasks->where('is_overdue', true)->count(),
            'due_today'   => $tasks->where('is_due_today', true)->count(),
        ];

        return Inertia::render('Tasks/Index', [
            'tasks'     => $tasks,
            'companies' => $companies,
            'counts'    => $counts,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // STORE — create new task
    // ─────────────────────────────────────────────────────────────────────────
    public function store(Request $request)
    {
        $user = Auth::user();

        $data = $request->validate([
            'title'                    => 'required|string|max:255',
            'description'              => 'nullable|string',
            'priority'                 => 'required|in:low,medium,high',
            'status'                   => 'required|in:not_started,in_progress,completed,cancelled',
            'company_id'               => 'nullable|integer',
            'expected_start_date'      => 'nullable|date',
            'expected_duration_days'   => 'nullable|integer|min:1',
            'expected_end_date'        => 'nullable|date',
            'actual_start_date'        => 'nullable|date',
            'actual_duration_days'     => 'nullable|integer|min:1',
            'actual_end_date'          => 'nullable|date',
            'reminder_enabled'         => 'boolean',
            'completion_notes'         => 'nullable|string',
        ]);

        // Auto-compute expected_end_date if start + duration given but no end
        if (!empty($data['expected_start_date']) && !empty($data['expected_duration_days']) && empty($data['expected_end_date'])) {
            $data['expected_end_date'] = Carbon::parse($data['expected_start_date'])
                ->addDays($data['expected_duration_days'] - 1)
                ->toDateString();
        }

        // Same for actual
        if (!empty($data['actual_start_date']) && !empty($data['actual_duration_days']) && empty($data['actual_end_date'])) {
            $data['actual_end_date'] = Carbon::parse($data['actual_start_date'])
                ->addDays($data['actual_duration_days'] - 1)
                ->toDateString();
        }

        $task = UserTask::create([
            ...$data,
            'user_id' => $user->id,
        ]);

        return response()->json(['success' => true, 'task' => $this->formatTask($task->load('company:id,name_en,name_ar'))]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // UPDATE — edit task
    // ─────────────────────────────────────────────────────────────────────────
    public function update(Request $request, UserTask $task)
    {
        abort_if($task->user_id !== Auth::id(), 403);

        $data = $request->validate([
            'title'                    => 'required|string|max:255',
            'description'              => 'nullable|string',
            'priority'                 => 'required|in:low,medium,high',
            'status'                   => 'required|in:not_started,in_progress,completed,cancelled',
            'company_id'               => 'nullable|integer',
            'expected_start_date'      => 'nullable|date',
            'expected_duration_days'   => 'nullable|integer|min:1',
            'expected_end_date'        => 'nullable|date',
            'actual_start_date'        => 'nullable|date',
            'actual_duration_days'     => 'nullable|integer|min:1',
            'actual_end_date'          => 'nullable|date',
            'reminder_enabled'         => 'boolean',
            'completion_notes'         => 'nullable|string',
        ]);

        // Auto-compute end dates
        if (!empty($data['expected_start_date']) && !empty($data['expected_duration_days']) && empty($data['expected_end_date'])) {
            $data['expected_end_date'] = Carbon::parse($data['expected_start_date'])
                ->addDays($data['expected_duration_days'] - 1)
                ->toDateString();
        }
        if (!empty($data['actual_start_date']) && !empty($data['actual_duration_days']) && empty($data['actual_end_date'])) {
            $data['actual_end_date'] = Carbon::parse($data['actual_start_date'])
                ->addDays($data['actual_duration_days'] - 1)
                ->toDateString();
        }

        $task->update($data);

        return response()->json(['success' => true, 'task' => $this->formatTask($task->fresh()->load('company:id,name_en,name_ar'))]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // QUICK STATUS — patch just the status (for kanban-style toggle)
    // ─────────────────────────────────────────────────────────────────────────
    public function updateStatus(Request $request, UserTask $task)
    {
        abort_if($task->user_id !== Auth::id(), 403);
        $request->validate(['status' => 'required|in:not_started,in_progress,completed,cancelled']);
        $task->update(['status' => $request->status]);
        return response()->json(['success' => true]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DESTROY — delete task
    // ─────────────────────────────────────────────────────────────────────────
    public function destroy(UserTask $task)
    {
        abort_if($task->user_id !== Auth::id(), 403);
        $task->delete();
        return response()->json(['success' => true]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // BADGE COUNT — used by nav to show overdue+due-today count
    // ─────────────────────────────────────────────────────────────────────────
    public function badgeCount()
    {
        $user  = Auth::user();
        $today = now()->toDateString();

        $count = UserTask::where('user_id', $user->id)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->where(function ($q) use ($today) {
                $q->where('expected_end_date', '<=', $today);
            })
            ->count();

        return response()->json(['count' => $count]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE: format task for frontend
    // ─────────────────────────────────────────────────────────────────────────
    private function formatTask(UserTask $task): array
    {
        return [
            'id'                      => $task->id,
            'title'                   => $task->title,
            'description'             => $task->description,
            'priority'                => $task->priority,
            'status'                  => $task->status,
            'company_id'              => $task->company_id,
            'company_name_en'         => $task->company?->name_en,
            'company_name_ar'         => $task->company?->name_ar,
            'expected_start_date'     => $task->expected_start_date?->toDateString(),
            'expected_duration_days'  => $task->expected_duration_days,
            'expected_end_date'       => $task->expected_end_date?->toDateString(),
            'actual_start_date'       => $task->actual_start_date?->toDateString(),
            'actual_duration_days'    => $task->actual_duration_days,
            'actual_end_date'         => $task->actual_end_date?->toDateString(),
            'reminder_enabled'        => $task->reminder_enabled,
            'completion_notes'        => $task->completion_notes,
            'is_overdue'              => $task->is_overdue,
            'is_due_today'            => $task->is_due_today,
            'delay_days'              => $task->delay_days,
            'created_at'              => $task->created_at->toDateString(),
        ];
    }
}