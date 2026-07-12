<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Pekerja;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::with('pekerja.divisi', 'pekerja.jabatan');

        if ($request->filled('role') && $request->role !== 'all') {
            $query->where('role', $request->role);
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%')
                  ->orWhere('email', 'like', '%' . $request->search . '%');
            });
        }

        return response()->json($query->latest()->get());
    }

    public function edit(User $user)
    {
        return response()->json($user->load('pekerja.divisi', 'pekerja.jabatan'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|unique:users,email',
            'phone' => 'nullable|string|unique:users,phone',
            'role' => 'required|string|in:guest,karyawan,manajer,hr,admin',
            'nip' => 'required|string|unique:pekerja,nip',
            'divisi_id' => 'nullable|exists:divisi,id',
            'jabatan_id' => 'nullable|exists:jabatan,id',
            'tanggal_masuk' => 'nullable|date',
        ]);

        $plainPassword = Str::random(8);

        [$user, $pekerja] = DB::transaction(function () use ($validated, $plainPassword) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'password' => Hash::make($plainPassword),
                'role' => $validated['role'],
            ]);

            $pekerja = Pekerja::create([
                'user_id' => $user->id,
                'nip' => $validated['nip'],
                'divisi_id' => $validated['divisi_id'] ?? null,
                'jabatan_id' => $validated['jabatan_id'] ?? null,
                'qr_code' => Str::uuid()->toString(),
                'tanggal_masuk' => $validated['tanggal_masuk'] ?? null,
            ]);

            return [$user, $pekerja];
        });

        return response()->json([
            'message' => 'Karyawan berhasil dibuat.',
            'user' => $user,
            'pekerja' => $pekerja->load('divisi', 'jabatan'),
            'password' => $plainPassword,
        ], 201);
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|unique:users,email,' . $user->id,
            'phone' => 'nullable|string|unique:users,phone,' . $user->id,
            'role' => 'required|string|in:guest,karyawan,manajer,hr,admin',
            'nip' => 'sometimes|required|string|unique:pekerja,nip,' . optional($user->pekerja)->id,
            'divisi_id' => 'nullable|exists:divisi,id',
            'jabatan_id' => 'nullable|exists:jabatan,id',
            'tanggal_masuk' => 'nullable|date',
        ]);

        $user->update(collect($validated)->only(['name', 'email', 'phone', 'role'])->toArray());

        if ($user->pekerja) {
            $user->pekerja->update(
                collect($validated)->only(['nip', 'divisi_id', 'jabatan_id', 'tanggal_masuk'])->toArray()
            );
        }

        return response()->json($user->load('pekerja.divisi', 'pekerja.jabatan'));
    }

    public function destroy(User $user)
    {
        $user->delete();

        return response()->json(['message' => 'User deleted successfully']);
    }
}