<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;

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

        if ($request->filled('search')) {
            $this->applySearch($query, $request->search);
        }

        $perPage = $request->get('per_page', 20);

        return response()->json($query->paginate($perPage));
    }

    // GET /api/audit-log/trash — log yang sudah di-trash
    public function trash(Request $request)
    {
        $query = AuditLog::onlyTrashed()
            ->with('user:id,name')
            ->latest('deleted_at');

        if ($request->filled('search')) {
            $this->applySearch($query, $request->search);
        }

        $perPage = $request->get('per_page', 20);

        return response()->json($query->paginate($perPage));
    }

    // Cari di deskripsi, endpoint, ip_address, dan nama user terkait.
    private function applySearch(Builder $query, string $search): void
    {
        $query->where(function (Builder $q) use ($search) {
            $q->where('deskripsi', 'like', "%{$search}%")
                ->orWhere('endpoint', 'like', "%{$search}%")
                ->orWhere('ip_address', 'like', "%{$search}%")
                ->orWhereHas('user', function (Builder $userQuery) use ($search) {
                    $userQuery->where('name', 'like', "%{$search}%");
                });
        });
    }
}