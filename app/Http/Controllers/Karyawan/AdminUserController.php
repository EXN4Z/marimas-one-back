<?php

namespace App\Http\Controllers\Karyawan;

use App\Http\Controllers\Controller;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminUserController extends Controller
{
    // POST /api/admin/users/{id}/set-password -- admin nentuin sendiri password baru.
    public function setPassword(Request $request, int $id)
    {
        // BARU: pastikan cuma admin yang bisa akses (double-check di route middleware juga)
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Tidak diizinkan.'], 403);
        }

        $validated = $request->validate([
            'password' => ['required', 'string', 'confirmed'],
        ]);

        $user = User::findOrFail($id);

        $user->update([
            'password' => Hash::make($validated['password']),
        ]);

        return response()->json([
            'message' => 'Password berhasil diubah.',
            'user' => $user->only(['id', 'name']),
        ]);
    }
}