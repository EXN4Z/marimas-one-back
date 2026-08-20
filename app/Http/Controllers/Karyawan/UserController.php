<?php

namespace App\Http\Controllers\Karyawan;

use App\Http\Controllers\Controller;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::with('departemen', 'lokasiKantor');

        // BARU: kalau yang akses akun cabang, cuma tampilin karyawan yang
        // lokasi_kantor_id-nya sama dengan lokasi_kantor_id akun cabang tsb.
        // Filter langsung di kolom users.lokasi_kantor_id (dulu lewat
        // whereHas('pekerja', ...), sekarang gak ada lagi tabel pekerja).
        $user = Auth::user();
        if ($user && $user->role === 'cabang' && $user->lokasi_kantor_id) {
            $query->where('lokasi_kantor_id', $user->lokasi_kantor_id)
                  ->where('role', '!=', 'cabang');
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
        return response()->json($user->load('departemen', 'lokasiKantor'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|unique:users,email',
            'phone' => 'nullable|string|unique:users,phone',
            'password' => 'required|string',
            'role' => 'required|string|in:guest,karyawan,manajer,hr,admin,cabang',
            'nik' => 'required_unless:role,cabang|nullable|string|unique:users,nik',
            'departemen_id' => 'nullable|exists:departemen,id',
            // UBAH: wajib diisi kalau role cabang, biar gak lolos dengan null lagi.
            'lokasi_kantor_id' => 'required_if:role,cabang|nullable|exists:lokasi_kantor,id',
            'tanggal_masuk' => 'nullable|date',
        ]);

        // BARU: password default dibuat dari nama user (huruf kecil, spasi
        // diganti underscore), bukan random lagi.
        $plainPassword = User::generatePasswordFromName($validated['name']);
        $isCabang = $validated['role'] === 'cabang';

        // BARU: gak ada lagi tabel pekerja terpisah — semua kolom karyawan
        // (nik, departemen_id, tanggal_masuk) langsung masuk ke users dalam
        // satu insert. Akun cabang gak punya data karyawan sama sekali
        // (nik/departemen_id/tanggal_masuk dibiarkan null).
        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'lokasi_kantor_id' => $isCabang ? $validated['lokasi_kantor_id'] : ($validated['lokasi_kantor_id'] ?? null),
            'nik' => $isCabang ? null : $validated['nik'],
            'departemen_id' => $isCabang ? null : ($validated['departemen_id'] ?? null),
            'tanggal_masuk' => $isCabang ? null : ($validated['tanggal_masuk'] ?? null),
        ]);

        return response()->json([
            'message' => 'User berhasil dibuat.',
            'user' => $user->load('departemen', 'lokasiKantor'),
        ], 201);
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|unique:users,email,' . $user->id,
            'phone' => 'nullable|string|unique:users,phone,' . $user->id,
            'role' => 'required|string|in:guest,karyawan,manajer,hr,admin,cabang',
            'nik' => 'required_unless:role,cabang|nullable|string|unique:users,nik,' . $user->id,
            'departemen_id' => 'nullable|exists:departemen,id',
            'lokasi_kantor_id' => 'required_if:role,cabang|nullable|exists:lokasi_kantor,id',
            'tanggal_masuk' => 'nullable|date',
        ]);

        $isCabang = $validated['role'] === 'cabang';

        // BARU: satu update langsung ke users, gak ada lagi percabangan
        // Pekerja::create/update/delete. Kalau role diganti jadi cabang,
        // kolom karyawan (nik/departemen_id/tanggal_masuk) di-null-kan —
        // analog sama dulu ngehapus row pekerja-nya.
        $user->update([
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'role' => $validated['role'],
            'lokasi_kantor_id' => $isCabang ? $validated['lokasi_kantor_id'] : ($validated['lokasi_kantor_id'] ?? null),
            'nik' => $isCabang ? null : $validated['nik'],
            'departemen_id' => $isCabang ? null : ($validated['departemen_id'] ?? null),
            'tanggal_masuk' => $isCabang ? null : ($validated['tanggal_masuk'] ?? null),
        ]);

        return response()->json($user->load('departemen', 'lokasiKantor'));
    }

    public function destroy(User $user)
    {
        $user->delete();

        return response()->json(['message' => 'User deleted successfully']);
    }
}