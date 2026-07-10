<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatbotController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BarangController;
use App\Http\Controllers\MutasiBarangController;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
Route::post('/resend-otp', [AuthController::class, 'resendOtp']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/chatbot', [ChatbotController::class, 'ask']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/chat', [ChatbotController::class, 'ask']);
    Route::get('/mutasi-barang', [MutasiBarangController::class, 'index']);
    Route::get('/barang', [BarangController::class, 'index']);
    Route::get('/barang/kode/{kode_barang}', [BarangController::class, 'findByKode']);
    Route::get('/barang/{id}', [BarangController::class, 'show']);
    Route::post('/barang', [BarangController::class, 'store']);
    Route::post('/barang/{id}/masuk', [BarangController::class, 'scanMasuk']);
    Route::post('/barang/{id}/keluar', [BarangController::class, 'scanKeluar']);
    Route::get('/barang/{id}/riwayat', [BarangController::class, 'riwayat']);
});