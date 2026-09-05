<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Read-only view over the append-only audit trail. There is deliberately no
 * create/edit/delete path -- an audit row is never written from a screen.
 */
class AuditEventController extends Controller
{
    public function index(Request $request): View
    {
        abort_if(! auth()->user()->can('view_audit_events'), 403);

        $query = AuditEvent::with('actor')->latest('occurred_at');

        if ($action = $request->get('action')) {
            $query->where('action', 'like', "%{$action}%");
        }

        if ($result = $request->get('result')) {
            $query->where('result', $result);
        }

        if ($correlationId = $request->get('correlation_id')) {
            $query->where('correlation_id', $correlationId);
        }

        if ($resourceType = $request->get('resource_type')) {
            $query->where('resource_type', $resourceType);
        }

        return view('admin.audit-events.index', [
            'events' => $query->paginate(50)->withQueryString(),
            'resourceTypes' => AuditEvent::whereNotNull('resource_type')
                ->distinct()->orderBy('resource_type')->pluck('resource_type'),
        ]);
    }
}
