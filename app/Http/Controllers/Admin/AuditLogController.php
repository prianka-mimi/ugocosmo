<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;

class AuditLogController extends Controller
{
    public function index()
    {
        $filters = request()->only(['action', 'entity_type']);
        $query = AdminAuditLog::with('admin')->recent();

        if (! empty($filters['action'])) {
            $query->byAction($filters['action']);
        }
        if (! empty($filters['entity_type'])) {
            $query->byEntityType($filters['entity_type']);
        }

        $logs = $query->paginate(50);

        $actions = AdminAuditLog::select('action')->distinct()->pluck('action');
        $entityTypes = AdminAuditLog::select('entity_type')->distinct()->pluck('entity_type');

        return view('modules.admin.audit.index', compact('logs', 'actions', 'entityTypes'));
    }
}
