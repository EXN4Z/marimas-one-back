<?php

namespace App\Http\Controllers;

use App\Models\Aset;
use App\Models\AsetKelengkapan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AsetController extends Controller
{
    /**
     * GET /api/aset
     * Daftar semua aset. Eager-load relasi yang dipakai di tabel list Inventaris.
     */
    public function index()
    {
        $aset = Aset::with([
            'jenis',
            'supplier',
            'kelengkapan.kelengkapanMaster',
            'pemakaiSaatIni.pekerja.user',
            'pemakaiSaatIni.user',
            'pemakaiPending.pekerja.user',
            'pemakaiPending.user',
            'penangananAktif',
        ])
            ->latest()
            ->get();

        return response()->json($aset);
    }

    /**
     * GET /api/aset/{aset}
     * Detail satu aset, termasuk riwayat lengkap (pemakai & penanganan) buat halaman detail.
     */
    public function show(Aset $aset)
    {
        $aset->load([
            'jenis',
            'supplier',
            'kelengkapan.kelengkapanMaster',
            'pemakaiSaatIni.pekerja.user',
            'pemakaiSaatIni.user',
            'pemakaiPending.pekerja.user',
            'pemakaiPending.user',
            'pemakai.pekerja.user',
            'pemakai.user',
            'penanganan',
            'penggantianSparepart',
            'penangananAktif',
        ]);

        return response()->json($aset);
    }

    /**
     * POST /api/aset
     * kode_aset digenerate otomatis lewat trigger DB, gak perlu (& gak boleh) dikirim dari frontend.
     * kelengkapan dikirim sebagai JSON string (multipart/form-data gak bisa array of object native).
     */
    public function store(Request $request)
    {
        $validated = $this->validasi($request);

        $aset = DB::transaction(function () use ($request, $validated) {
            if ($request->hasFile('foto')) {
                $validated['foto'] = $request->file('foto')->store('aset', 'public');
            }

            $aset = Aset::create($validated);

            $this->simpanKelengkapan($aset, $request);

            return $aset;
        });

        return response()->json(
            $aset->load('jenis', 'supplier', 'kelengkapan.kelengkapanMaster'),
            201
        );
    }

    /**
     * POST /api/aset/{aset} (+ _method=PUT dari frontend, krn ada file upload)
     * Foto lama dihapus dari storage kalau diganti foto baru.
     */
    public function update(Request $request, Aset $aset)
    {
        $validated = $this->validasi($request, $aset);

        DB::transaction(function () use ($request, $validated, $aset) {
            if ($request->hasFile('foto')) {
                if ($aset->foto) {
                    Storage::disk('public')->delete($aset->foto);
                }
                $validated['foto'] = $request->file('foto')->store('aset', 'public');
            }

            $aset->update($validated);

            // kelengkapan cuma diupdate kalau frontend memang mengirim field-nya
            // (biar update parsial lain, mis. cuma ganti status, gak ikut ngosongin kelengkapan)
            if ($request->has('kelengkapan')) {
                $aset->kelengkapan()->delete();
                $this->simpanKelengkapan($aset, $request);
            }
        });

        return response()->json(
            $aset->fresh()->load('jenis', 'supplier', 'kelengkapan.kelengkapanMaster')
        );
    }

    /**
     * DELETE /api/aset/{aset}
     */
    public function destroy(Aset $aset)
    {
        if ($aset->foto) {
            Storage::disk('public')->delete($aset->foto);
        }

        $namaAset = $aset->kode_aset;
        $aset->delete();

        return response()->json(['message' => "Aset {$namaAset} berhasil dihapus."]);
    }

    /**
     * Validasi bersama buat store() & update(). Field unique (serial_number)
     * dikecualikan dari record aset itu sendiri kalau lagi mode update.
     */
    protected function validasi(Request $request, ?Aset $aset = null): array
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
                $aset
                    ? 'unique:aset,serial_number,' . $aset->id
                    : 'unique:aset,serial_number',
            ],
            'perusahaan' => 'nullable|string|max:255',
            'keterangan' => 'nullable|string',
            'foto' => 'nullable|image|max:4096',
            'supplier_id' => 'nullable|exists:supplier,id',
            'tanggal_pembelian' => 'nullable|date',
            'no_surat_jalan' => 'nullable|string|max:255',
            'no_good_receive' => 'nullable|string|max:255',
            'status' => 'nullable|in:tersedia,dipakai,rusak,menunggu_perbaikan,diperbaiki,rusak_berat',
            'kelengkapan' => 'nullable|string', // JSON string, di-decode manual di simpanKelengkapan()
        ]);
    }

    /**
     * Decode JSON kelengkapan dari request lalu simpan sebagai baris-baris AsetKelengkapan.
     */
    protected function simpanKelengkapan(Aset $aset, Request $request): void
    {
        $items = json_decode($request->input('kelengkapan', '[]'), true) ?: [];

        foreach ($items as $item) {
            if (empty($item['kelengkapan_master_id'])) {
                continue;
            }

            AsetKelengkapan::create([
                'aset_id' => $aset->id,
                'kelengkapan_master_id' => $item['kelengkapan_master_id'],
                'keterangan' => $item['keterangan'] ?? null,
            ]);
        }
    }
}