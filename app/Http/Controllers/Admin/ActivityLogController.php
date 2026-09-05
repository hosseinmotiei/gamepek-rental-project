<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ActivityLogController extends Controller
{
    public function index(Request $request): View
    {
        abort_if(! auth()->user()->can('view_activity_logs'), 403);

        $query = ActivityLog::with('admin')->latest();

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('action', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('admin', fn ($u) => $u->where('full_name', 'like', "%{$search}%")->orWhere('mobile', 'like', "%{$search}%"));
            });
        }

        if ($adminId = $request->get('admin_id')) {
            $query->where('admin_id', $adminId);
        }

        if ($action = $request->get('action')) {
            $query->where('action', 'like', "%{$action}%");
        }

        if ($subjectType = $request->get('subject_type')) {
            $query->where('subject_type', $subjectType);
        }

        if ($from = $request->get('date_from')) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->get('date_to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        $logs = $query->paginate(25)->withQueryString();

        $admins = User::whereHas('roles')->orderBy('full_name')->get(['id', 'full_name', 'mobile']);

        $subjectTypes = ActivityLog::whereNotNull('subject_type')
            ->select('subject_type')->distinct()->pluck('subject_type');

        return view('admin.activity-logs.index', compact('logs', 'admins', 'subjectTypes'));
    }

    public function show(ActivityLog $activityLog): View
    {
        abort_if(! auth()->user()->can('view_activity_logs'), 403);
        $activityLog->load('admin');

        return view('admin.activity-logs.show', compact('activityLog'));
    }
}
