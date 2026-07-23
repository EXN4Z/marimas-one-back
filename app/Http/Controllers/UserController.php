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
        $query = User::with('pekerja.departemen', 'pekerja.jabatan', 'pekerja.lokasiKantor');

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
            'nip' => 'required_unless:role,cabang|nullable|string|unique:pekerja,nip',
            'departemen_id' => 'nullable|exists:departemen,id',
            'jabatan_id' => 'nullable|exists:jabatan,id',
            'lokasi_kantor_id' => 'nullable|exists:lokasi_kantor,id',
            'tanggal_masuk' => 'nullable|date',
            'kuota_izin_tahunan' => 'nullable|integer|min:0|max:365',
        ]);

        $plainPassword = Str::random(8);

        $result = DB::transaction(function () use ($validated, $plainPassword) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'password' => Hash::make($plainPassword),
                'role' => $validated['role'],
            ]);

            $pekerja = null;

<<<<<<< HEAD
            // akun cabang nggak punya profil pekerja
=======
            // akun cabang mewakili entitas cabang, bukan pegawai — nggak punya profil pekerja
>>>>>>> 3c98b01764fee6937e600bb8b6187bd05f5af980
            if ($validated['role'] !== 'cabang') {
                $pekerja = Pekerja::create([
                    'user_id' => $user->id,
                    'nip' => $validated['nip'],
                    'departemen_id' => $validated['departemen_id'] ?? null,
                    'jabatan_id' => $validated['jabatan_id'] ?? null,
                    'qr_code' => Str::uuid()->toString(),
                    'lokasi_kantor_id' => $validated['lokasi_kantor_id'] ?? null,
                    'tanggal_masuk' => $validated['tanggal_masuk'] ?? null,
                    'kuota_izin_tahunan' => $validated['kuota_izin_tahunan'] ?? 12,
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
            'nip' => 'required_unless:role,cabang|nullable|string|unique:pekerja,nip,' . optional($user->pekerja)->id,
            'departemen_id' => 'nullable|exists:departemen,id',
            'jabatan_id' => 'nullable|exists:jabatan,id',
            'lokasi_kantor_id' => 'nullable|exists:lokasi_kantor,id',
            'tanggal_masuk' => 'nullable|date',
            'kuota_izin_tahunan' => 'nullable|integer|min:0|max:365',
        ]);

        $user->update(collect($validated)->only(['name', 'email', 'phone', 'role'])->toArray());

        if ($validated['role'] === 'cabang') {
            // role diubah jadi cabang -> hapus profil pekerja lama (kalau ada)
            // ganti ke soft-delete / simpan histori dulu kalau nggak mau datanya hilang
            $user->pekerja()?->delete();
        } elseif ($user->pekerja) {
            $user->pekerja->update(
                collect($validated)->only(['nip', 'departemen_id', 'jabatan_id', 'tanggal_masuk', 'kuota_izin_tahunan', 'lokasi_kantor_id'])->toArray()
            );
        } else {
            // role diubah dari cabang -> jadi role pegawai, tapi belum punya record pekerja
            Pekerja::create([
                'user_id' => $user->id,
                'nip' => $validated['nip'],
                'departemen_id' => $validated['departemen_id'] ?? null,
                'jabatan_id' => $validated['jabatan_id'] ?? null,
                'qr_code' => Str::uuid()->toString(),
                'lokasi_kantor_id' => $validated['lokasi_kantor_id'] ?? null,
                'tanggal_masuk' => $validated['tanggal_masuk'] ?? null,
                'kuota_izin_tahunan' => $validated['kuota_izin_tahunan'] ?? 12,
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