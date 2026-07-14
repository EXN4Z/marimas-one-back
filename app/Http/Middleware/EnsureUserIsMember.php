<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsMember
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        // Ambil role dengan level TERENDAH dari daftar yang ditulis di route,
        // supaya 'role:admin,hr' dan 'role:hr,admin' hasilnya selalu sama
        // (keduanya berarti "hr ke atas boleh akses").
        $minRole = collect($roles)->sortBy(fn (string $role) => \App\Models\User::roleLevel($role))->first();

        if (!$request->user() || !$request->user()->hasRoleAtLeast($minRole ?? 'admin')) {
            return response()->json(['message' => 'Akses ditolak'], 403);
        }

        return $next($request);
    }
}
