<?php

namespace App\Http\Controllers\Karyawan;

use App\Http\Controllers\Controller;

use App\Models\User;
use App\Models\MasterData\Role; // BARU: dibutuhkan buat lookup nama role -> id di store()/update()
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;

class UserController extends Controller
{
    public function index(Request $request)
    {
        // BARU: eager-load relasi role juga, biar frontend (TabKaryawan.tsx)
        // bisa nampilin nama/warna role tanpa request tambahan.
        $query = User::with('departemen', 'lokasiKantor', 'roleRef');

        // BARU: cek role akun cabang sekarang lewat relasi roleRef->nama,
        // bukan $user->role lagi (kolom itu sudah nggak ada -- dulu diam-diam
        // selalu null & bikin kondisi ini nggak pernah kepakai).
        $user = Auth::user();
        if ($user && $user->roleRef?->nama === 'cabang' && $user->lokasi_kantor_id) {
            $query->where('lokasi_kantor_id', $user->lokasi_kantor_id)
                  ->whereHas('roleRef', fn ($q) => $q->where('nama', '!=', 'cabang'));
        }

        if ($request->filled('role') && $request->role !== 'all') {
            $query->whereHas('roleRef', fn ($q) => $q->where('nama', $request->role));
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
        return response()->json($user->load('departemen', 'lokasiKantor', 'roleRef'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|unique:users,email',
            'phone' => 'nullable|string|unique:users,phone',
            'password' => 'required|string',
            // BARU: validasi sekarang langsung ke role_id integer, sinkron
            // sama CreateKaryawanPage.tsx yang sudah ngirim role_id, bukan
            // nama role lagi.
            'role_id' => 'required|integer|exists:roles,id',
            'nik' => 'nullable|string|unique:users,nik',
            'departemen_id' => 'nullable|exists:departemen,id',
            'lokasi_kantor_id' => 'nullable|exists:lokasi_kantor,id',
            'tanggal_masuk' => 'nullable|date',
        ]);

        // BARU: ambil nama role dari id yang dikirim, dipakai buat nentuin
        // apakah ini akun cabang (masih perlu tau NAMA-nya buat cabang-specific
        // logic di bawah, walau yang disimpan ke DB tetap role_id).
        $role = Role::findOrFail($validated['role_id']);
        $isCabang = $role->nama === 'cabang';

        // BARU: validasi kondisional (nik wajib kecuali cabang, lokasi_kantor_id
        // wajib kalau cabang) sekarang dicek manual di sini -- dulu bisa pakai
        // required_unless:role,cabang / required_if:role,cabang karena field-nya
        // masih 'role' (nama string), tapi sekarang field yang dikirim 'role_id'
        // (angka), jadi rule itu nggak bisa lagi bandingin ke literal 'cabang'.
        if (!$isCabang && empty($validated['nik'])) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => ['nik' => ['NIK karyawan wajib diisi.']],
            ], 422);
        }
        if ($isCabang && empty($validated['lokasi_kantor_id'])) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => ['lokasi_kantor_id' => ['Cabang penempatan wajib dipilih.']],
            ], 422);
        }
        if (!$isCabang && !empty($validated['nik'])) {
            // unique check manual karena rule unique: di atas gak jalan buat nik kosong/cabang
            if (User::where('nik', $validated['nik'])->exists()) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => ['nik' => ['NIK sudah digunakan.']],
                ], 422);
            }
        }

        $plainPassword = User::generatePasswordFromName($validated['name']);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'password' => Hash::make($validated['password']),
            'role_id' => $validated['role_id'], // BARU: ganti dari 'role' => nama
            'lokasi_kantor_id' => $isCabang ? $validated['lokasi_kantor_id'] : ($validated['lokasi_kantor_id'] ?? null),
            'nik' => $isCabang ? null : $validated['nik'],
            'departemen_id' => $isCabang ? null : ($validated['departemen_id'] ?? null),
            'tanggal_masuk' => $isCabang ? null : ($validated['tanggal_masuk'] ?? null),
        ]);

        return response()->json([
            'message' => 'User berhasil dibuat.',
            'user' => $user->load('departemen', 'lokasiKantor', 'roleRef'),
        ], 201);
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|unique:users,email,' . $user->id,
            'phone' => 'nullable|string|unique:users,phone,' . $user->id,
            // BARU: sama seperti store() -- role_id, bukan nama role.
            'role_id' => 'required|integer|exists:roles,id',
            'nik' => 'nullable|string|unique:users,nik,' . $user->id,
            'departemen_id' => 'nullable|exists:departemen,id',
            'lokasi_kantor_id' => 'nullable|exists:lokasi_kantor,id',
            'tanggal_masuk' => 'nullable|date',
        ]);

        $role = Role::findOrFail($validated['role_id']);
        $isCabang = $role->nama === 'cabang';

        if (!$isCabang && empty($validated['nik'])) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => ['nik' => ['NIK karyawan wajib diisi.']],
            ], 422);
        }
        if ($isCabang && empty($validated['lokasi_kantor_id'])) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => ['lokasi_kantor_id' => ['Cabang penempatan wajib dipilih.']],
            ], 422);
        }

        $user->update([
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'role_id' => $validated['role_id'], // BARU: ganti dari 'role' => nama
            'lokasi_kantor_id' => $isCabang ? $validated['lokasi_kantor_id'] : ($validated['lokasi_kantor_id'] ?? null),
            'nik' => $isCabang ? null : $validated['nik'],
            'departemen_id' => $isCabang ? null : ($validated['departemen_id'] ?? null),
            'tanggal_masuk' => $isCabang ? null : ($validated['tanggal_masuk'] ?? null),
        ]);

        return response()->json($user->load('departemen', 'lokasiKantor', 'roleRef'));
    }

    public function destroy(User $user)
    {
        $user->delete();

        return response()->json(['message' => 'User deleted successfully']);
    }
}