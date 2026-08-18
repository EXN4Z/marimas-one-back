<?php

namespace App\Http\Controllers\Inventaris;

use App\Http\Controllers\Controller;
use App\Imports\AsetKelengkapanImport;
use App\Models\AsetKelengkapan;
use App\Models\AsetPemakai;
use App\Models\User;
use App\Notifications\AsetKelengkapanKerusakanDilaporkan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
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
     * Admin lihat semua (termasuk yang 'rusak' & punya orang lain).
     * Non-admin cuma lihat yang 'tersedia' ATAU yang LAGI dia pinjam/pending
     * sendiri (pemakaiSaatIni/pemakaiPending, bukan `pemakai()` yang isinya
     * riwayat lengkap -- kalau dibiarin riwayat, kelengkapan yang udah balik
     * ke rusak/dipegang orang lain lain ikut kebocor cuma karena user ini
     * PERNAH pegang). Status 'rusak' juga sengaja DIKECUALIKAN total dari
     * non-admin, bukan cuma disembunyiin di tab FE.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $isAdmin = $user?->role === 'admin';

        $query = AsetKelengkapan::with([
            'aset',
            'lokasiKantor',
            'supplier',
            'pemakaiSaatIni.user',
            'pemakaiPending.user',
        ])->latest();

        if (!$isAdmin) {
            $query->where('status', '!=', 'rusak')
                ->where(function ($q) use ($user) {
                    $q->where('status', 'tersedia')
                        ->orWhereHas('pemakaiSaatIni', function ($sub) use ($user) {
                            $sub->where('user_id', $user->id);
                        })
                        ->orWhereHas('pemakaiPending', function ($sub) use ($user) {
                            $sub->where('user_id', $user->id);
                        });
                });
        }

        return response()->json($query->get());
    }

    /**
     * GET /api/aset-kelengkapan/{aset_kelengkapan}
     * Scoping sama kayak index() -- WAJIB dicek di sini juga (bukan cuma
     * index()), soalnya endpoint ini bisa dipanggil langsung lewat ID
     * tanpa lewat daftar/tabel.
     */
    public function show(Request $request, AsetKelengkapan $asetKelengkapan)
    {
        $user = $request->user();
        $isAdmin = $user?->role === 'admin';

        if (!$isAdmin) {
            abort_if($asetKelengkapan->status === 'rusak', 403, 'Kamu tidak punya akses untuk melihat detail kelengkapan ini.');

            $terkaitUser = $asetKelengkapan->pemakaiSaatIni()->where('user_id', $user->id)->exists()
                || $asetKelengkapan->pemakaiPending()->where('user_id', $user->id)->exists();

            abort_unless($asetKelengkapan->status === 'tersedia' || $terkaitUser, 403, 'Kamu tidak punya akses untuk melihat detail kelengkapan ini.');
        }

        $asetKelengkapan->load([
            'aset',
            'lokasiKantor',
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
            $asetKelengkapan->load(['aset', 'lokasiKantor', 'supplier']),
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
            $asetKelengkapan->fresh()->load(['aset', 'lokasiKantor', 'supplier'])
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
    /**
     * POST /api/aset-kelengkapan/{aset_kelengkapan}/lapor-rusak
     * Lepas otomatis dari induk (kalau ada), tutup paksa peminjaman aktif
     * yang nempel di kelengkapan ini, status -> 'rusak'. Final, gak ada
     * opsi "diperbaiki" buat kelengkapan.
     */
    public function laporRusak(AsetKelengkapan $asetKelengkapan)
    {
        if ($asetKelengkapan->status === 'rusak') {
            return response()->json([
                'message' => 'Kelengkapan ini sudah dilaporkan rusak.',
            ], 422);
        }

        // tangkep dulu sebelum aset_id dikosongin di transaksi bawah --
        // butuh buat isi pesan notif ("terpasang di ...").
        $asetIndukLabel = null;
        if ($asetKelengkapan->aset_id) {
            $asetInduk = $asetKelengkapan->load('aset')->aset;
            if ($asetInduk) {
                $asetIndukLabel = trim(($asetInduk->kode_aset ?? '') . ' ' . ($asetInduk->merek ?? ''));
            }
        }

        DB::transaction(function () use ($asetKelengkapan) {
            $asetKelengkapan->update([
                'aset_id' => null,
                'status' => 'rusak',
                'tanggal_rusak' => now(),
            ]);

            AsetPemakai::where('aset_kelengkapan_id', $asetKelengkapan->id)
                ->where('status', 'disetujui')
                ->whereNull('tanggal_pengembalian')
                ->update([
                    'tanggal_pengembalian' => now(),
                    'dikembalikan_at' => now(),
                    'catatan_pengembalian' => 'Dikembalikan otomatis — kelengkapan dinyatakan rusak.',
                ]);
        });

        // TAMBAH: notif ke manajer/hr/admin tiap ada laporan kerusakan
        // kelengkapan masuk (database + broadcast + web push), sama pola
        // kayak laporan kerusakan aset utama. Yang lapor selalu admin
        // (route ini role:admin-only) jadi dia sendiri dikecualikan dari
        // penerima biar gak notif diri sendiri.
        // try-catch: laporan yang SUDAH tersimpan di atas jangan ikut gagal
        // kalau notif error.
        try {
            Notification::send(
                User::whereIn('role', ['manajer', 'hr', 'admin'])
                    ->where('id', '!=', auth()->id())
                    ->get(),
                new AsetKelengkapanKerusakanDilaporkan($asetKelengkapan, $asetIndukLabel, auth()->user()->name)
            );
        } catch (\Throwable $e) {
            Log::error('Gagal mengirim notifikasi laporan kerusakan kelengkapan', [
                'aset_kelengkapan_id' => $asetKelengkapan->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return response()->json(
            $asetKelengkapan->fresh()->load(['aset', 'lokasiKantor', 'supplier'])
        );
    }
    public function pasangPengganti(Request $request, AsetKelengkapan $asetKelengkapan)
    {
        if ($asetKelengkapan->status !== 'tersedia') {
            return response()->json([
                'message' => 'Kelengkapan ini tidak tersedia untuk dipasang.',
            ], 422);
        }

        $validated = $request->validate([
            'aset_id' => 'required|exists:aset,id',
        ]);

        DB::transaction(function () use ($asetKelengkapan, $validated) {
            $adaPemakaiAktif = AsetPemakai::where('aset_id', $validated['aset_id'])
                ->where('status', 'disetujui')
                ->whereNull('tanggal_pengembalian')
                ->exists();

            $asetKelengkapan->update([
                'aset_id' => $validated['aset_id'],
                'status'  => $adaPemakaiAktif ? 'dipakai' : 'tersedia',
            ]);
        });

        return response()->json(
            $asetKelengkapan->fresh()->load(['aset', 'lokasiKantor', 'supplier'])
        );
    }
    public function rusak(Request $request)
    {
        $query = AsetKelengkapan::query()
            ->where('status', 'rusak');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('kode_kelengkapan', 'like', "%{$search}%")
                ->orWhere('nama', 'like', "%{$search}%")
                ->orWhere('merek', 'like', "%{$search}%");
            });
        }

        $data = $query
            ->with(['aset', 'lokasiKantor', 'supplier'])
            ->orderByDesc('tanggal_rusak')
            ->paginate($request->query('per_page', 15));

        return response()->json($data);
    }

    protected function validasi(Request $request, ?AsetKelengkapan $asetKelengkapan = null): array
    {
        $request->merge([
            'serial_number' => $request->serial_number === '' ? null : $request->serial_number,
            // Multipart FormData ngirim '' (bukan field ilang) pas dikosongkan
            // dari FE, jadi harus dinormalisasi manual ke null biar exists:...
            // gak ikut divalidasi & biar update() beneran ngosongin kolomnya
            // (bukan malah dibiarin ke value lama krn key gak ke-set).
            'aset_id' => $request->aset_id === '' ? null : $request->aset_id,
            'lokasi_kantor_id' => $request->lokasi_kantor_id === '' ? null : $request->lokasi_kantor_id,
        ]);

        return $request->validate([
            'aset_id' => 'nullable|exists:aset,id',
            'lokasi_kantor_id' => 'nullable|exists:lokasi_kantor,id',
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
            'status' => 'nullable|in:tersedia,dipakai,rusak',
            'tanggal_rusak' => 'nullable|date',
        ]);
    }
}