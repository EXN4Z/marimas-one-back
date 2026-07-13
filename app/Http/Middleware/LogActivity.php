<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;

class LogActivity
{
    // Route yang gak perlu dicatat, biar gak infinite logging & gak berisik
    protected array $excluded = [
        'api/audit-log',
        'api/audit-log/*',
        'api/login',
        'api/logout',
    ];

    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        $this->log($request);

        return $response;
    }

    protected function log(Request $request): void
    {
        foreach ($this->excluded as $pattern) {
            if ($request->is($pattern)) {
                return;
            }
        }

        AuditLog::create([
            'user_id' => $request->user()?->id,
            'method' => $request->method(),
            'endpoint' => $request->path(),
            'deskripsi' => $this->buatDeskripsi($request),
            'ip_address' => $request->ip(),
        ]);
    }

    protected function buatDeskripsi(Request $request): string
    {
        $nama = $request->user()?->name ?? 'Guest';
        $method = $request->method();
        $path = $request->path();

        return match ($method) {
            'GET' => "{$nama} melihat data di /{$path}",
            'POST' => "{$nama} membuat data baru di /{$path}",
            'PUT', 'PATCH' => "{$nama} mengubah data di /{$path}",
            'DELETE' => "{$nama} menghapus data di /{$path}",
            default => "{$nama} mengakses /{$path}",
        };
    }
}