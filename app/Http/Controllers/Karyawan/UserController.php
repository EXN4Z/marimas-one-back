<?php

namespace App\Http\Controllers\Karyawan;

use App\Http\Controllers\Controller;

use App\Models\User;
use App\Models\Pekerja;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::with('pekerja.departemen', 'pekerja.jabatan', 'pekerja.lokasiKantor');

        // BARU: kalau yang akses akun cabang, cuma tampilin karyawan yang
        // lokasi_kantor_id-nya sama dengan lokasi_kantor_id akun cabang tsb.
        $user = Auth::user();
        if ($user && $user->role === 'cabang' && $user->lokasi_kantor_id) {
            $query->whereHas('pekerja', function ($q) use ($user) {
                $q->where('lokasi_kantor_id', $user->lokasi_kantor_id);
            });
        }

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
        return response()->json($user->load('pekerja.departemen', 'pekerja.jabatan', 'pekerja.lokasiKantor'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|unique:users,email',
            'phone' => 'nullable|string|unique:users,phone',
            'role' => 'required|string|in:guest,karyawan,manajer,hr,admin,cabang',
            'nik' => 'required_unless:role,cabang|nullable|string|unique:pekerja,nik',
            'departemen_id' => 'nullable|exists:departemen,id',
            'jabatan_id' => 'nullable|exists:jabatan,id',
            // UBAH: wajib diisi kalau role cabang, biar gak lolos dengan null lagi.
            'lokasi_kantor_id' => 'required_if:role,cabang|nullable|exists:lokasi_kantor,id',
            'tanggal_masuk' => 'nullable|date',
        ]);

        $plainPassword = Str::random(8);

        $result = DB::transaction(function () use ($validated, $plainPassword) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'password' => Hash::make($plainPassword),
                'role' => $validated['role'],
                // BARU: akun cabang butuh lokasi_kantor_id di tabel users
                // sendiri, karena dia gak punya baris di tabel pekerja.
                'lokasi_kantor_id' => $validated['role'] === 'cabang'
                    ? $validated['lokasi_kantor_id']
                    : null,
            ]);

            $pekerja = null;

            if ($validated['role'] !== 'cabang') {
                $pekerja = Pekerja::create([
                    'user_id' => $user->id,
                    'nik' => $validated['nik'],
                    'departemen_id' => $validated['departemen_id'] ?? null,
                    'jabatan_id' => $validated['jabatan_id'] ?? null,
                    'qr_code' => Str::uuid()->toString(),
                    'lokasi_kantor_id' => $validated['lokasi_kantor_id'] ?? null,
                    'tanggal_masuk' => $validated['tanggal_masuk'] ?? null,
                ]);
            }

            return [$user, $pekerja];
        });

        [$user, $pekerja] = $result;

        return response()->json([
            'message' => 'User berhasil dibuat.',
            'user' => $user,
            'pekerja' => $pekerja?->load('departemen', 'jabatan'),
            'password' => $plainPassword,
        ], 201);
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|unique:users,email,' . $user->id,
            'phone' => 'nullable|string|unique:users,phone,' . $user->id,
            'role' => 'required|string|in:guest,karyawan,manajer,hr,admin,cabang',
            'nik' => 'required_unless:role,cabang|nullable|string|unique:pekerja,nik,' . optional($user->pekerja)->id,
            'departemen_id' => 'nullable|exists:departemen,id',
            'jabatan_id' => 'nullable|exists:jabatan,id',
            'lokasi_kantor_id' => 'required_if:role,cabang|nullable|exists:lokasi_kantor,id',
            'tanggal_masuk' => 'nullable|date',
        ]);

        $user->update(collect($validated)->only(['name', 'email', 'phone', 'role'])->toArray());

        // BARU: sync lokasi_kantor_id di tabel users -- cuma relevan/dipakai
        // buat role cabang, role lain di-null-kan biar gak nyangkut data lama.
        $user->lokasi_kantor_id = $validated['role'] === 'cabang'
            ? $validated['lokasi_kantor_id']
            : null;
        $user->save();

        if ($validated['role'] === 'cabang') {
            $user->pekerja()?->delete();
        } elseif ($user->pekerja) {
            $user->pekerja->update(
                collect($validated)->only(['nik', 'departemen_id', 'jabatan_id', 'tanggal_masuk', 'lokasi_kantor_id'])->toArray()
            );
        } else {
            Pekerja::create([
                'user_id' => $user->id,
                'nik' => $validated['nik'],
                'departemen_id' => $validated['departemen_id'] ?? null,
                'jabatan_id' => $validated['jabatan_id'] ?? null,
                'qr_code' => Str::uuid()->toString(),
                'lokasi_kantor_id' => $validated['lokasi_kantor_id'] ?? null,
                'tanggal_masuk' => $validated['tanggal_masuk'] ?? null,
            ]);
        }

        return response()->json($user->load('pekerja.departemen', 'pekerja.jabatan', 'pekerja.lokasiKantor'));
    }

    public function destroy(User $user)
    {
        $user->delete();

        return response()->json(['message' => 'User deleted successfully']);
    }
}