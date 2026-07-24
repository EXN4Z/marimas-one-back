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
     * BARU: ambil user id pemakai, entah dia karyawan (lewat pekerja.user)
     * atau akun cabang (lewat user langsung). Dipakai di semua tempat yang
     * sebelumnya cuma cek $pemakai->pekerja?->user?->id, biar akun cabang
     * ikut kedeteksi dengan benar (bukan cuma karyawan).
     */
    private function pemakaiUserId($pemakai): ?int
    {
        return $pemakai?->pekerja?->user?->id ?? $pemakai?->user?->id;
    }

    /**
     * Sembunyiin no_struk_penerimaan/no_struk_pengembalian dari siapa aja
     * selain admin/hr dan karyawan yang jadi peminjam di record itu sendiri.
     * Struk itu bukti fisik yang dipegang si peminjam — kalau bocor ke
     * karyawan lain, orang lain bisa pura-pura jadi peminjam pas pengembalian.
     */
    private function maskStruk($pemakai, $userId, bool $isPrivileged)
    {
        if ($isPrivileged) {
            return $pemakai;
        }
        $isOwner = $this->pemakaiUserId($pemakai) === $userId;
        if (!$isOwner) {
            $pemakai->no_struk_penerimaan = null;
            $pemakai->no_struk_pengembalian = null;
        }
        return $pemakai;
    }

    /**
     * BARU: karyawan/manajer cuma boleh lihat baris aset yang (a) lagi dia
     * pinjam sendiri, atau (b) berstatus "tersedia" (biar bisa diajukan
     * pinjam). Aset yang lagi dipinjam/ditangani KARYAWAN LAIN disembunyikan
     * total dari tabel & gak bisa diakses langsung lewat /aset/{id}.
     */
    private function visibleToUser(Aset $aset, int $userId, bool $isPrivileged): bool
    {
        if ($isPrivileged) {
            return true;
        }

        // gak ada pemakai saat ini (tersedia, atau rusak tanpa pemakai
        // terpasang) — tetap kelihatan biar bisa diajukan pinjam.
        if (!$aset->pemakaiSaatIni) {
            return true;
        }

        return $this->pemakaiUserId($aset->pemakaiSaatIni) === $userId;
    }

    /**
     * BARU: karyawan/manajer cuma boleh lihat DATA PRIBADI sendiri — bukan siapa
     * aja yang pinjam/lapor kerusakan aset lain. Cuma admin & hr yang boleh lihat
     * identitas & riwayat lengkap semua karyawan. Aset itu sendiri (kode, jenis,
     * status tersedia/dipakai, dll) tetap kelihatan buat semua — yang disembunyikan
     * cuma identitas & riwayat pribadi orang lain.
     */
    private function sanitizeAsetForUser(Aset $aset, int $userId, bool $isPrivileged): Aset
    {
        if ($isPrivileged) {
            return $aset;
        }

        // pemakai_saat_ini: tetap tampil (dipakai buat cek status "aku yang pinjam
        // apa nggak" & tombol Kembalikan/Lapor Kerusakan), tapi nama & struk orang
        // lain disembunyikan. Berlaku buat dua bentuk pemakai: karyawan (pekerja.user)
        // ATAU akun cabang (user langsung).
        if ($aset->relationLoaded('pemakaiSaatIni') && $aset->pemakaiSaatIni) {
            $isOwner = $this->pemakaiUserId($aset->pemakaiSaatIni) === $userId;
            if (!$isOwner) {
                if ($aset->pemakaiSaatIni->pekerja?->user) {
                    $aset->pemakaiSaatIni->pekerja->user->name = null;
                }
                if ($aset->pemakaiSaatIni->user) {
                    $aset->pemakaiSaatIni->user->name = null;
                }
            }
        }

        // pemakai_pending: id tetap ada (buat deteksi "ada pengajuan lain yang
        // masih nunggu"), tapi nama pengaju lain disembunyikan.
        if ($aset->relationLoaded('pemakaiPending')) {
            $aset->pemakaiPending->each(function ($p) use ($userId) {
                $isOwner = $this->pemakaiUserId($p) === $userId;
                if (!$isOwner) {
                    if ($p->pekerja?->user) {
                        $p->pekerja->user->name = null;
                    }
                    if ($p->user) {
                        $p->user->name = null;
                    }
                }
            });
        }

        // riwayat pemakai (detail): cuma tampilin riwayat peminjaman milik sendiri
        if ($aset->relationLoaded('pemakai')) {
            $aset->setRelation(
                'pemakai',
                $aset->pemakai->filter(fn ($p) => $this->pemakaiUserId($p) === $userId)->values()
            );
        }

        // riwayat penanganan/perbaikan (detail): cuma tampilin laporan milik sendiri
        // (atau laporan tanpa pemakai — hasil audit gudang admin — tetap disembunyikan
        // dari karyawan biasa karena bukan urusan mereka).
        if ($aset->relationLoaded('penanganan')) {
            $aset->setRelation(
                'penanganan',
                $aset->penanganan->filter(fn ($p) => $this->pemakaiUserId($p->pemakai) === $userId)->values()
            );
        }

        return $aset;
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $isPrivileged = in_array($user->role, ['admin', 'hr'], true);
        $userId = $user->id;

        $aset = Aset::with([
            'jenis',
            'supplier',
            'kelengkapan.kelengkapanMaster',
            'pemakaiSaatIni.pekerja.user',
            'pemakaiSaatIni.user', // BARU — akun cabang gak punya pekerja, jadi user-nya harus di-load langsung
            'pemakaiPending.pekerja.user',
            'pemakaiPending.user', // BARU — sama, buat akun cabang yang lagi ngajuin pinjam
            'penangananAktif', // biar frontend tau aset mana yang laporan kerusakannya masih belum ditangani
        ])->latest()->get();

        // BARU: buang total baris aset yang lagi dipinjam/ditangani KARYAWAN
        // LAIN — karyawan biasa cuma boleh lihat punya sendiri + yang tersedia.
        $aset = $aset->filter(fn ($a) => $this->visibleToUser($a, $userId, $isPrivileged))->values();

        $aset->each(function ($a) use ($userId, $isPrivileged) {
            if ($a->pemakaiSaatIni) {
                $this->maskStruk($a->pemakaiSaatIni, $userId, $isPrivileged);
            }
            $this->sanitizeAsetForUser($a, $userId, $isPrivileged);
        });

        return response()->json($aset);
    }

    public function show(Request $request, Aset $aset)
    {
        $user = $request->user();
        $isPrivileged = in_array($user->role, ['admin', 'hr'], true);
        $userId = $user->id;

        $aset->load([
            'jenis',
            'supplier',
            'kelengkapan.kelengkapanMaster',
            'pemakaiSaatIni.pekerja.user', // baru — detail modal butuh ini buat tombol kontekstual (Terima Kembali / Lapor Kerusakan)
            'pemakaiSaatIni.user', // BARU — akun cabang
            'pemakai.pekerja.user',
            'pemakai.user', // BARU — riwayat pemakai buat akun cabang
            'pemakaiPending.pekerja.user', // baru
            'pemakaiPending.user', // BARU
            'penanganan.pemakai.pekerja.user',
            'penanganan.pemakai.user', // BARU — riwayat perbaikan yang dilaporkan akun cabang
            'penggantianSparepart',
            'penangananAktif',
        ]);

        // BARU: kalau aset ini lagi dipinjam KARYAWAN LAIN, tolak akses sama
        // sekali — konsisten sama yang disembunyikan di index(), dan mencegah
        // karyawan buka detail lewat URL/API langsung.
        abort_unless($this->visibleToUser($aset, $userId, $isPrivileged), 403, 'Anda tidak punya akses ke aset ini.');

        if ($aset->pemakaiSaatIni) {
            $this->maskStruk($aset->pemakaiSaatIni, $userId, $isPrivileged);
        }
        $aset->pemakai->each(fn ($p) => $this->maskStruk($p, $userId, $isPrivileged));

        $this->sanitizeAsetForUser($aset, $userId, $isPrivileged);

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
                'pemakaiPending.user', // BARU
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