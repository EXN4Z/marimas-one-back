<?php

namespace App\Http\Controllers;

use App\Models\Barang;
use App\Models\Peminjaman;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class PeminjamanController extends Controller
{
    /**
     * GET /api/peminjaman
     * Riwayat global (dipinjam + dikembalikan), terbaru duluan. Buat panel riwayat di halaman utama.
     */
    public function index(Request $request)
    {
        $limit = (int) $request->query('limit', 10);

        $riwayat = Peminjaman::with(['user:id,name', 'barang:id,nama'])
            ->orderByRaw('COALESCE(tanggal_kembali_aktual, tanggal_pinjam) DESC')
            ->limit($limit)
            ->get();

        return response()->json($riwayat);
    }

    /**
     * GET /api/barang/{barang}/peminjaman?status=dipinjam
     * Daftar peminjam aktif untuk satu barang tertentu.
     */
    public function aktifByBarang(Request $request, Barang $barang)
    {
        $status = $request->query('status', 'dipinjam');

        $data = $barang->peminjaman()
            ->with('user:id,name')
            ->where('status', $status)
            ->orderByDesc('tanggal_pinjam')
            ->get();

        return response()->json($data);
    }

    /**
     * POST /api/barang/{barang}/pinjamkan
     * Body: { items: [{ user_id, jumlah }, ...], tanggal_kembali_rencana }
     * Bikin beberapa peminjaman sekaligus dalam 1 kali submit (1 tanggal rencana kembali dipakai bersama).
     */
    public function pinjamkan(Request $request, Barang $barang)
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.user_id' => ['required', 'distinct', 'exists:users,id'],
            'items.*.jumlah' => ['required', 'integer', 'min:1'],
            'tanggal_kembali_rencana' => ['required', 'date', 'after_or_equal:today'],
        ]);

        // stok tersedia = stok total - jumlah yang lagi dipinjam (belum dikembalikan)
        $sedangDipinjam = $barang->peminjaman()
            ->where('status', 'dipinjam')
            ->sum('jumlah');
        $tersedia = $barang->stok - $sedangDipinjam;

        $totalDiminta = collect($validated['items'])->sum('jumlah');

        if ($totalDiminta > $tersedia) {
            return response()->json([
                'message' => "Total yang diminta ({$totalDiminta}) melebihi stok tersedia ({$tersedia} {$barang->satuan}).",
            ], 422);
        }

        $peminjamanList = DB::transaction(function () use ($barang, $validated) {
            $hasil = collect();
            foreach ($validated['items'] as $item) {
                $peminjaman = Peminjaman::create([
                    'barang_id' => $barang->id,
                    'user_id' => $item['user_id'],
                    'jumlah' => $item['jumlah'],
                    'tanggal_pinjam' => now(),
                    'tanggal_kembali_rencana' => $validated['tanggal_kembali_rencana'],
                    'status' => 'dipinjam',
                ]);
                $hasil->push($peminjaman);
            }
            return $hasil;
        });

        $ids = $peminjamanList->pluck('id');
        $peminjamanList = Peminjaman::with('user:id,name')
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get();

        return response()->json([
            'barang' => $barang->fresh(),
            'peminjaman' => $peminjamanList,
        ]);
    }

    /**
     * POST /api/peminjaman/{peminjaman}/kembalikan
     */
    public function kembalikan(Peminjaman $peminjaman)
    {
        if ($peminjaman->status === 'dikembalikan') {
            return response()->json([
                'message' => 'Peminjaman ini sudah dikembalikan sebelumnya.',
            ], 422);
        }

        $peminjaman->update([
            'status' => 'dikembalikan',
            'tanggal_kembali_aktual' => now(),
        ]);

        $peminjaman->load('user:id,name');

        return response()->json([
            'barang' => $peminjaman->barang()->first()->fresh(),
            'peminjaman' => $peminjaman,
        ]);
    }
}