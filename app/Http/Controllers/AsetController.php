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
     * Sembunyiin no_struk_penerimaan/no_struk_pengembalian dari siapa aja
     * selain admin dan karyawan/akun cabang yang jadi peminjam di record itu
     * sendiri. Struk itu bukti fisik yang dipegang si peminjam — kalau bocor
     * ke pihak lain, orang lain bisa pura-pura jadi peminjam pas pengembalian.
     *
     * FIX: sebelumnya cuma cek $pemakai->pekerja?->user?->id, jadi akun
     * cabang (yang gak punya pekerja, cuma user_id langsung) selalu dianggap
     * BUKAN pemiliknya sendiri dan struknya ke-mask terus. Sekarang cek dua
     * kemungkinan: lewat pekerja.user ATAU lewat user langsung.
     */
    private function maskStruk($pemakai, $userId, bool $isAdmin)
    {
        if ($isAdmin) {
            return $pemakai;
        }
        $isOwner = ($pemakai->pekerja?->user?->id === $userId) || ($pemakai->user?->id === $userId);
        if (!$isOwner) {
            $pemakai->no_struk_penerimaan = null;
            $pemakai->no_struk_pengembalian = null;
        }
        return $pemakai;
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $isAdmin = $request->user()->role === 'admin';
        $userId = $request->user()->id;

        $aset = Aset::with([
            'jenis',
            'supplier',
            'kelengkapan.kelengkapanMaster',
            'pemakaiSaatIni.pekerja.user',
            'pemakaiSaatIni.user', // FIX: penerima akun cabang gak punya pekerja, datanya di sini
            'pemakaiPending.pekerja.user', // baru — biar tau aset mana yang ada request pending
            'pemakaiPending.user', // FIX: sama kayak di atas, buat pengajuan dari akun cabang
            'penangananAktif', // biar frontend tau aset mana yang laporan kerusakannya masih belum ditangani
        ])->latest()->get();

        $aset->each(function ($a) use ($userId, $isAdmin) {
            if ($a->pemakaiSaatIni) {
                $this->maskStruk($a->pemakaiSaatIni, $userId, $isAdmin);
            }
        });

        return response()->json($aset);
    }
    
    public function show(Request $request, Aset $aset)
    {
        $isAdmin = $request->user()->role === 'admin';
        $userId = $request->user()->id;

        $aset->load([
            'jenis',
            'supplier',
            'kelengkapan.kelengkapanMaster',
            'pemakaiSaatIni.pekerja.user', // baru — detail modal butuh ini buat tombol kontekstual (Terima Kembali / Lapor Kerusakan)
            'pemakaiSaatIni.user', // FIX: penerima akun cabang
            'pemakai.pekerja.user',
            'pemakai.user', // FIX: riwayat pemakai yang penerimanya akun cabang
            'pemakaiPending.pekerja.user', // baru
            'pemakaiPending.user', // FIX: pengajuan dari akun cabang
            'penanganan.pemakai.pekerja.user',
            'penanganan.pemakai.user', // FIX: siapa yang minjem pas lapor rusak, kalau dia akun cabang
            'penggantianSparepart',
            'penangananAktif',
        ]);

        if ($aset->pemakaiSaatIni) {
            $this->maskStruk($aset->pemakaiSaatIni, $userId, $isAdmin);
        }
        $aset->pemakai->each(fn ($p) => $this->maskStruk($p, $userId, $isAdmin));

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
            $aset->load([
                'jenis',
                'supplier',
                'kelengkapan.kelengkapanMaster',
                'pemakaiPending.pekerja.user',
                'pemakaiPending.user', // FIX: konsisten sama index()/show()
            ]),
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