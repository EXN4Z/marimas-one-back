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

    // Cuma method yang benar-benar mengubah data yang dicatat. GET (buka
    // halaman/liat data/pindah tab) sengaja gak dicatat -- dulu tiap
    // navigasi/fetch list ikut nyatet, jadi log kebanjiran "melihat data"
    // dan menutupi aktivitas ubah data yang justru penting.
    protected array $loggedMethods = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        $this->log($request);

        return $response;
    }

    protected function log(Request $request): void
    {
        if (!in_array($request->method(), $this->loggedMethods, true)) {
            return;
        }

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
            'POST' => "{$nama} membuat data baru di /{$path}",
            'PUT', 'PATCH' => "{$nama} mengubah data di /{$path}",
            'DELETE' => "{$nama} menghapus data di /{$path}",
            default => "{$nama} mengakses /{$path}",
        };
    }
}