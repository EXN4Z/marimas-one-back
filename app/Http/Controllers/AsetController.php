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
     * Display a listing of the resource.
     */
    public function index()
    {
        $aset = Aset::with([
            'jenis',
            'supplier',
            'kelengkapan.kelengkapanMaster',
            'pemakaiSaatIni.pekerja.user',
            'pemakaiPending.pekerja.user', // baru — biar tau aset mana yang ada request pending
            'penangananAktif', // biar frontend tau aset mana yang laporan kerusakannya masih belum ditangani
        ])->latest()->get();
    
        return response()->json($aset);
    }
    
    public function show(Aset $aset)
    {
        $aset->load([
            'jenis',
            'supplier',
            'kelengkapan.kelengkapanMaster',
            'pemakaiSaatIni.pekerja.user', // baru — detail modal butuh ini buat tombol kontekstual (Terima Kembali / Lapor Kerusakan)
            'pemakai.pekerja.user',
            'pemakaiPending.pekerja.user', // baru
            'penanganan.pemakai.pekerja.user',
            'penggantianSparepart',
            'penangananAktif',
        ]);
    
        return response()->json($aset);
    }

    /**
     * Store a newly created resource in storage.
     *
     * 'kelengkapan' dikirim sebagai JSON string dari frontend (karena bercampur
     * dengan file upload di FormData), formatnya:
     * '[{"kelengkapan_master_id":1,"keterangan":"S/N: xxx"}, ...]'
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'jenis_id' => 'nullable|exists:jenis_aset,id',
            'merek' => 'nullable|string',
            'tipe' => 'nullable|string',
            'warna' => 'nullable|string',
            'serial_number' => 'nullable|string|unique:aset,serial_number',
            'perusahaan' => 'nullable|string',
            'keterangan' => 'nullable|string',
            'foto' => 'nullable|file|mimes:jpg,jpeg,png|max:2048',
            'supplier_id' => 'nullable|exists:supplier,id',
            'tanggal_pembelian' => 'nullable|date',
            'no_surat_jalan' => 'nullable|string',
            'no_good_receive' => 'nullable|string',
            'kelengkapan' => 'nullable|string',
        ]);

        $kelengkapanData = $this->parseKelengkapan($request->input('kelengkapan'));

        $aset = DB::transaction(function () use ($validated, $request, $kelengkapanData) {
            if ($request->hasFile('foto')) {
                $validated['foto'] = $request->file('foto')->store('foto-aset', 'public');
            }
            unset($validated['kelengkapan']);

            // kode_aset sengaja gak dikirim, biar trigger DB yang generate
            $aset = Aset::create($validated);

            foreach ($kelengkapanData as $item) {
                AsetKelengkapan::create([
                    'aset_id' => $aset->id,
                    'kelengkapan_master_id' => $item['kelengkapan_master_id'],
                    'keterangan' => $item['keterangan'] ?? null,
                ]);
            }

            return $aset;
        });

        return response()->json(
            $aset->load(['jenis', 'supplier', 'kelengkapan.kelengkapanMaster', 'pemakaiPending.pekerja.user']),
            201
        );
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Aset $aset)
    {
        $validated = $request->validate([
            'jenis_id' => 'nullable|exists:jenis_aset,id',
            'merek' => 'nullable|string',
            'tipe' => 'nullable|string',
            'warna' => 'nullable|string',
            'serial_number' => 'nullable|string|unique:aset,serial_number,' . $aset->id,
            'perusahaan' => 'nullable|string',
            'keterangan' => 'nullable|string',
            'foto' => 'nullable|file|mimes:jpg,jpeg,png|max:2048',
            'supplier_id' => 'nullable|exists:supplier,id',
            'tanggal_pembelian' => 'nullable|date',
            'no_surat_jalan' => 'nullable|string',
            'no_good_receive' => 'nullable|string',
            'kelengkapan' => 'nullable|string',
        ]);

        $kelengkapanRaw = $request->input('kelengkapan');
        $kelengkapanProvided = $request->has('kelengkapan');

        DB::transaction(function () use ($validated, $request, $aset, $kelengkapanRaw, $kelengkapanProvided) {
            if ($request->hasFile('foto')) {
                if ($aset->foto) {
                    Storage::disk('public')->delete($aset->foto);
                }
                $validated['foto'] = $request->file('foto')->store('foto-aset', 'public');
            }
            unset($validated['kelengkapan']);

            $aset->update($validated);

            // kalau frontend kirim ulang daftar kelengkapan, timpa yang lama
            if ($kelengkapanProvided) {
                $aset->kelengkapan()->delete();

                foreach ($this->parseKelengkapan($kelengkapanRaw) as $item) {
                    AsetKelengkapan::create([
                        'aset_id' => $aset->id,
                        'kelengkapan_master_id' => $item['kelengkapan_master_id'],
                        'keterangan' => $item['keterangan'] ?? null,
                    ]);
                }
            }
        });

        return response()->json(
            $aset->load(['jenis', 'supplier', 'kelengkapan.kelengkapanMaster'])
        );
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Aset $aset)
    {
        if ($aset->foto) {
            Storage::disk('public')->delete($aset->foto);
        }

        $aset->delete();

        return response()->json(['message' => "Aset {$aset->kode_aset} berhasil dihapus."]);
    }

    /**
     * Decode & validasi ringan payload kelengkapan dari JSON string.
     */
    private function parseKelengkapan(?string $raw): array
    {
        if (!$raw) {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, function ($item) {
            return is_array($item) && !empty($item['kelengkapan_master_id']);
        }));
    }
}