<?php

namespace App\Http\Controllers\Organisasi;

use App\Http\Controllers\Controller;

use App\Models\Perusahaan;
use Illuminate\Http\Request;

// Mirror dari CabangController -- struktur & validasi field sama persis,
// tapi TANPA cek "masih ada pegawai/relasi" sebelum hapus, karena
// Perusahaan sengaja belum dikaitkan ke tabel lain (lihat model Perusahaan).
class PerusahaanController extends Controller
{
    // GET /api/perusahaan
    public function index()
    {
        $perusahaan = Perusahaan::orderBy('nama')->get();

        return response()->json($perusahaan);
    }

    // GET /api/perusahaan/{id}
    public function show($id)
    {
        $perusahaan = Perusahaan::findOrFail($id);

        return response()->json($perusahaan);
    }

    // POST /api/perusahaan
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nama' => 'required|string|max:150',
            'alamat' => 'required|string|max:1000',
            'telepon' => 'required|string|max:30',
            'link' => 'required|string|max:255',
        ], [
            'nama.required' => 'Kolom nama wajib diisi.',
            'alamat.required' => 'Kolom alamat wajib diisi.',
            'telepon.required' => 'Kolom nomor telepon wajib diisi.',
            'link.required' => 'Kolom link wajib diisi.',
        ]);

        $perusahaan = Perusahaan::create($validated);

        return response()->json($perusahaan, 201);
    }

    // PUT /api/perusahaan/{id}
    public function update(Request $request, $id)
    {
        $perusahaan = Perusahaan::findOrFail($id);

        $validated = $request->validate([
            'nama' => 'sometimes|required|string|max:150',
            'alamat' => 'sometimes|required|string|max:1000',
            'telepon' => 'sometimes|required|string|max:30',
            'link' => 'sometimes|required|string|max:255',
        ], [
            'nama.required' => 'Kolom nama wajib diisi.',
            'alamat.required' => 'Kolom alamat wajib diisi.',
            'telepon.required' => 'Kolom nomor telepon wajib diisi.',
            'link.required' => 'Kolom link wajib diisi.',
        ]);

        $perusahaan->update($validated);

        return response()->json($perusahaan);
    }

    // DELETE /api/perusahaan/{id}
    public function destroy($id)
    {
        $perusahaan = Perusahaan::findOrFail($id);
        $perusahaan->delete();

        return response()->json(['message' => 'Perusahaan berhasil dihapus.']);
    }
}