<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Imports\RoleImport;
use App\Models\MasterData\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class RoleController extends Controller
{
    // GET /api/role — daftar role + jumlah user yang pakai role itu,
    // paginated (default 10/halaman, bisa dicari lewat ?search=).
    public function index(Request $request)
    {
        $query = Role::withCount('users')->orderBy('level', 'desc')->orderBy('nama');

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('nama', 'like', '%' . $request->search . '%')
                  ->orWhere('label', 'like', '%' . $request->search . '%');
            });
        }

        $perPage = max(1, (int) $request->get('per_page', 10));

        return response()->json($query->paginate($perPage));
    }

    // POST /api/role
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nama' => 'required|string|max:50|alpha_dash|unique:roles,nama',
            'label' => 'nullable|string|max:100',
            'level' => 'required|integer|min:0|max:255',
        ], [
            'nama.required' => 'Kolom nama wajib diisi.',
            'nama.alpha_dash' => 'Nama role cuma boleh huruf, angka, strip, dan underscore (tanpa spasi) -- ini yang bakal dipakai di kolom role user.',
            'nama.unique' => 'Role dengan nama ini sudah ada.',
            'level.required' => 'Kolom level wajib diisi.',
            'level.integer' => 'Level harus berupa angka.',
        ]);

        $validated['nama'] = strtolower($validated['nama']);

        $role = Role::create($validated);
        $role->loadCount('users');
        User::clearRoleLevelCache();

        return response()->json($role, 201);
    }

    // PUT /api/role/{id}
    public function update(Request $request, $id)
    {
        $role = Role::withCount('users')->findOrFail($id);

        $validated = $request->validate([
            'nama' => 'sometimes|required|string|max:50|alpha_dash|unique:roles,nama,' . $role->id,
            'label' => 'nullable|string|max:100',
            'level' => 'sometimes|required|integer|min:0|max:255',
        ], [
            'nama.required' => 'Kolom nama wajib diisi.',
            'nama.alpha_dash' => 'Nama role cuma boleh huruf, angka, strip, dan underscore (tanpa spasi) -- ini yang bakal dipakai di kolom role user.',
            'nama.unique' => 'Role dengan nama ini sudah ada.',
            'level.integer' => 'Level harus berupa angka.',
        ]);

        // Ganti nama role yang masih dipakai user bikin user itu jadi
        // gak punya role yang valid lagi (role di kolom users.role gak
        // ikut keupdate) -- dicegah di sini, mirip pola guard hapus di
        // bawah. Admin harus pindahin user-nya dulu (lewat Data User) baru
        // boleh ganti nama.
        if (isset($validated['nama']) && $validated['nama'] !== $role->nama && $role->users_count > 0) {
            return response()->json([
                'message' => 'Role ini masih dipakai ' . $role->users_count . ' user. Pindahkan role user tersebut dulu sebelum mengganti nama role ini.',
            ], 422);
        }

        if (isset($validated['nama'])) {
            $validated['nama'] = strtolower($validated['nama']);
        }

        $role->update($validated);
        $role->loadCount('users');
        User::clearRoleLevelCache();

        return response()->json($role);
    }

    // DELETE /api/role/{id}
    public function destroy($id)
    {
        $role = Role::withCount('users')->findOrFail($id);

        if ($role->users_count > 0) {
            return response()->json([
                'message' => 'Role ini masih dipakai ' . $role->users_count . ' user. Pindahkan role user tersebut terlebih dahulu sebelum menghapus.',
            ], 422);
        }

        $role->delete();
        User::clearRoleLevelCache();

        return response()->json(['message' => 'Role berhasil dihapus.']);
    }

    /**
     * POST /api/role/import -- import massal data Role dari file Excel
     * (.xlsx/.xls). Format kolom: Nama | Label | Level. Baris dengan nama
     * yang sudah ada di-UPDATE (bukan dilewati) -- lihat RoleImport.
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:10240', // max 10MB
        ]);

        DB::beginTransaction();
        try {
            $import = new RoleImport();
            Excel::import($import, $request->file('file'));

            if (count($import->getErrors()) > 0) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'errors'  => $import->getErrors(),
                ], 422);
            }

            DB::commit();
            User::clearRoleLevelCache();
            return response()->json([
                'success' => true,
                'message' => "Berhasil import {$import->getCreatedCount()} role baru"
                    . ($import->getUpdatedCount() > 0 ? ", {$import->getUpdatedCount()} diperbarui" : ''),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal import: ' . $e->getMessage(),
            ], 422);
        }
    }
}