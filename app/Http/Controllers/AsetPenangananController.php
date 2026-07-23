<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\GeneratesStrukNumber;
use App\Models\AsetPemakai;
use App\Models\AsetPenanganan;
use App\Models\User;
use App\Notifications\AsetKerusakanDilaporkan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

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

        // cek user emang lagi pegang aset ini via pemakaian aktif (status disetujui, belum dikembalikan)
        $pemakai = AsetPemakai::where('aset_id', $validated['aset_id'])
            ->where('status', 'disetujui')
            ->whereNull('tanggal_pengembalian')
            ->whereHas('pekerja', fn ($q) => $q->where('user_id', $user->id))
            ->first();

        // nullable: laporan kerusakan bisa juga muncul pas aset lagi nganggur (audit gudang)
        $penanganan = AsetPenanganan::create([
            'aset_id' => $validated['aset_id'],
            'aset_pemakai_id' => $pemakai->id ?? null,
            'jenis_kerusakan' => $validated['jenis_kerusakan'],
            'keluhan' => $validated['keluhan'],
            'tanggal_lapor' => now(),
        ]);

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

            $asetPenanganan->update($validated);
        });

        return response()->json($asetPenanganan->fresh()->load(['aset.jenis', 'pemakai.pekerja.user']));
    }

    public function destroy(AsetPenanganan $asetPenanganan)
    {
        $asetPenanganan->delete();

        return response()->json(['message' => 'Laporan penanganan berhasil dihapus.']);
    }
}