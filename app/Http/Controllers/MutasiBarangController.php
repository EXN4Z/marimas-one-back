<?php

namespace App\Http\Controllers;

use App\Models\MutasiBarang;
use Illuminate\Http\Request;

class MutasiBarangController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $limit = (int) $request->query('limit', 10);

        $mutasi = MutasiBarang::with(['barang:id,nama,satuan', 'user:id,name'])
            ->latest()
            ->limit($limit)
            ->get();

        return response()->json($mutasi);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(MutasiBarang $mutasiBarang)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(MutasiBarang $mutasiBarang)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, MutasiBarang $mutasiBarang)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(MutasiBarang $mutasiBarang)
    {
        //
    }
}