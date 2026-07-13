<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatbotController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BarangController;
use App\Http\Controllers\MutasiBarangController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\AbsensiController;
use App\Http\Controllers\KategoriBarangController;
use App\Http\Controllers\DepartemenController;
use App\Http\Controllers\JabatanController;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
Route::post('/resend-otp', [AuthController::class, 'resendOtp']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
});

Route::middleware(['auth:sanctum', 'role:karyawan,manajer,hr,admin'])->group(function () {
    Route::prefix('absensi')->group(function () {
        Route::get('/karyawan', [AbsensiController::class, 'karyawan']);
        Route::get('/hari-ini', [AbsensiController::class, 'hariIni']);
        Route::get('/riwayat', [AbsensiController::class, 'riwayat']);
        Route::post('/scan', [AbsensiController::class, 'scan']);
    });
    Route::get('/karyawan/kode/{kode}', [AbsensiController::class, 'getByKode']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/chat', [ChatbotController::class, 'ask']);
    Route::get('/mutasi-barang', [MutasiBarangController::class, 'index']);
    Route::get('/barang', [BarangController::class, 'index']);
    Route::get('/barang/kode/{kode_barang}', [BarangController::class, 'findByKode']);
    Route::get('/barang/{id}', [BarangController::class, 'show']);
    Route::post('/barang', [BarangController::class, 'store']);
    Route::post('/barang/{barang}/masuk', [BarangController::class, 'scanMasuk']);
    Route::post('/barang/{barang}/keluar', [BarangController::class, 'scanKeluar']);
    Route::get('/barang/{barang}/riwayat', [BarangController::class, 'riwayat']);
    Route::get('/karyawan', [UserController::class, 'index']);

    Route::prefix('ticketing')->group(function () {
        Route::get('/', [TicketController::class, 'index']);
        Route::get('/history', [TicketController::class, 'history']);
        Route::post('/', [TicketController::class, 'store']);
        Route::get('/{ticket}', [TicketController::class, 'show']);
    });
});

Route::middleware(['auth:sanctum', 'role:manajer,hr,admin'])->group(function () {
    Route::put('/ticketing/{ticket}/status', [TicketController::class, 'updateStatus']);
});

Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
    Route::get('/karyawan/{user}', [UserController::class, 'edit']);
    Route::put('/karyawan/{user}', [UserController::class, 'update']);
    Route::delete('/karyawan/{user}', [UserController::class, 'destroy']);
    Route::post('/karyawan', [UserController::class, 'store']);
});

Route::middleware(['auth:sanctum', 'role:admin,hr'])->group(function () {
    Route::apiResource('kategori-barang', KategoriBarangController::class)->except(['show']);
    Route::apiResource('departemen', DepartemenController::class)->except(['show']);
    Route::apiResource('jabatan', JabatanController::class)->except(['show']);
});

Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
    Route::get('/audit-log', [AuditLogController::class, 'index']);
    Route::get('/audit-log/trash', [AuditLogController::class, 'trash']);
});