<?php

namespace App\Http\Controllers\Organisasi;

use App\Http\Controllers\Controller;

use App\Imports\CabangImport;
use App\Models\LokasiKantor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class CabangController extends Controller
{
    // GET /api/cabang — daftar cabang + jumlah pegawai masing-masing
    public function index()
    {
        // BARU: withCount('karyawan') -- relasi baru yang udah filter
        // role != 'cabang' (lihat LokasiKantor::karyawan()), biar akun
        // cabang gak ikut kehitung sebagai pegawainya sendiri. Alias tetap
        // 'pekerja_count' biar frontend (CabangPage.tsx) gak perlu diubah.
        $cabang = LokasiKantor::withCount(['karyawan as pekerja_count'])
            ->orderBy('nama')
            ->get();

        return response()->json($cabang);
    }

    // GET /api/cabang/{id}
    public function show($id)
    {
        $cabang = LokasiKantor::withCount(['karyawan as pekerja_count'])->findOrFail($id);

        return response()->json($cabang);
    }

    // POST /api/cabang
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nama' => 'required|string|max:150',
            'alamat' => 'required|string|max:1000',
            'telepon' => 'required|string|max:30',
            'link' => 'required|string|max:255',
        ], [
            'nama.required' => 'Kolom nama wajib diisi.',
            'alamat.required' => 'Kolom alamat wajib diisi.',
            'telepon.required' => 'Kolom nomor telepon wajib diisi.',
            'link.required' => 'Kolom link wajib diisi.',
        ]);

        $cabang = LokasiKantor::create($validated);
        $cabang->loadCount(['karyawan as pekerja_count']);

        return response()->json($cabang, 201);
    }

    // PUT /api/cabang/{id}
    public function update(Request $request, $id)
    {
        $cabang = LokasiKantor::findOrFail($id);

        $validated = $request->validate([
            'nama' => 'sometimes|required|string|max:150',
            'alamat' => 'sometimes|required|string|max:1000',
            'telepon' => 'sometimes|required|string|max:30',
            'link' => 'sometimes|required|string|max:255',
        ], [
            'nama.required' => 'Kolom nama wajib diisi.',
            'alamat.required' => 'Kolom alamat wajib diisi.',
            'telepon.required' => 'Kolom nomor telepon wajib diisi.',
            'link.required' => 'Kolom link wajib diisi.',
        ]);

        $cabang->update($validated);
        $cabang->loadCount(['karyawan as pekerja_count']);

        return response()->json($cabang);
    }

    // DELETE /api/cabang/{id}
    public function destroy($id)
    {
        $cabang = LokasiKantor::withCount(['karyawan as pekerja_count'])->findOrFail($id);

        if ($cabang->pekerja_count > 0) {
            return response()->json([
                'message' => 'Cabang ini masih memiliki ' . $cabang->pekerja_count . ' pegawai. Pindahkan pegawai terlebih dahulu sebelum menghapus.',
            ], 422);
        }

        $cabang->delete();

        return response()->json(['message' => 'Cabang berhasil dihapus.']);
    }

    /**
     * POST /api/cabang/import -- import massal data Cabang dari file Excel
     * (.xlsx/.xls). Format kolom: Nama | Alamat | Telepon | Link.
     * Baris dengan nama yang sudah ada di-UPDATE (bukan dilewati) -- lihat
     * CabangImport. Mirror PerusahaanController::import() persis, cuma
     * beda target model (LokasiKantor).
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:10240', // max 10MB
        ]);

        DB::beginTransaction();
        try {
            $import = new CabangImport();
            Excel::import($import, $request->file('file'));

            if (count($import->getErrors()) > 0) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'errors'  => $import->getErrors(),
                ], 422);
            }

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => "Berhasil import {$import->getCreatedCount()} cabang baru"
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