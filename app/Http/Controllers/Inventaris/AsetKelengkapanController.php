<?php

namespace App\Http\Controllers\Inventaris;

use App\Http\Controllers\Controller;
use App\Imports\AsetKelengkapanImport;
use App\Models\AsetKelengkapan;
use App\Models\AsetPemakai;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class AsetKelengkapanController extends Controller
{
    /**
     * POST /api/aset-kelengkapan/import
     * Import massal Aset Kelengkapan dari file Excel (.xlsx/.xls), item
     * berdiri sendiri yang nempel ke aset utama yang SUDAH ADA (dicari
     * lewat kolom "Kode Aset Induk"). Lihat AsetKelengkapanImport buat
     * detail format kolom yang diharapkan.
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:10240', // max 10MB
        ]);

        DB::beginTransaction();
        try {
            $import = new AsetKelengkapanImport();
            Excel::import($import, $request->file('file'));

            if (count($import->getErrors()) > 0) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'errors'  => $import->getErrors(),
                    'message' => $import->getErrors()[0] ?? 'Gagal import.',
                ], 422);
            }

            if ($import->getRowCount() === 0) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak ada baris data yang berhasil dibaca dari file.',
                ], 422);
            }

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => "Berhasil import {$import->getRowCount()} kelengkapan aset",
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal import: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * GET /api/aset-kelengkapan
     * Sama polanya kayak AsetController::index() — admin lihat semua,
     * non-admin cuma lihat yang 'tersedia' atau yang terkait pemakaian
     * dia sendiri (lewat aset_pemakai.aset_kelengkapan_id).
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $isAdmin = $user?->role === 'admin';

        $query = AsetKelengkapan::with([
            'aset',
            'supplier',
            'pemakaiSaatIni.user',
            'pemakaiPending.user',
        ])->latest();

        if (!$isAdmin) {
            $query->where(function ($q) use ($user) {
                $q->where('status', 'tersedia')
                    ->orWhereHas('pemakai', function ($sub) use ($user) {
                        $sub->where('user_id', $user->id);
                    });
            });
        }

        return response()->json($query->get());
    }

    /**
     * GET /api/aset-kelengkapan/{aset_kelengkapan}
     */
    public function show(Request $request, AsetKelengkapan $asetKelengkapan)
    {
        $user = $request->user();
        $isAdmin = $user?->role === 'admin';

        if (!$isAdmin) {
            $terkaitUser = $asetKelengkapan->pemakai()
                ->where('user_id', $user->id)
                ->exists();

            abort_unless($asetKelengkapan->status === 'tersedia' || $terkaitUser, 403, 'Kamu tidak punya akses untuk melihat detail kelengkapan ini.');
        }

        $asetKelengkapan->load([
            'aset',
            'supplier',
            'pemakaiSaatIni.user',
            'pemakaiPending.user',
            'pemakai.user',
        ]);

        return response()->json($asetKelengkapan);
    }

    /**
     * POST /api/aset-kelengkapan
     * kode_kelengkapan digenerate otomatis lewat trigger DB.
     */
    public function store(Request $request)
    {
        $validated = $this->validasi($request);

        $asetKelengkapan = DB::transaction(function () use ($request, $validated) {
            if ($request->hasFile('foto')) {
                $validated['foto'] = $request->file('foto')->store('aset-kelengkapan', 'public');
            }

            return AsetKelengkapan::create($validated);
        });

        return response()->json(
            $asetKelengkapan->load(['aset', 'supplier']),
            201
        );
    }

    /**
     * POST /api/aset-kelengkapan/{aset_kelengkapan} (+ _method=PUT)
     */
    public function update(Request $request, AsetKelengkapan $asetKelengkapan)
    {
        $validated = $this->validasi($request, $asetKelengkapan);

        DB::transaction(function () use ($request, $validated, $asetKelengkapan) {
            if ($request->hasFile('foto')) {
                if ($asetKelengkapan->foto) {
                    Storage::disk('public')->delete($asetKelengkapan->foto);
                }
                $validated['foto'] = $request->file('foto')->store('aset-kelengkapan', 'public');
            }

            $asetKelengkapan->update($validated);
        });

        return response()->json(
            $asetKelengkapan->fresh()->load(['aset', 'supplier'])
        );
    }

    /**
     * DELETE /api/aset-kelengkapan/{aset_kelengkapan}
     * ?force=1 lewatin guard riwayat, sama kayak AsetController::destroy().
     */
    public function destroy(Request $request, AsetKelengkapan $asetKelengkapan)
    {
        $force = $request->boolean('force');

        if (!$force && $asetKelengkapan->pemakai()->exists()) {
            return response()->json([
                'message' => 'Kelengkapan ini punya riwayat peminjaman dan tidak bisa dihapus.',
                'force_available' => true,
            ], 422);
        }

        $namaKelengkapan = $asetKelengkapan->kode_kelengkapan;
        $asetKelengkapan->delete();

        return response()->json(['message' => "Kelengkapan {$namaKelengkapan} berhasil dihapus."]);
    }

    protected function validasi(Request $request, ?AsetKelengkapan $asetKelengkapan = null): array
    {
        $request->merge([
            'serial_number' => $request->serial_number === '' ? null : $request->serial_number,
        ]);

        return $request->validate([
            'aset_id' => 'nullable|exists:aset,id',
            'nama' => 'nullable',
            'merek' => 'nullable|string|max:255',
            'tipe' => 'nullable|string|max:255',
            'warna' => 'nullable|string|max:255',
            'serial_number' => [
                'nullable',
                'string',
                'max:255',
                $asetKelengkapan
                    ? 'unique:aset_kelengkapan,serial_number,' . $asetKelengkapan->id
                    : 'unique:aset_kelengkapan,serial_number',
            ],
            'tanggal_garansi' => 'nullable|date',
            'perusahaan' => 'nullable|string|max:255',
            'keterangan' => 'nullable|string',
            'foto' => 'nullable|image|max:4096',
            'supplier_id' => 'nullable|exists:supplier,id',
            'tanggal_pembelian' => 'nullable|date',
            'no_surat_jalan' => 'nullable|string|max:255',
            'no_good_receive' => 'nullable|string|max:255',
            'status' => 'nullable|in:tersedia,dipakai,rusak,diperbaiki',
        ]);
    }
}