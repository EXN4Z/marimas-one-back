<?php

namespace App\Http\Controllers\Inventaris;

use App\Http\Controllers\Controller;
use App\Models\AsetKelengkapan;
use App\Models\AsetPemakai;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AsetKelengkapanController extends Controller
{
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
        $pekerjaId = $user?->pekerja?->id;

        $query = AsetKelengkapan::with([
            'jenis',
            'supplier',
            'pemakaiSaatIni.pekerja.user',
            'pemakaiSaatIni.user',
            'pemakaiPending.pekerja.user',
            'pemakaiPending.user',
        ])->latest();

        if (!$isAdmin) {
            $query->where(function ($q) use ($user, $pekerjaId) {
                $q->where('status', 'tersedia')
                    ->orWhereHas('pemakai', function ($sub) use ($user, $pekerjaId) {
                        $sub->where('user_id', $user->id);
                        if ($pekerjaId) {
                            $sub->orWhere('pekerja_id', $pekerjaId);
                        }
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
            $pekerjaId = $user?->pekerja?->id;
            $terkaitUser = $asetKelengkapan->pemakai()
                ->where(function ($q) use ($user, $pekerjaId) {
                    $q->where('user_id', $user->id);
                    if ($pekerjaId) {
                        $q->orWhere('pekerja_id', $pekerjaId);
                    }
                })
                ->exists();

            abort_unless($asetKelengkapan->status === 'tersedia' || $terkaitUser, 403, 'Kamu tidak punya akses untuk melihat detail kelengkapan ini.');
        }

        $asetKelengkapan->load([
            'jenis',
            'supplier',
            'pemakaiSaatIni.pekerja.user',
            'pemakaiSaatIni.user',
            'pemakaiPending.pekerja.user',
            'pemakaiPending.user',
            'pemakai.pekerja.user',
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
            $asetKelengkapan->load('jenis', 'supplier'),
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
            $asetKelengkapan->fresh()->load('jenis', 'supplier')
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
        return $request->validate([
            'jenis_id' => 'nullable|exists:jenis_aset,id',
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