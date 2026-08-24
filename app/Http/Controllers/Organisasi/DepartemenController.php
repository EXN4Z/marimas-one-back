<?php

namespace App\Http\Controllers\Organisasi;

use App\Http\Controllers\Controller;

use App\Imports\DepartemenImport;
use App\Models\MasterData\Departemen;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class DepartemenController extends Controller
{
    public function index()
    {
        return response()->json(Departemen::orderBy('nama')->get());
    }

    /**
     * POST /api/departemen/import -- import massal data Departemen dari
     * file Excel (.xlsx/.xls). Format kolom: Nama. Baris dengan nama yang
     * sudah ada dilewati (idempotent, bukan error) -- lihat DepartemenImport.
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:10240', // max 10MB
        ]);

        DB::beginTransaction();
        try {
            $import = new DepartemenImport();
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
                'message' => "Berhasil import {$import->getCreatedCount()} departemen baru"
                    . ($import->getSkippedCount() > 0 ? ", {$import->getSkippedCount()} dilewati (sudah ada)" : ''),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal import: ' . $e->getMessage(),
            ], 422);
        }
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nama' => 'required|string|unique:departemen,nama',
        ]);

        $departemen = Departemen::create($validated);

        return response()->json($departemen, 201);
    }

    public function update(Request $request, Departemen $departeman)
    {
        $validated = $request->validate([
            'nama' => 'required|string|unique:departemen,nama,' . $departeman->id,
        ]);

        $departeman->update($validated);

        return response()->json($departeman);
    }

    public function destroy(Departemen $departeman)
    {
        $departeman->delete();

        return response()->json(['message' => "Departemen {$departeman->nama} berhasil dihapus."]);
    }
}