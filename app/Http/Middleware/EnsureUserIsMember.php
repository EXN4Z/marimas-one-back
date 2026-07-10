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
    public function handle(Request $request, Closure $next, string $minRole): Response
    {
        if (!$request->user()->hasRoleAtLeast($minRole)) {
            return response()->json(['message' => 'Akses ditolak'], 403);
        }

        return $next($request);
    }
}
