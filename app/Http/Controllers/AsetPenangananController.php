<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\GeneratesStrukNumber;
use App\Models\Aset;
use App\Models\AsetPemakai;
use App\Models\AsetPenanganan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        // cek user emang lagi pegang aset ini via pemakaian aktif (status disetujui, belum dikembalikan)
        $pemakai = AsetPemakai::where('aset_id', $validated['aset_id'])
            ->where('status', 'disetujui')
            ->whereNull('tanggal_pengembalian')
            ->whereHas('pekerja', fn ($q) => $q->where('user_id', $user->id))
            ->first();

        $penanganan = DB::transaction(function () use ($validated, $pemakai, $aset) {
            // nullable: laporan kerusakan bisa juga muncul pas aset lagi nganggur (audit gudang)
            $penanganan = AsetPenanganan::create([
                'aset_id' => $validated['aset_id'],
                'aset_pemakai_id' => $pemakai->id ?? null,
                'jenis_kerusakan' => $validated['jenis_kerusakan'],
                'keluhan' => $validated['keluhan'],
                'tanggal_lapor' => now(),
            ]);

            // TAMBAH: begitu ada laporan kerusakan baru, status aset di tabel inventaris
            // langsung berubah jadi "menunggu_perbaikan" — kelihatan baik oleh admin
            // maupun karyawan, sampai admin menerima & mulai menangani laporannya.
            $aset->update(['status' => 'menunggu_perbaikan']);

            return $penanganan;
        });

        return response()->json($penanganan->load(['aset.jenis', 'pemakai.pekerja.user']), 201);
    }

    // BARU: admin terima/mulai tangani laporan kerusakan -> status aset jadi "diperbaiki"
    public function terima(AsetPenanganan $asetPenanganan)
    {
        if ($asetPenanganan->tanggal_diterima) {
            return response()->json([
                'message' => 'Laporan ini sudah diterima sebelumnya.',
            ], 422);
        }

        if ($asetPenanganan->tanggal_selesai) {
            return response()->json([
                'message' => 'Laporan ini sudah ditandai selesai.',
            ], 422);
        }

        DB::transaction(function () use ($asetPenanganan) {
            $asetPenanganan->update(['tanggal_diterima' => now()]);
            $asetPenanganan->aset()->update(['status' => 'diperbaiki']);
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

            // TAMBAH: begitu perbaikan ditandai selesai, status aset di tabel inventaris
            // balik lagi — jadi "dipakai" kalau masih ada pemakai aktif, atau "tersedia" kalau tidak.
            if ($validated['tanggal_selesai'] ?? null) {
                $aset = $asetPenanganan->aset;
                $masihDipakai = AsetPemakai::where('aset_id', $aset->id)
                    ->where('status', 'disetujui')
                    ->whereNull('tanggal_pengembalian')
                    ->exists();

                $aset->update(['status' => $masihDipakai ? 'dipakai' : 'tersedia']);
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