<?php

namespace App\Http\Controllers;

use App\Models\LokasiKantor;
use Illuminate\Http\Request;

class CabangController extends Controller
{
    // GET /api/cabang — daftar cabang + jumlah pegawai masing-masing
    public function index()
    {
        $cabang = LokasiKantor::withCount('pekerja')
            ->orderBy('nama')
            ->get();

        return response()->json($cabang);
    }

    // GET /api/cabang/{id}
    public function show($id)
    {
        $cabang = LokasiKantor::withCount('pekerja')->findOrFail($id);

        return response()->json($cabang);
    }

    // POST /api/cabang
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nama' => 'required|string|max:150',
            'alamat' => 'nullable|string|max:1000',
            'telepon' => 'nullable|string|max:30',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ]);

        $cabang = LokasiKantor::create($validated);
        $cabang->loadCount('pekerja');

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
            'latitude' => 'sometimes|required|numeric|between:-90,90',
            'longitude' => 'sometimes|required|numeric|between:-180,180',
        ]);

        $cabang->update($validated);
        $cabang->loadCount('pekerja');

        return response()->json($cabang);
    }

    // DELETE /api/cabang/{id}
    public function destroy($id)
    {
        $cabang = LokasiKantor::withCount('pekerja')->findOrFail($id);

        if ($cabang->pekerja_count > 0) {
            return response()->json([
                'message' => 'Cabang ini masih memiliki ' . $cabang->pekerja_count . ' pegawai. Pindahkan pegawai terlebih dahulu sebelum menghapus.',
            ], 422);
        }

        $cabang->delete();

        return response()->json(['message' => 'Cabang berhasil dihapus.']);
    }
}