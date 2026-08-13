<?php

namespace App\Http\Controllers\Inventaris;

use App\Http\Controllers\Controller;

use App\Models\Aset;
use App\Models\AsetPemakai;
use App\Models\AsetWriteoff;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AsetController extends Controller
{
    /**
     * GET /api/aset
     * Admin: daftar SEMUA aset (perilaku lama, gak berubah).
     * Non-admin (karyawan/cabang/manajer/hr): dibatasi cuma aset yang
     * statusnya 'tersedia' (biar tau apa yang bisa dipinjam) PLUS aset yang
     * pernah/sedang ada hubungan pemakaian sama akun dia sendiri (lewat
     * user_id atau pekerja_id di tabel aset_pemakai) -- sama persis pola
     * kepemilikan yang dipakai di AsetPemakaiController::riwayat(). Aset
     * yang lagi dipegang/riwayatnya cuma nempel ke orang lain TIDAK ikut
     * dikirim ke non-admin sama sekali, jadi bukan cuma disembunyiin di
     * tampilan React -- datanya memang gak nyampe ke browser mereka.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $isAdmin = $user?->role === 'admin';
        $pekerjaId = $user?->pekerja?->id;

        $query = Aset::with([
            'departemen',
            'supplier',
            'pemakaiSaatIni.pekerja.user',
            'pemakaiSaatIni.user',
            'pemakaiPending.pekerja.user',
            'pemakaiPending.user',
            'penangananAktif',
            'writeoff.penyetuju:id,name',
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
     * GET /api/aset/{aset}
     * Detail satu aset, termasuk riwayat lengkap (pemakai & penanganan) buat halaman detail.
     * Non-admin cuma boleh buka detail aset yang 'tersedia' atau yang
     * pernah/sedang berhubungan pemakaian sama dia sendiri -- sama scoping-nya
     * kayak index(). Ini WAJIB dicek di sini juga (bukan cuma index()), soalnya
     * endpoint ini bisa dipanggil langsung lewat ID tanpa lewat daftar/tabel.
     */
    public function show(Request $request, Aset $aset)
    {
        $user = $request->user();
        $isAdmin = $user?->role === 'admin';

        if (!$isAdmin) {
            $pekerjaId = $user?->pekerja?->id;
            $terkaitUser = $aset->pemakai()
                ->where(function ($q) use ($user, $pekerjaId) {
                    $q->where('user_id', $user->id);
                    if ($pekerjaId) {
                        $q->orWhere('pekerja_id', $pekerjaId);
                    }
                })
                ->exists();

            abort_unless($aset->status === 'tersedia' || $terkaitUser, 403, 'Kamu tidak punya akses untuk melihat detail aset ini.');
        }

        $aset->load([
            'departemen',
            'supplier',
            'pemakaiSaatIni.pekerja.user',
            'pemakaiSaatIni.user',
            'pemakaiPending.pekerja.user',
            'pemakaiPending.user',
            'pemakai.pekerja.user',
            'pemakai.user',
            'penanganan',
            'penangananAktif',
            'writeoff.penyetuju:id,name',
            'asetKelengkapan.supplier',
        ]);

        return response()->json($aset);
    }

    /**
     * POST /api/aset
     * kode_aset digenerate otomatis lewat trigger DB (dari kata pertama
     * `merek`), gak perlu (& gak boleh) dikirim dari frontend.
     * Kelengkapan (Tas, Charger, dst) BUKAN sub-item di form ini -- itu
     * dikelola lewat tabel aset_kelengkapan yang nempel ke aset induknya
     * (lihat AsetKelengkapanController), lalu dikaitkan ke pemakai lewat
     * aset_pemakai.aset_kelengkapan_id, biasanya di form peminjaman.
     */
    public function store(Request $request)
    {
        $validated = $this->validasi($request);

        $aset = DB::transaction(function () use ($request, $validated) {
            if ($request->hasFile('foto')) {
                $validated['foto'] = $request->file('foto')->store('aset', 'public');
            }

            return Aset::create($validated);
        });

        return response()->json(
            $aset->load('departemen', 'supplier'),
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
        });

        return response()->json(
            $aset->fresh()->load('departemen', 'supplier')
        );
    }

    /**
     * DELETE /api/aset/{aset}
     * ?force=1 lewatin guard riwayat — dipakai admin buat bersihin data
     * lama/test yang gak bisa kehapus normal krn udah punya riwayat
     * pemakai/penanganan. Aman: aset_pemakai & aset_perbaikan semua
     * cascadeOnDelete di FK-nya, jadi riwayat ikut kehapus bersih, gak
     * nyisa orphan row.
     */
    public function destroy(Request $request, Aset $aset)
    {
        $force = $request->boolean('force');

        if (!$force) {
            if ($aset->penanganan()->exists()) {
                return response()->json([
                    'message' => 'Aset ini punya riwayat perbaikan/penanganan dan tidak bisa dihapus.',
                    'force_available' => true,
                ], 422);
            }

            if ($aset->pemakai()->exists()) {
                return response()->json([
                    'message' => 'Aset ini punya riwayat peminjaman dan tidak bisa dihapus.',
                    'force_available' => true,
                ], 422);
            }
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
            'departemen_id' => 'nullable|exists:departemen,id',
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
            'jumlah' => 'nullable|integer|min:1',
            'tanggal_garansi' => 'nullable|date',
            'perusahaan' => 'nullable|string|max:255',
            'keterangan' => 'nullable|string',
            'foto' => 'nullable|image|max:4096',
            'supplier_id' => 'nullable|exists:supplier,id',
            'tanggal_pembelian' => 'nullable|date',
            'no_surat_jalan' => 'nullable|string|max:255',
            'no_good_receive' => 'nullable|string|max:255',
            'status' => 'nullable|in:tersedia,dipakai,rusak,menunggu_perbaikan,diperbaiki,rusak_berat',
        ]);
    }
    public function jual(Request $request, Aset $aset)
    {
        if ($aset->status !== 'rusak_berat' && $aset->status !== 'tersedia') {
            return response()->json([
                'message' => 'Aset hanya bisa dijual jika statusnya Rusak Berat.',
            ], 422);
        }

        $validated = $request->validate([
            'alasan' => 'nullable|string',
            'no_berita_acara' => 'nullable|string|max:255',
            'catatan' => 'nullable|string',
        ]);

        DB::transaction(function () use ($aset, $request, $validated) {
            $aset->update(['status' => 'dijual']);

            // jaga-jaga: kalau masih ada record aset_pemakai yang "nyangkut"
            // aktif (data lama sebelum ada auto-kembalikan di rusak_berat),
            // tutup paksa di sini juga biar "Dipakai Oleh" gak nunjuk ke
            // orang yang udah gak pegang aset ini lagi.
            AsetPemakai::where('aset_id', $aset->id)
                ->where('status', 'disetujui')
                ->whereNull('tanggal_pengembalian')
                ->update([
                    'tanggal_pengembalian' => now(),
                    'dikembalikan_at' => now(),
                    'catatan_pengembalian' => 'Dikembalikan otomatis — aset dijual.',
                ]);

            // catat sebagai riwayat writeoff biar muncul akurat di panel
            // "Riwayat Aset" (siapa yang nyetujui, kapan, kenapa) — bukan
            // cuma ganti status tanpa jejak.
            AsetWriteoff::updateOrCreate(
                ['aset_id' => $aset->id],
                [
                    'disetujui_oleh' => $request->user()?->id,
                    'alasan' => $validated['alasan'] ?? 'Rusak berat, tidak dapat diperbaiki lagi.',
                    'no_berita_acara' => $validated['no_berita_acara'] ?? null,
                    'tanggal_writeoff' => now()->toDateString(),
                    'catatan' => $validated['catatan'] ?? null,
                ]
            );
        });

        return response()->json($aset->fresh()->load([
            'departemen',
            'supplier',
            'pemakaiSaatIni',
            'writeoff.penyetuju:id,name',
        ]));
    }
}