<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\GeneratesStrukNumber;
use App\Models\Aset;
use App\Models\AsetPemakai;
use App\Models\AsetPenanganan;
use App\Models\User;
use App\Notifications\AsetKerusakanDilaporkan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class AsetPenangananController extends Controller
{
    use GeneratesStrukNumber;

    // admin only (dicek di route middleware, lihat bawah)
    public function index()
    {
        $data = AsetPenanganan::with(['aset.jenis', 'pemakai.pekerja.user'])
            ->orderByDesc('tanggal_lapor')
            ->get();

        return response()->json($data);
    }

    // peminjam lapor kerusakan aset yang sedang dia pakai
    public function store(Request $request)
    {
        $validated = $request->validate([
            'aset_id' => 'required|exists:aset,id',
            'jenis_kerusakan' => 'required|in:software,hardware',
            'keluhan' => 'required|string',
        ]);

        $user = $request->user();
        $aset = Aset::findOrFail($validated['aset_id']);

        // cegah lapor dobel kalau aset ini masih ada laporan yang belum selesai ditangani
        // (baik yang masih menunggu diterima admin, maupun yang sudah diterima/sedang diperbaiki)
        if (in_array($aset->status, ['menunggu_perbaikan', 'diperbaiki'], true)) {
            return response()->json([
                'message' => 'Aset ini sudah dilaporkan rusak dan sedang dalam penanganan.',
            ], 422);
        }

        // cegah lapor 2x: kalau aset ini masih ada laporan yang belum kelar
        // (belum ditandai selesai), tolak laporan baru.
        $sudahAdaLaporanAktif = AsetPenanganan::where('aset_id', $validated['aset_id'])
            ->whereNull('tanggal_selesai')
            ->exists();

        if ($sudahAdaLaporanAktif) {
            throw ValidationException::withMessages([
                'aset_id' => 'Aset ini sudah ada laporan kerusakan yang masih diproses. Tunggu sampai selesai sebelum lapor lagi.',
            ]);
        }

        // cek user emang lagi pegang aset ini via pemakaian aktif (status disetujui, belum dikembalikan)
        $pemakai = AsetPemakai::where('aset_id', $validated['aset_id'])
            ->where('status', 'disetujui')
            ->whereNull('tanggal_pengembalian')
            ->whereHas('pekerja', fn ($q) => $q->where('user_id', $user->id))
            ->first();

        $penanganan = DB::transaction(function () use ($validated, $pemakai) {
            // nullable: laporan kerusakan bisa juga muncul pas aset lagi nganggur (audit gudang)
            $penanganan = AsetPenanganan::create([
                'aset_id' => $validated['aset_id'],
                'aset_pemakai_id' => $pemakai->id ?? null,
                'jenis_kerusakan' => $validated['jenis_kerusakan'],
                'keluhan' => $validated['keluhan'],
                'tanggal_lapor' => now(),
            ]);

            // aset langsung ganti status "menunggu_perbaikan" biar kelihatan di tabel
            // (dan biar tombol "Lapor Kerusakan" ilang, gak bisa dobel lapor)
            Aset::whereKey($validated['aset_id'])->update(['status' => 'menunggu_perbaikan']);

            return $penanganan;
        });

        // TAMBAH: notif ke manajer/hr/admin tiap ada laporan kerusakan aset masuk
        // (database + broadcast + web push, biar kekirim walau admin lagi di luar device)
        // try-catch: laporan yang SUDAH tersimpan di atas jangan ikut gagal kalau notif error
        try {
            Notification::send(
                User::whereIn('role', ['manajer', 'hr', 'admin'])->get(),
                new AsetKerusakanDilaporkan($penanganan->load(['aset.jenis', 'pemakai.pekerja.user']))
            );
        } catch (\Throwable $e) {
            Log::error('Gagal mengirim notifikasi laporan kerusakan aset', [
                'aset_penanganan_id' => $penanganan->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return response()->json($penanganan->load(['aset.jenis', 'pemakai.pekerja.user']), 201);
    }

    // admin: terima & mulai tangani laporan -> aset jadi "diperbaiki" (sedang diperbaiki)
    public function terima(AsetPenanganan $asetPenanganan)
    {
        if ($asetPenanganan->tanggal_diterima) {
            return response()->json(['message' => 'Laporan ini sudah diterima sebelumnya.'], 422);
        }

        if ($asetPenanganan->tanggal_selesai) {
            return response()->json(['message' => 'Laporan ini sudah selesai ditangani.'], 422);
        }

        DB::transaction(function () use ($asetPenanganan) {
            $asetPenanganan->update(['tanggal_diterima' => now()]);
            Aset::whereKey($asetPenanganan->aset_id)->update(['status' => 'diperbaiki']);
        });

        return response()->json($asetPenanganan->fresh()->load(['aset.jenis', 'pemakai.pekerja.user']));
    }

    // admin: tandai penanganan selesai + isi hasil/biaya, generate no_struk (dicek di route middleware, lihat bawah)
    public function update(Request $request, AsetPenanganan $asetPenanganan)
    {
        $validated = $request->validate([
            'tanggal_selesai' => 'nullable|date',
            'harga_jasa' => 'nullable|numeric|min:0',
            'biaya_komponen' => 'nullable|numeric|min:0',
            'hasil' => 'nullable|string',
            'catatan' => 'nullable|string',
        ]);

        // kalau tanggal_selesai gak dikirim eksplisit, anggap "tandai selesai sekarang"
        if (!$request->has('tanggal_selesai')) {
            $validated['tanggal_selesai'] = now();
        }

        DB::transaction(function () use ($asetPenanganan, $validated) {
            // struk cuma digenerate sekali, pas pertama kali ditandai selesai
            if (!$asetPenanganan->no_struk && ($validated['tanggal_selesai'] ?? null)) {
                $validated['no_struk'] = $this->generateNoStruk('PNG', 'aset_penanganan', 'no_struk');
            }

            // jaga-jaga: kalau admin langsung tandai selesai tanpa lewat tombol
            // "Terima" dulu, tetap isi tanggal_diterima biar datanya konsisten.
            if (($validated['tanggal_selesai'] ?? null) && !$asetPenanganan->tanggal_diterima) {
                $validated['tanggal_diterima'] = now();
            }

            $asetPenanganan->update($validated);

            if ($validated['tanggal_selesai'] ?? null) {
                // FIX: hasil "rusak_berat" sebelumnya gak pernah dicek di sini,
                // jadi aset selalu dibalikin ke 'dipakai'/'tersedia' walau admin
                // udah pilih rusak berat di form. Sekarang dicek dulu hasil
                // akhirnya (pakai nilai yang baru disimpan, fallback ke nilai
                // lama kalau field 'hasil' gak dikirim di request ini).
                $hasilAkhir = $validated['hasil'] ?? $asetPenanganan->hasil;

                if ($hasilAkhir === 'rusak_berat') {
                    // Rusak berat: aset TIDAK boleh balik ke sirkulasi peminjaman
                    // (tersedia/dipakai) — dikunci di status 'rusak' sampai admin
                    // menindaklanjuti (jual/buang/hapus dari inventaris).
                    Aset::whereKey($asetPenanganan->aset_id)->update(['status' => 'rusak']);
                } else {
                    // balikin status aset ke normal begitu ditandai selesai: kalau masih
                    // ada pemakai aktif yang belum ngembaliin ya "dipakai" lagi, kalau
                    // enggak ya balik "tersedia" — bukan asal "tersedia" biar gak nyalahin
                    // data peminjaman yang masih jalan.
                    $masihDipakai = AsetPemakai::where('aset_id', $asetPenanganan->aset_id)
                        ->where('status', 'disetujui')
                        ->whereNull('tanggal_pengembalian')
                        ->exists();

                    Aset::whereKey($asetPenanganan->aset_id)
                        ->update(['status' => $masihDipakai ? 'dipakai' : 'tersedia']);
                }
            }
        });

        return response()->json($asetPenanganan->fresh()->load(['aset.jenis', 'pemakai.pekerja.user']));
    }

    public function destroy(AsetPenanganan $asetPenanganan)
    {
        $asetPenanganan->delete();

        return response()->json(['message' => 'Laporan penanganan berhasil dihapus.']);
    }
}