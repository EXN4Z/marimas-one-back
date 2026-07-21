<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\GeneratesStrukNumber;
use App\Models\Aset;
use App\Models\AsetPeminjaman;
use App\Models\AsetPenanganan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AsetPeminjamanController extends Controller
{
    use GeneratesStrukNumber;

    /**
     * Riwayat peminjaman. Filter: ?aset_id=  ?status=dipinjam|selesai
     */
    public function index(Request $request)
    {
        $query = AsetPeminjaman::with([
            'aset:id,kode_aset,merk,tipe,barang_id',
            'aset.barang:id,nama',
            'pekerja.user:id,name',
            'kelengkapanDibawa.kelengkapan:id,nama',
        ])->latest('tanggal_pinjam');

        if ($request->filled('aset_id')) {
            $query->where('aset_id', $request->query('aset_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $limit = (int) $request->query('limit', 20);

        return response()->json($query->limit($limit)->get());
    }

    /**
     * Pinjamkan 1 aset. Aset harus berstatus 'tersedia'.
     */
    public function store(Request $request, Aset $aset)
    {
        $validated = $request->validate([
            'pekerja_id' => 'nullable|exists:pekerja,id',
            'nik_peminjam' => 'nullable|string',
            'nama_peminjam' => 'required|string',
            'tanggal_pinjam' => 'required|date',
            'kondisi_saat_pinjam' => 'nullable|string',
            'catatan' => 'nullable|string',
            'kelengkapan_ids' => 'nullable|array',
            'kelengkapan_ids.*' => 'exists:aset_kelengkapan,id',
        ]);

        if ($aset->status !== 'tersedia') {
            throw ValidationException::withMessages([
                'aset_id' => ["Aset {$aset->kode_aset} sedang tidak tersedia (status: {$aset->status}), tidak bisa dipinjamkan."],
            ]);
        }

        return DB::transaction(function () use ($validated, $aset) {
            $noStruk = $this->generateNoStruk('PJM', 'aset_peminjaman', 'no_struk_pinjam');

            $peminjaman = AsetPeminjaman::create([
                'aset_id' => $aset->id,
                'pekerja_id' => $validated['pekerja_id'] ?? null,
                'nik_peminjam' => $validated['nik_peminjam'] ?? null,
                'nama_peminjam' => $validated['nama_peminjam'],
                'tanggal_pinjam' => $validated['tanggal_pinjam'],
                'kondisi_saat_pinjam' => $validated['kondisi_saat_pinjam'] ?? $aset->kondisi,
                'no_struk_pinjam' => $noStruk,
                'status' => 'dipinjam',
                'catatan' => $validated['catatan'] ?? null,
            ]);

            foreach ($validated['kelengkapan_ids'] ?? [] as $kelengkapanId) {
                $peminjaman->kelengkapanDibawa()->create([
                    'aset_kelengkapan_id' => $kelengkapanId,
                ]);
            }

            $aset->update(['status' => 'dipinjam']);

            return response()->json([
                'message' => "Aset {$aset->kode_aset} berhasil dipinjamkan kepada {$peminjaman->nama_peminjam}.",
                'peminjaman' => $peminjaman->load(['aset', 'pekerja.user:id,name', 'kelengkapanDibawa.kelengkapan']),
            ], 201);
        });
    }

    /**
     * Kembalikan aset yang sedang dipinjam. Syarat: pemohon HARUS menyertakan
     * no_struk_pinjam yang cocok dengan struk pinjam asli, sebagai bukti bahwa
     * pengembalian ini memang menutup peminjaman yang benar.
     */
    public function kembalikan(Request $request, AsetPeminjaman $peminjaman)
    {
        $validated = $request->validate([
            'no_struk_pinjam' => 'required|string',
            'tanggal_pengembalian' => 'required|date',
            'kondisi_saat_kembali' => 'nullable|in:baik,rusak_ringan,rusak_berat',
            'catatan' => 'nullable|string',
        ]);

        if ($peminjaman->status !== 'dipinjam') {
            throw ValidationException::withMessages([
                'status' => ['Peminjaman ini sudah selesai / sudah dikembalikan sebelumnya.'],
            ]);
        }

        if ($validated['no_struk_pinjam'] !== $peminjaman->no_struk_pinjam) {
            throw ValidationException::withMessages([
                'no_struk_pinjam' => ['Nomor bukti pinjam tidak cocok. Pengembalian wajib menyertakan bukti pinjam yang benar.'],
            ]);
        }

        // Kalau masih ada laporan penanganan/perbaikan yang belum kelar buat
        // peminjaman ini, aset belum boleh dikembalikan ke inventaris.
        $adaPenangananBelumSelesai = AsetPenanganan::where('aset_peminjaman_id', $peminjaman->id)
            ->whereNull('hasil')
            ->exists();

        if ($adaPenangananBelumSelesai) {
            throw ValidationException::withMessages([
                'penanganan' => ['Masih ada laporan penanganan/perbaikan yang belum diselesaikan untuk peminjaman ini.'],
            ]);
        }

        return DB::transaction(function () use ($validated, $peminjaman) {
            $noStruk = $this->generateNoStruk('KBL', 'aset_peminjaman', 'no_struk_pengembalian');
            $kondisi = $validated['kondisi_saat_kembali'] ?? 'baik';

            $peminjaman->update([
                'status' => 'selesai',
                'tanggal_pengembalian' => $validated['tanggal_pengembalian'],
                'kondisi_saat_kembali' => $kondisi,
                'no_struk_pengembalian' => $noStruk,
                'catatan' => $validated['catatan'] ?? $peminjaman->catatan,
            ]);

            $peminjaman->aset->update([
                'status' => 'tersedia',
                'kondisi' => $kondisi,
            ]);

            return response()->json([
                'message' => "Aset {$peminjaman->aset->kode_aset} berhasil dikembalikan ke inventaris.",
                'peminjaman' => $peminjaman->fresh()->load(['aset', 'pekerja.user:id,name']),
            ]);
        });
    }
}
