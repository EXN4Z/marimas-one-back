<?php

namespace App\Http\Controllers\Organisasi;

use App\Http\Controllers\Controller;

use App\Imports\PerusahaanImport;
use App\Models\Perusahaan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

// Mirror dari CabangController -- struktur & validasi field sama persis,
// tapi TANPA cek "masih ada pegawai/relasi" sebelum hapus, karena
// Perusahaan sengaja belum dikaitkan ke tabel lain (lihat model Perusahaan).
class PerusahaanController extends Controller
{
    // GET /api/perusahaan
    public function index()
    {
        $perusahaan = Perusahaan::orderBy('nama')->get();

        return response()->json($perusahaan);
    }

    // GET /api/perusahaan/{id}
    public function show($id)
    {
        $perusahaan = Perusahaan::findOrFail($id);

        return response()->json($perusahaan);
    }

    // POST /api/perusahaan
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

        $perusahaan = Perusahaan::create($validated);

        return response()->json($perusahaan, 201);
    }

    // PUT /api/perusahaan/{id}
    public function update(Request $request, $id)
    {
        $perusahaan = Perusahaan::findOrFail($id);

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

        $perusahaan->update($validated);

        return response()->json($perusahaan);
    }

    // DELETE /api/perusahaan/{id}
    public function destroy($id)
    {
        $perusahaan = Perusahaan::findOrFail($id);
        $perusahaan->delete();

        return response()->json(['message' => 'Perusahaan berhasil dihapus.']);
    }

    /**
     * POST /api/perusahaan/import -- import massal data Perusahaan dari file
     * Excel (.xlsx/.xls). Format kolom: Nama | Alamat | Telepon | Link.
     * Baris dengan nama yang sudah ada di-UPDATE (bukan dilewati) -- lihat
     * PerusahaanImport. Mirror SupplierController::import().
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:10240', // max 10MB
        ]);

        DB::beginTransaction();
        try {
            $import = new PerusahaanImport();
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
                'message' => "Berhasil import {$import->getCreatedCount()} perusahaan baru"
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