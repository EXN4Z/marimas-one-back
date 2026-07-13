<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    // GET /api/audit-log — log aktif (belum di-trash)
    public function index(Request $request)
    {
        $query = AuditLog::with('user:id,name')->latest();

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('method')) {
            $query->where('method', $request->method);
        }

        $limit = $request->get('limit', 50);

        return response()->json($query->limit($limit)->get());
    }

    // GET /api/audit-log/trash — log yang sudah di-trash
    public function trash(Request $request)
    {
        $limit = $request->get('limit', 50);

        return response()->json(
            AuditLog::onlyTrashed()
                ->with('user:id,name')
                ->latest('deleted_at')
                ->limit($limit)
                ->get()
        );
    }
}