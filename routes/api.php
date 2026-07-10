<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatbotController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BarangController;
use App\Http\Controllers\MutasiBarangController;
use App\Http\Controllers\UserController;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
Route::post('/resend-otp', [AuthController::class, 'resendOtp']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/chatbot', [ChatbotController::class, 'ask']);

Route::middleware(['auth:sanctum', 'role:karyawan,manajer,hr,admin'])->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);
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
});
Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
    Route::get('/karyawan/{user}', [UserController::class, 'edit']);
    Route::put('/karyawan/{user}', [UserController::class, 'update']);
    Route::delete('/karyawan/{user}', [UserController::class, 'destroy']);
    Route::post('/karyawan', [UserController::class, 'store']);
});