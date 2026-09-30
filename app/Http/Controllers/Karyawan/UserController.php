<?php

namespace App\Http\Controllers\Karyawan;

use App\Http\Controllers\Controller;

use App\Models\Transaksi\InventoryPemakai;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index(Request $request)
    {
        // BARU: eager-load relasi role juga, biar frontend (TabKaryawan.tsx)
        // bisa nampilin nama/warna role tanpa request tambahan.
        $query = User::with('departemen', 'lokasiKantor', 'roleRef');

        // Role 'cabang' sekarang diperlakukan identik dengan 'user' --
        // gak ada lagi scoping/filter khusus berdasarkan lokasi_kantor_id
        // akun yang login (lihat catatan di User::isAdmin() -- akses cuma
        // 2 tingkat: admin vs role lain, semuanya setara).
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
        return response()->json($user->load('departemen', 'lokasiKantor', 'roleRef', 'perusahaan'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|unique:users,email',
            'phone' => 'nullable|string|unique:users,phone',
            'password' => 'required|string',
            'role_id' => 'required|integer|exists:roles,id',
            'nik' => 'nullable|string|unique:users,nik',
            'departemen_id' => 'nullable|exists:departemen,id',
            'lokasi_kantor_id' => 'nullable|exists:lokasi_kantor,id',
            'tanggal_masuk' => 'nullable|date',
            'perusahaan_id' => 'nullable|exists:perusahaan,id',
            'status' => 'sometimes|in:aktif,nonaktif',
        ]);

        $role = \App\Models\MasterData\Role::findOrFail($validated['role_id']);
        if ($role->nama === 'cabang') {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => ['role_id' => ['Role cabang sudah tidak digunakan.']],
            ], 422);
        }

        if (empty($validated['nik'])) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => ['nik' => ['NIK karyawan wajib diisi.']],
            ], 422);
        }
        if (User::where('nik', $validated['nik'])->exists()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => ['nik' => ['NIK sudah digunakan.']],
            ], 422);
        }

        $plainPassword = User::generatePasswordFromName($validated['name']);

       $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'password' => Hash::make($validated['password']),
            'role_id' => $validated['role_id'],
            'lokasi_kantor_id' => $validated['lokasi_kantor_id'] ?? null,
            'nik' => $validated['nik'],
            'departemen_id' => $validated['departemen_id'] ?? null,
            'perusahaan_id' => $validated['perusahaan_id'] ?? null,
            'tanggal_masuk' => $validated['tanggal_masuk'] ?? null,
            'status' => $validated['status'] ?? 'aktif',
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
            'role_id' => 'required|integer|exists:roles,id', // GANTI
            'nik' => 'nullable|string|unique:users,nik,' . $user->id,
            'departemen_id' => 'nullable|exists:departemen,id',
            'perusahaan_id' => 'nullable|exists:perusahaan,id',
            'lokasi_kantor_id' => 'nullable|exists:lokasi_kantor,id',
            'tanggal_masuk' => 'nullable|date',
            'status' => 'sometimes|in:aktif,nonaktif',
        ]);

        $role = \App\Models\MasterData\Role::findOrFail($validated['role_id']);
        if ($role->nama === 'cabang') {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => ['role_id' => ['Role cabang sudah tidak digunakan.']],
            ], 422);
        }

        if (empty($validated['nik'])) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => ['nik' => ['NIK karyawan wajib diisi.']],
            ], 422);
        }

        // Nonaktifkan akun cuma boleh kalau user gak lagi punya pinjaman
        // inventory yang aktif (disetujui/pending & belum dikembalikan).
        $mauNonaktif = ($validated['status'] ?? $user->status) === 'nonaktif'
            && $user->status !== 'nonaktif';

        if ($mauNonaktif && ($blok = $this->pinjamanAktifResponse($user, 'menonaktifkan'))) {
            return $blok;
        }

        $user->update([
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'role_id' => $validated['role_id'],
            'lokasi_kantor_id' => $validated['lokasi_kantor_id'] ?? null,
            'nik' => $validated['nik'],
            'departemen_id' => $validated['departemen_id'] ?? null,
            'perusahaan_id' => $validated['perusahaan_id'] ?? null,
            'tanggal_masuk' => $validated['tanggal_masuk'] ?? null,
            'status' => $validated['status'] ?? $user->status,
        ]);

        // Akun baru saja dinonaktifkan -- cabut semua sesi login-nya.
        if ($mauNonaktif) {
            $user->tokens()->delete();
        }

        return response()->json($user->load('departemen', 'lokasiKantor', 'roleRef'));
    }

    public function destroy(User $user)
    {
        if ($blok = $this->pinjamanAktifResponse($user, 'menghapus akun')) {
            return $blok;
        }

        $user->delete();

        return response()->json(['message' => 'User deleted successfully']);
    }

    /**
     * Balikin response 422 kalau user masih punya pinjaman inventory aktif
     * (status disetujui/pending dan belum ada tanggal_pengembalian),
     * atau null kalau aman. Format error pakai key `status` biar langsung
     * muncul di field "Status Akun" di KaryawanEdit.tsx.
     */
    private function pinjamanAktifResponse(User $user, string $aksi)
    {
        $pinjaman = InventoryPemakai::with('inventory:id,kode_inventory')
            ->where('user_id', $user->id)
            ->whereIn('status', ['disetujui', 'pending'])
            ->whereNull('tanggal_pengembalian')
            ->get();

        if ($pinjaman->isEmpty()) {
            return null;
        }

        $daftar = $pinjaman->map(fn ($p) => $p->inventory?->kode_inventory)
            ->filter()
            ->unique()
            ->implode(', ');

        $detail = $daftar !== '' ? " ($daftar)" : '';

        return response()->json([
            'message' => 'The given data was invalid.',
            'errors' => ['status' => [
                "Karyawan masih punya {$pinjaman->count()} pinjaman inventory{$detail}. Selesaikan/kembalikan dulu sebelum {$aksi}.",
            ]],
        ], 422);
    }
}