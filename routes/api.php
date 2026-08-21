<?php

use App\Http\Controllers\Auth\AuthController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Karyawan\UserController;
use App\Http\Controllers\Organisasi\DepartemenController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Dashboard\DashboardController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Karyawan\AdminUserController;
use App\Http\Controllers\Inventaris\SupplierController;
use App\Http\Controllers\Inventory\InventoryController;
use App\Http\Controllers\Inventory\InventoryPemakaiController;
use App\Http\Controllers\Inventory\InventoryPenangananController;
use App\Http\Controllers\Organisasi\CabangController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\ImportController;
use App\Models\AsetKelengkapan;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
Route::post('/resend-otp', [AuthController::class, 'resendOtp']);
Route::post('/login', [AuthController::class, 'login']);

Broadcast::routes(['middleware' => ['auth:sanctum']]);

Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
    Route::apiResource('cabang', CabangController::class);
});

Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);
    Route::put('/change-password', [AuthController::class, 'changePassword']);

    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::post('/{id}/read', [NotificationController::class, 'markAsRead']);
        Route::post('/read-all', [NotificationController::class, 'markAllAsRead']);
        Route::delete('/{id}', [NotificationController::class, 'destroy']);
    });

    Route::post('/push-subscriptions', [PushSubscriptionController::class, 'store']);
    Route::delete('/push-subscriptions', [PushSubscriptionController::class, 'destroy']);
});
Route::middleware(['auth:sanctum', 'role:admin'])->post('/admin/users/{id}/set-password', [AdminUserController::class, 'setPassword']);

Route::middleware(['auth:sanctum', 'role:karyawan,manajer,hr,admin'])->group(function () {
    Route::post('/aset-penanganan', [InventoryPenangananController::class, 'store']); // karyawan: lapor kerusakan aset yang lagi dia pakai

    Route::prefix('dashboard')->group(function () {
        Route::get('/kpd', [DashboardController::class, 'KaryawanPerDepart']);
    });

    Route::get('/user', [AuthController::class, 'user']);
});

// BARU: role 'cabang' butuh akses read-only ke kpd juga (dipakai DashboardCabang).
Route::middleware(['auth:sanctum', 'role:cabang,karyawan,manajer,hr,admin'])->group(function () {
    Route::get('/user', [AuthController::class, 'user']);

    Route::prefix('dashboard')->group(function () {
        Route::get('/kpd', [DashboardController::class, 'KaryawanPerDepart']);
    });
});

Route::middleware(['auth:sanctum', 'role:manajer,hr,admin,cabang'])->group(function () {
    Route::prefix('dashboard-analytics')->group(function () {
        Route::get('/total-keuangan', [DashboardController::class, 'totalKeuangan']);
        Route::get('/keuangan-per-bulan', [DashboardController::class, 'keuanganPerBulan']);
    });
});

Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
    Route::get('/karyawan', [UserController::class, 'index']);
    Route::get('/karyawan/{user}', [UserController::class, 'edit']);
    Route::put('/karyawan/{user}', [UserController::class, 'update']);
    Route::delete('/karyawan/{user}', [UserController::class, 'destroy']);
    Route::post('/karyawan', [UserController::class, 'store']);
});

Route::middleware(['auth:sanctum', 'role:admin,hr'])->group(function () {
    Route::post('/departemen/import', [DepartemenController::class, 'import']);
    Route::apiResource('departemen', DepartemenController::class)->except(['show']);
});

Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
    Route::get('/audit-log', [AuditLogController::class, 'index']);
    Route::get('/audit-log/trash', [AuditLogController::class, 'trash']);
    Route::post('/aset-penanganan/{asetPenanganan}/terima', [InventoryPenangananController::class, 'terima']); // admin: terima & mulai tangani laporan
    Route::post('/aset-penanganan/{asetPenanganan}', [InventoryPenangananController::class, 'update']);
    Route::post('/import-aset', [ImportController::class, 'import']);
    Route::post('/import-karyawan', [ImportController::class, 'importKaryawan']);
    Route::post('/import-aset-penanganan', [ImportController::class, 'importAsetPenanganan']); // import bulk laporan penanganan aset (Berhasil Diperbaiki / Rusak Berat)
    Route::post('/aset-kelengkapan/import', [AsetKelengkapanController::class, 'import']);
    Route::post('/aset-kelengkapan/{asetKelengkapan}/pemakai', [InventoryPemakaiController::class, 'storeKelengkapan']);
    // index & show DIPINDAH ke grup role:karyawan,manajer,hr,admin di bawah
    // (bareng /aset) -- non-admin butuh baca kelengkapan yang tersedia/lagi
    // dia pinjam sendiri, filtering-nya udah dihandle di controller.
    Route::post('/aset-kelengkapan', [AsetKelengkapanController::class, 'store']);
    Route::post('/aset-kelengkapan/{asetKelengkapan}', [AsetKelengkapanController::class, 'update']); // POST + _method=PUT krn ada file upload, sama pola kayak /aset
    Route::delete('/aset-kelengkapan/{asetKelengkapan}', [AsetKelengkapanController::class, 'destroy']);
    Route::post('/aset-kelengkapan/{aset_kelengkapan}/lapor-rusak', [AsetKelengkapanController::class, 'laporRusak']);
    Route::post('/aset-kelengkapan/{aset_kelengkapan}/pasang-pengganti', [AsetKelengkapanController::class, 'pasangPengganti']);
    Route::get('/aset-kelengkapan/rusak', [AsetKelengkapanController::class, 'rusak']);
});

Route::middleware(['auth:sanctum', 'role:karyawan,manajer,hr,admin'])->group(function () {
    Route::get('/aset', [InventoryController::class, 'index']);
    Route::get('/aset/{aset}', [InventoryController::class, 'show']);
    Route::get('/supplier', [SupplierController::class, 'index']);

    // BARU: non-admin (karyawan/manajer/hr) butuh liat daftar kelengkapan
    // aset (charger, tas, dll) buat tau apa yang tersedia & apa yang lagi
    // dia pinjam sendiri -- scoping detail (gak boleh liat punya orang
    // lain / yang berstatus rusak) dicek DI DALAM controller, bukan cuma
    // di middleware ini.
    Route::get('/aset-kelengkapan', [AsetKelengkapanController::class, 'index']);
    Route::get('/aset-kelengkapan/{asetKelengkapan}', [AsetKelengkapanController::class, 'show']);

    // admin: riwayat GLOBAL semua aset. karyawan/manajer/hr: riwayat
    // dibatasi cuma punya sendiri (dicek & difilter di dalam controller,
    // BUKAN cuma di middleware — biar gak ada celah data orang lain bocor).
    Route::get('/aset-pemakai/riwayat', [InventoryPemakaiController::class, 'riwayat']);

    // PINDAH ke sini (dari grup admin-only) — pemakai (karyawan/cabang) yang
    // lagi pegang aset ini harus bisa ngembaliin sendiri, bukan cuma admin.
    // Otorisasi detail (harus admin ATAU pemilik pemakaian ini) dicek di
    // dalam InventoryPemakaiController::kembalikan(), bukan cuma di middleware.
    Route::post('/aset-pemakai/{asetPemakai}/kembalikan', [InventoryPemakaiController::class, 'kembalikan']);
});

// BARU: dipisah ke grup admin+hr — endpoint ini nampilin SEMUA laporan
// kerusakan dari SELURUH karyawan tanpa filter, jadi gak boleh diakses
// karyawan/manajer biasa (data pribadi karyawan lain).
Route::middleware(['auth:sanctum', 'role:admin,hr'])->group(function () {
    Route::get('/aset-penanganan', [InventoryPenangananController::class, 'index']);
    Route::get('/aset-penanganan/foto', [InventoryPenangananController::class, 'foto']); // tab "Rusak" di halaman Foto Aset
});

Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
    Route::post('/aset', [InventoryController::class, 'store']);
    Route::post('/aset/{aset}', [InventoryController::class, 'update']); // pakai POST + _method=PUT dari frontend krn ada file upload
    Route::delete('/aset/{aset}', [InventoryController::class, 'destroy']);
    Route::get('/aset-pemakai/foto', [InventoryPemakaiController::class, 'foto']);
    Route::post('/aset/{aset}/pemakai', [InventoryPemakaiController::class, 'store']);

    Route::delete('/aset-penanganan/{asetPenanganan}', [InventoryPenangananController::class, 'destroy']);
    Route::delete('/aset-pemakai/{asetPemakai}', [InventoryPemakaiController::class, 'destroy']);
    Route::post('/aset/{aset}/jual', [InventoryController::class, 'jual']);

    Route::post('/supplier/import', [SupplierController::class, 'import']);
    Route::apiResource('supplier', SupplierController::class)->except(['index', 'show']);
});