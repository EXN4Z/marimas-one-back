<?php

namespace App\Http\Controllers;

use App\Models\AsetPenanganan;
use App\Models\AsetPeminjaman;
use Illuminate\Http\Request;

class AsetPenangananController extends Controller
{
    // admin only (dicek di route middleware, lihat bawah)
    public function index()
    {
        $data = AsetPenanganan::with(['aset.jenis', 'peminjaman.pekerja.user'])
            ->orderByDesc('tanggal_lapor')
            ->get();

        return response()->json($data);
    }

    // peminjam lapor kerusakan aset yang sedang dia pinjam
    public function store(Request $request)
    {
        $validated = $request->validate([
            'aset_id' => 'required|exists:aset,id',
            'jenis_kerusakan' => 'required|in:software,hardware',
            'keluhan' => 'required|string',
        ]);

        $user = $request->user();

        // cek user emang lagi pegang aset ini via peminjaman aktif (status dipinjam)
        $peminjaman = AsetPeminjaman::where('aset_id', $validated['aset_id'])
            ->where('status', 'dipinjam')
            ->whereHas('pekerja', fn ($q) => $q->where('user_id', $user->id))
            ->first();

        // nullable: laporan kerusakan bisa juga muncul pas aset lagi nganggur (audit gudang)
        $penanganan = AsetPenanganan::create([
            'aset_id' => $validated['aset_id'],
            'aset_peminjaman_id' => $peminjaman->id ?? null,
            'jenis_kerusakan' => $validated['jenis_kerusakan'],
            'keluhan' => $validated['keluhan'],
            'tanggal_lapor' => now(),
        ]);

        return response()->json($penanganan->load(['aset.jenis', 'peminjaman.pekerja.user']), 201);
    }
}