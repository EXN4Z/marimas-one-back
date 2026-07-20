<?php

namespace App\Http\Controllers;

use App\Models\Barang;
use App\Models\MutasiBarang;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use App\Notifications\StokBarangRendah;

class BarangController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return response()->json(
            Barang::with('kategoriBarang:id,nama')->orderBy('nama')->get()
        );
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nama' => 'required|string',
            'kategori_id' => 'nullable|exists:kategori_barang,id',
            'satuan' => 'nullable|string',
            'stok' => 'nullable|integer|min:0',
            'stok_minimum' => 'nullable|integer|min:0',
        ]);

        // kode_barang sengaja gak dikirim, biar trigger DB yang generate
        $barang = Barang::create($validated);

        return response()->json($barang->load('kategoriBarang:id,nama'), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Barang $barang)
    {
        if (!$barang) {
            return response()->json(['message' => 'Barang tidak ditemukan'], 404);
        }

        return response()->json($barang->load('kategoriBarang:id,nama'));
    }

    /**
     * Cari barang berdasarkan kode_barang hasil scan QR code.
     */
    public function findByKode(string $kode_barang)
    {
        $barang = Barang::where('kode_barang', $kode_barang)->first();

        if (!$barang) {
            return response()->json(['message' => 'Barang dengan kode tersebut tidak ditemukan'], 404);
        }

        return response()->json($barang->load('kategoriBarang:id,nama'));
    }

    public function scanMasuk(Barang $barang, Request $request)
    {
        $request->validate([
            'jumlah' => 'required|integer|min:1',
            'catatan' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($request, $barang) {
            $stokSebelum = $barang->stok;
            $barang->stok += $request->jumlah;
            $barang->save();

            $mutasi = MutasiBarang::create([
                'barang_id' => $barang->id,
                'user_id' => $request->user()->id,
                'tipe' => 'masuk',
                'jumlah' => $request->jumlah,
                'stok_sebelum' => $stokSebelum,
                'stok_sesudah' => $barang->stok,
                'catatan' => $request->catatan,
            ]);

            return response()->json([
                'message' => "Barang {$barang->nama} berhasil ditambahkan sebanyak {$request->jumlah}.",
                'barang' => $barang,
                'mutasi' => $mutasi,
            ]);
        });
    }

    public function scanKeluar(Barang $barang, Request $request)
    {
        $request->validate([
            'jumlah' => 'required|integer|min:1',
            'catatan' => 'nullable|string',
        ]);

        if ($barang->stok < $request->jumlah) {
            throw ValidationException::withMessages([
                'jumlah' => ['Stok barang tidak mencukupi. Stok saat ini: ' . $barang->stok],
            ]);
        }

        return DB::transaction(function () use ($request, $barang) {
            $stokSebelum = $barang->stok;
            $barang->stok -= $request->jumlah;
            $barang->save();

            // TAMBAH: notif ke admin & hr cuma pas stok BARU AJA nyampe/nembus batas minimum,
            // biar gak spam notif tiap kali scan keluar selama masih di bawah minimum.
            $barusajaMenipis = $stokSebelum > $barang->stok_minimum && $barang->stok <= $barang->stok_minimum;
            if ($barusajaMenipis) {
                Notification::send(
                    User::whereIn('role', ['admin', 'hr'])->get(),
                    new StokBarangRendah($barang)
                );
            }

            $mutasi = MutasiBarang::create([
                'barang_id' => $barang->id,
                'user_id' => $request->user()->id,
                'tipe' => 'keluar',
                'jumlah' => $request->jumlah,
                'stok_sebelum' => $stokSebelum,
                'stok_sesudah' => $barang->stok,
                'catatan' => $request->catatan,
            ]);

            return response()->json([
                'message' => "Barang {$barang->nama} berhasil dikurangi sebanyak {$request->jumlah}.",
                'barang' => $barang,
                'mutasi' => $mutasi,
            ]);
        });
    }

    public function riwayat($id)
    {
        $mutasi = MutasiBarang::where('barang_id', $id)
            ->with('user:id,name')
            ->latest()
            ->get();

        return response()->json($mutasi);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Barang $barang)
    {
        $validated = $request->validate([
            'nama' => 'sometimes|required|string',
            'kategori_id' => 'nullable|exists:kategori_barang,id',
            'satuan' => 'sometimes|required|string',
            'stok_minimum' => 'nullable|integer|min:0',
        ]);

        $barang->update($validated);

        return response()->json($barang->load('kategoriBarang:id,nama'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Barang $barang)
    {
        MutasiBarang::where('barang_id', $barang->id)->delete();
        $barang->delete();

        return response()->json(['message' => "Barang {$barang->nama} berhasil dihapus."]);
    }
}