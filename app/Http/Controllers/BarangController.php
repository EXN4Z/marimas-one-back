<?php

namespace App\Http\Controllers;

use App\Models\Barang;
use App\Models\MutasiBarang;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use	Illuminate\Validation\ValidationException;

class BarangController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return response()->json(Barang::orderBy('nama')->get());
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
        dd($request->all());
        $request->validate([
            'kode_barang' => 'required|string|unique:barang,kode_barang',
            'nama' => 'required|string',
            'kategori' => 'nullable|string',
            'satuan' => 'nullable|string',
            'stok' => 'nullable|integer|min:0',
            'stok_minimum' => 'nullable|integer|min:0',
        ]);

        $barang = Barang::create($request->all());

        return response()->json($barang, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Barang $barang)
    {
        if (!$barang) {
            return response()->json(['message' => 'Barang tidak ditemukan'], 404);
        }

        return response()->json($barang);
    }

    /**
     * Cari barang berdasarkan kode_barang hasil scan QR code.
     * Dipanggil dari frontend setelah kamera berhasil membaca QR.
     */
    public function findByKode(string $kode_barang)
    {
        $barang = Barang::where('kode_barang', $kode_barang)->first();

        if (!$barang) {
            return response()->json(['message' => 'Barang dengan kode tersebut tidak ditemukan'], 404);
        }

        return response()->json($barang);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function scanMasuk(Barang $barang, Request $request)
    {
        $request->validate([
            'jumlah' => 'required|integer|min:1',
            'catatan' => 'nullable|string',
        ]);
        if (!$barang) {
            return response()->json(['message' => 'Barang tidak ditemukan'], 404);
        }
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
    public function scanKeluar (Barang $barang, Request $request)
    {
        $request->validate([
            'jumlah' => 'required|integer|min:1',
            'catatan' => 'nullable|string',
        ]);
        if (!$barang) {
            return response()->json(['message' => 'Barang tidak ditemukan'], 404);
        }
        if ($barang->stok < $request->jumlah) {
            throw ValidationException::withMessages([
                'jumlah' => ['Stok barang tidak mencukupi. Stok saat ini: ' . $barang->stok],
            ]);
        }
        return DB::transaction(function () use ($request, $barang) {
            $stokSebelum = $barang->stok;
            $barang->stok -= $request->jumlah;
            $barang->save();

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

    public function riwayat($id) {
        $mutasi	=	MutasiBarang::where('barang_id',$id)
            ->with('user:id,name')
            ->latest()
            ->get();
        return response()->json($mutasi);
    }
    public function update(Request $request, Barang $barang)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Barang $barang)
    {
        //
    }
}