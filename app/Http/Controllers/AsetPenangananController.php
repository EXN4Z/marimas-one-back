<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\GeneratesStrukNumber;
use App\Models\Aset;
use App\Models\AsetPenanganan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AsetPenangananController extends Controller
{
    use GeneratesStrukNumber;

    /**
     * Riwayat penanganan. Filter: ?aset_id=  ?aset_peminjaman_id=  ?status=proses|selesai
     */
    public function index(Request $request)
    {
        $query = AsetPenanganan::with(['aset:id,kode_aset,merk,tipe'])->latest('tanggal_lapor');

        if ($request->filled('aset_id')) {
            $query->where('aset_id', $request->query('aset_id'));
        }

        if ($request->filled('aset_peminjaman_id')) {
            $query->where('aset_peminjaman_id', $request->query('aset_peminjaman_id'));
        }

        if ($request->query('status') === 'proses') {
            $query->whereNull('hasil');
        } elseif ($request->query('status') === 'selesai') {
            $query->whereNotNull('hasil');
        }

        return response()->json($query->get());
    }

    /**
     * Lapor kerusakan pada aset. Menandai aset berstatus 'diperbaiki' sampai
     * penanganan ini diselesaikan lewat endpoint selesai().
     */
    public function store(Request $request, Aset $aset)
    {
        $validated = $request->validate([
            'aset_peminjaman_id' => 'nullable|exists:aset_peminjaman,id',
            'jenis_kerusakan' => 'required|in:software,hardware',
            'keluhan' => 'required|string',
            'tanggal_lapor' => 'required|date',
            'kondisi' => 'nullable|in:rusak_ringan,rusak_berat',
            'catatan' => 'nullable|string',
        ]);

        if ($aset->status === 'dihapus') {
            throw ValidationException::withMessages([
                'aset_id' => ["Aset {$aset->kode_aset} sudah dihapus dari inventaris."],
            ]);
        }

        $adaYangBelumSelesai = $aset->penanganan()->whereNull('hasil')->exists();
        if ($adaYangBelumSelesai) {
            throw ValidationException::withMessages([
                'aset_id' => ["Aset {$aset->kode_aset} masih punya laporan penanganan yang belum selesai."],
            ]);
        }

        return DB::transaction(function () use ($validated, $aset) {
            $penanganan = $aset->penanganan()->create([
                'aset_peminjaman_id' => $validated['aset_peminjaman_id'] ?? null,
                'jenis_kerusakan' => $validated['jenis_kerusakan'],
                'keluhan' => $validated['keluhan'],
                'tanggal_lapor' => $validated['tanggal_lapor'],
                'catatan' => $validated['catatan'] ?? null,
            ]);

            $aset->update([
                'status' => 'diperbaiki',
                'kondisi' => $validated['kondisi'] ?? 'rusak_ringan',
            ]);

            return response()->json([
                'message' => "Laporan kerusakan untuk aset {$aset->kode_aset} berhasil dicatat.",
                'penanganan' => $penanganan->load('aset'),
            ], 201);
        });
    }

    /**
     * Selesaikan penanganan: isi biaya jasa + komponen, hasil akhir, lalu
     * cetak struk. Kalau hasilnya 'diperbaiki', aset balik ke status
     * 'dipinjam' (kalau lagi ada peminjaman aktif yg nyambung) atau
     * 'tersedia'. Kalau 'rusak_berat', aset tetap 'diperbaiki' menunggu
     * keputusan lanjut (mis. write-off) dari admin.
     */
    public function selesai(Request $request, AsetPenanganan $penanganan)
    {
        $validated = $request->validate([
            'tanggal_selesai' => 'required|date',
            'harga_jasa' => 'required|numeric|min:0',
            'biaya_komponen' => 'required|numeric|min:0',
            'hasil' => 'required|in:diperbaiki,rusak_berat',
            'catatan' => 'nullable|string',
        ]);

        if ($penanganan->hasil !== null) {
            throw ValidationException::withMessages([
                'hasil' => ['Penanganan ini sudah diselesaikan sebelumnya.'],
            ]);
        }

        return DB::transaction(function () use ($validated, $penanganan) {
            $noStruk = $this->generateNoStruk('SVC', 'aset_penanganan', 'no_struk');

            $penanganan->update([
                'tanggal_selesai' => $validated['tanggal_selesai'],
                'harga_jasa' => $validated['harga_jasa'],
                'biaya_komponen' => $validated['biaya_komponen'],
                'hasil' => $validated['hasil'],
                'no_struk' => $noStruk,
                'catatan' => $validated['catatan'] ?? $penanganan->catatan,
            ]);

            $aset = $penanganan->aset;

            if ($validated['hasil'] === 'diperbaiki') {
                $adaPeminjamanAktif = $penanganan->aset_peminjaman_id
                    && $penanganan->peminjaman
                    && $penanganan->peminjaman->status === 'dipinjam';

                $aset->update([
                    'kondisi' => 'baik',
                    'status' => $adaPeminjamanAktif ? 'dipinjam' : 'tersedia',
                ]);
            } else {
                // rusak_berat: kondisi dicatat, status dibiarkan 'diperbaiki'
                // sampai admin memutuskan langkah selanjutnya.
                $aset->update(['kondisi' => 'rusak_berat']);
            }

            return response()->json([
                'message' => "Penanganan aset {$aset->kode_aset} berhasil diselesaikan.",
                'penanganan' => $penanganan->fresh()->load('aset', 'peminjaman'),
            ]);
        });
    }
}
