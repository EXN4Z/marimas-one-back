<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;

class AdminUserController extends Controller
{
    // POST /api/admin/users/{id}/reset-password
    public function resetPassword(Request $request, int $id)
    {
        // BARU: pastikan cuma admin yang bisa akses (double-check di route middleware juga)
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Tidak diizinkan.'], 403);
        }

        $user = User::findOrFail($id);
        $newPassword = Str::random(10); // BARU: password baru, human-typeable

        $user->update([
            'password' => Hash::make($newPassword),
        ]);

        return response()->json([
            'message' => 'Password berhasil direset.',
            'user' => $user->only(['id', 'name']),
            'new_password' => $newPassword, // BARU: cuma muncul SEKALI di response ini, nggak disimpan plaintext di DB
        ]);
    }
}