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
     * REVISI (hapus level & label): dulu ini membandingkan LEVEL role
     * terendah yang ditulis di route (mis. 'role:admin,hr') ke level role
     * user (lewat hasRoleAtLeast()). Sekarang hierarki level dihapus --
     * cuma ada 2 tingkat: 'admin' (akses semua) vs role lain (semuanya
     * setara, kayak 'karyawan').
     *
     * Aturannya: kalau daftar role yang ditulis di route CUMA 'admin'
     * (mis. 'role:admin'), route itu admin-only. Kalau daftar rolenya ada
     * role lain selain 'admin' (mis. 'role:karyawan,manajer,hr,admin' atau
     * 'role:cabang,karyawan,manajer,hr,admin'), berarti route itu boleh
     * diakses semua user berrole valid -- karena semua role selain admin
     * sekarang setara, jadi nyebut salah satu role non-admin di daftar itu
     * otomatis nyebut semuanya. Route tanpa daftar role sama sekali
     * (mis. cuma 'role') tetap default admin-only, sama kayak sebelumnya.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Akses ditolak'], 403);
        }

        $adaRoleNonAdmin = collect($roles)->contains(fn (string $role) => $role !== 'admin');

        if (!$adaRoleNonAdmin && !$user->isAdmin()) {
            return response()->json(['message' => 'Akses ditolak'], 403);
        }

        return $next($request);
    }
}