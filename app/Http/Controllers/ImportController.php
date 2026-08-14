<?php

namespace App\Http\Controllers;

use App\Imports\AsetBuktiImport;
use App\Imports\AsetBuktiRapiImport;
use App\Imports\AsetPenangananImport;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\DB;

class ImportController extends Controller
{
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:10240', // max 10MB
        ]);

        DB::beginTransaction();
        try {
            $import = new AsetBuktiImport();
            Excel::import($import, $request->file('file'));

            if (count($import->getErrors()) > 0) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'errors'  => $import->getErrors(),
                ], 422);
            }

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => "Berhasil import {$import->getRowCount()} baris data",
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal import: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * POST /api/import-aset-rapi
     * Import format BARU "Data Aset Rapi" -- 1 baris Excel = 1 barang,
     * dengan kolom "Kategori" eksplisit (Aset Utama / Kelengkapan).
     * Lihat AsetBuktiRapiImport buat detail format kolom yang diharapkan.
     * Endpoint terpisah dari import() lama supaya file format LAMA
     * (Nama Barang 1..4 per baris) tetap bisa diimport tanpa perubahan.
     */
    public function importRapi(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:10240', // max 10MB
        ]);

        DB::beginTransaction();
        try {
            $import = new AsetBuktiRapiImport();
            Excel::import($import, $request->file('file'));

            if (count($import->getErrors()) > 0) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'errors'  => $import->getErrors(),
                ], 422);
            }

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => "Berhasil import {$import->getRowCount()} baris data",
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal import: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * POST /api/import-aset-penanganan
     * Bulk import laporan penanganan aset yang SUDAH SELESAI -- cuma
     * dipakai dari tombol Import di tab "Berhasil Diperbaiki" & "Rusak
     * Berat" pada Forum Penanganan Aset. Lihat AsetPenangananImport buat
     * detail format kolom yang diharapkan.
     */
    public function importAsetPenanganan(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:10240', // max 10MB
        ]);

        DB::beginTransaction();
        try {
            $import = new AsetPenangananImport();
            Excel::import($import, $request->file('file'));

            if (count($import->getErrors()) > 0) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'errors'  => $import->getErrors(),
                    'message' => $import->getErrors()[0] ?? 'Gagal import.',
                ], 422);
            }

            if ($import->getRowCount() === 0) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak ada baris data yang berhasil dibaca dari file.',
                ], 422);
            }

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => "Berhasil import {$import->getRowCount()} laporan penanganan aset",
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal import: ' . $e->getMessage(),
            ], 422);
        }
    }
}