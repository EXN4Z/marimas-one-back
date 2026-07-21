<?php

namespace App\Http\Controllers;

use App\Models\AsetPenanganan;
use App\Models\AsetPemakai;
use Illuminate\Http\Request;

class AsetPenangananController extends Controller
{
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
            'jenis_kerusakan' => 'required|string|max:255',
            'keluhan' => 'required|string',
        ]);

        $user = $request->user();

        // pastiin user emang lagi pegang aset ini (pemakai aktif = tanggal_pengembalian masih null)
        $pemakai = AsetPemakai::where('aset_id', $validated['aset_id'])
            ->whereNull('tanggal_pengembalian')
            ->whereHas('pekerja', fn ($q) => $q->where('user_id', $user->id))
            ->first();

        if (!$pemakai) {
            return response()->json(['message' => 'Kamu tidak sedang memegang aset ini.'], 403);
        }

        $penanganan = AsetPenanganan::create([
            'aset_id' => $validated['aset_id'],
            'aset_pemakai_id' => $pemakai->id,
            'jenis_kerusakan' => $validated['jenis_kerusakan'],
            'keluhan' => $validated['keluhan'],
            'tanggal_lapor' => now(),
        ]);

        return response()->json($penanganan->load(['aset.jenis', 'pemakai.pekerja.user']), 201);
    }
}