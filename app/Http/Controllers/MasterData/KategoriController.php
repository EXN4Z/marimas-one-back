<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;

use App\Models\MasterData\Kategori;

/**
 * Read-only dengan sengaja: Kategori cuma 2 baris fix ("Barang Utama" &
 * "Kelengkapan"), di-seed langsung lewat migration -- BUKAN dikelola admin
 * lewat CRUD Master Data (beda sama MasterKategoriController). Kode-nya
 * dipakai di banyak tempat di kode program (validasi parent_id, filter
 * index, dst), jadi sengaja gak ada store/update/destroy di sini biar gak
 * ada yang kepencet ubah/hapus dari UI.
 */
class KategoriController extends Controller
{
    // GET /api/kategori — dipakai buat dropdown pilih Kategori waktu
    // bikin/edit Master Kategori.
    public function index()
    {
        return response()->json(Kategori::orderBy('id')->get());
    }
}