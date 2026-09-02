<?php

namespace App\Http\Controllers\Organisasi;

use App\Http\Controllers\Controller;

use App\Models\LokasiKantor;
use Illuminate\Http\Request;

class CabangController extends Controller
{
    // GET /api/cabang — daftar cabang + jumlah pegawai masing-masing
    public function index()
    {
        // BARU: withCount('karyawan') -- relasi baru yang udah filter
        // role != 'cabang' (lihat LokasiKantor::karyawan()), biar akun
        // cabang gak ikut kehitung sebagai pegawainya sendiri. Alias tetap
        // 'pekerja_count' biar frontend (CabangPage.tsx) gak perlu diubah.
        $cabang = LokasiKantor::withCount(['karyawan as pekerja_count'])
            ->orderBy('nama')
            ->get();

        return response()->json($cabang);
    }

    // GET /api/cabang/{id}
    public function show($id)
    {
        $cabang = LokasiKantor::withCount(['karyawan as pekerja_count'])->findOrFail($id);

        return response()->json($cabang);
    }

    // POST /api/cabang
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nama' => 'required|string|max:150',
            'alamat' => 'nullable|string|max:1000',
            'telepon' => 'nullable|string|max:30',
            'link' => 'required|string|max:255',
            // FIX: sebelumnya latitude/longitude gak divalidasi & gak ikut
            // ke $validated sama sekali, padahal kolomnya NOT NULL di DB --
            // request "Tambah Cabang" selalu gagal di step LokasiKantor::create().
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ], [
            'link.required' => 'Kolom link wajib diisi.',
            'latitude.required' => 'Latitude wajib diisi.',
            'longitude.required' => 'Longitude wajib diisi.',
        ]);

        $cabang = LokasiKantor::create($validated);
        $cabang->loadCount(['karyawan as pekerja_count']);

        return response()->json($cabang, 201);
    }

    // PUT /api/cabang/{id}
    public function update(Request $request, $id)
    {
        $cabang = LokasiKantor::findOrFail($id);

        $validated = $request->validate([
            'nama' => 'sometimes|required|string|max:150',
            'alamat' => 'nullable|string|max:1000',
            'telepon' => 'nullable|string|max:30',
            'link' => 'sometimes|required|string|max:255',
            // FIX: sama kayak store() -- latitude/longitude sebelumnya gak
            // divalidasi/di-update sama sekali.
            'latitude' => 'sometimes|required|numeric|between:-90,90',
            'longitude' => 'sometimes|required|numeric|between:-180,180',
        ]);

        $cabang->update($validated);
        $cabang->loadCount(['karyawan as pekerja_count']);

        return response()->json($cabang);
    }

    // DELETE /api/cabang/{id}
    public function destroy($id)
    {
        $cabang = LokasiKantor::withCount(['karyawan as pekerja_count'])->findOrFail($id);

        if ($cabang->pekerja_count > 0) {
            return response()->json([
                'message' => 'Cabang ini masih memiliki ' . $cabang->pekerja_count . ' pegawai. Pindahkan pegawai terlebih dahulu sebelum menghapus.',
            ], 422);
        }

        $cabang->delete();

        return response()->json(['message' => 'Cabang berhasil dihapus.']);
    }
}