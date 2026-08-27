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
use App\Http\Controllers\MasterData\SupplierController;
use App\Http\Controllers\MasterData\InventoryController;
use App\Http\Controllers\MasterData\KategoriController;
use App\Http\Controllers\Transaksi\InventoryPemakaiController;
use App\Http\Controllers\Transaksi\InventoryPenangananController;
use App\Http\Controllers\Organisasi\CabangController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\ImportController;

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
    Route::post('/inventory-penanganan', [InventoryPenangananController::class, 'store']); // karyawan: lapor kerusakan aset yang lagi dia pakai

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
    Route::post('/inventory-penanganan/import', [ImportController::class, 'importAsetPenanganan']);
    Route::post('/inventory-penanganan/{inventoryPenanganan}/terima', [InventoryPenangananController::class, 'terima']); // admin: terima & mulai tangani laporan
    Route::post('/inventory-penanganan/{inventoryPenanganan}', [InventoryPenangananController::class, 'update']);
    Route::post('/inventory/import', [ImportController::class, 'import']);
    Route::post('/import-karyawan', [ImportController::class, 'importKaryawan']);
});

Route::middleware(['auth:sanctum', 'role:admin,hr'])->group(function () {
    // eks AsetKelengkapanController@rusak — daftar kelengkapan rusak lintas
    // karyawan (alasan privasi sama kayak inventory-penanganan/foto di
    // bawah). HARUS didaftarin SEBELUM wildcard GET /inventory/{inventory}
    // di bawah, kalau kagak "kelengkapan" bakal ketangkep jadi {inventory}.
    Route::get('/inventory/kelengkapan/rusak', [InventoryController::class, 'rusakKelengkapan']);
});

Route::middleware(['auth:sanctum', 'role:karyawan,manajer,hr,admin'])->group(function () {
    Route::get('/inventory', [InventoryController::class, 'index']);
    Route::get('/inventory/{inventory}', [InventoryController::class, 'show']);
    Route::get('/supplier', [SupplierController::class, 'index']);

    // BARU: dibuka buat karyawan/manajer juga (dulu admin+hr only) -- non-
    // admin/hr cuma boleh liat laporan penanganan yang terkait pemakaian dia
    // sendiri, discoping DI DALAM controller (InventoryPenangananController::index()),
    // BUKAN cuma di middleware ini -- biar gak ada celah data karyawan lain bocor.
    Route::get('/inventory-penanganan', [InventoryPenangananController::class, 'index']);

    // BARU: non-admin (karyawan/manajer/hr) butuh liat daftar kelengkapan
    // aset (charger, tas, dll) buat tau apa yang tersedia & apa yang lagi
    // dia pinjam sendiri -- scoping detail (gak boleh liat punya orang
    // lain / yang berstatus rusak) dicek DI DALAM controller, bukan cuma
    // di middleware ini.

    // admin: riwayat GLOBAL semua aset. karyawan/manajer/hr: riwayat
    // dibatasi cuma punya sendiri (dicek & difilter di dalam controller,
    // BUKAN cuma di middleware — biar gak ada celah data orang lain bocor).
    Route::get('/inventory-pemakai/riwayat', [InventoryPemakaiController::class, 'riwayat']);

    // PINDAH ke sini (dari grup admin-only) — pemakai (karyawan/cabang) yang
    // lagi pegang aset ini harus bisa ngembaliin sendiri, bukan cuma admin.
    // Otorisasi detail (harus admin ATAU pemilik pemakaian ini) dicek di
    // dalam InventoryPemakaiController::kembalikan(), bukan cuma di middleware.
    Route::post('/inventory-pemakai/{inventoryPemakai}/kembalikan', [InventoryPemakaiController::class, 'kembalikan']);
});

// endpoint ini nampilin SEMUA laporan kerusakan dari SELURUH karyawan tanpa
// filter (tab "Rusak" di halaman Foto Aset) -- tetap admin+hr only, beda dari
// /inventory-penanganan (index) di atas yang sekarang sudah self-scoping.
Route::middleware(['auth:sanctum', 'role:admin,hr'])->group(function () {
    Route::get('/inventory-penanganan/foto', [InventoryPenangananController::class, 'foto']);
});

Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
    Route::post('/inventory', [InventoryController::class, 'store']);
    // Route ini WAJIB didaftarkan sebagai PUT, meskipun frontend ngirim raw
    // HTTP method POST (lihat updateInventory() di api/masterData/inventory.ts:
    // FormData + field _method=PUT -- trik standar krn PHP gak bisa parse body
    // multipart kalau method aslinya PUT, jadi verb asli dibikin POST). Begitu
    // Laravel baca _method=PUT itu, request->method() langsung KEBACA "PUT" buat
    // urusan routing (bukan cuma buat isMethod() checks) -- makanya route yang
    // dicocokkan router HARUS PUT, walau HTTP verb yang beneran dikirim ke
    // server itu POST. Kalau didaftarkan sebagai POST malah salah: router bakal
    // nyari route PUT (krn override), gak ketemu di antara method POST yang
    // terdaftar, lempar 405 "PUT method not supported".
    Route::put('/inventory/{inventory}', [InventoryController::class, 'update']);
    Route::delete('/inventory/{inventory}', [InventoryController::class, 'destroy']);
    Route::get('/inventory-pemakai/foto', [InventoryPemakaiController::class, 'foto']);
    Route::post('/inventory/{inventory}/pemakai', [InventoryPemakaiController::class, 'store']);

    Route::delete('/inventory-penanganan/{inventoryPenanganan}', [InventoryPenangananController::class, 'destroy']);
    Route::delete('/inventory-pemakai/{inventoryPemakai}', [InventoryPemakaiController::class, 'destroy']);
    Route::post('/inventory/{inventory}/jual', [InventoryController::class, 'jual']);

    // eks AsetKelengkapanController@laporRusak / pasangPengganti — final,
    // gak ada opsi "diperbaiki" buat kelengkapan (beda alur sama barang
    // utama yang lewat inventory-penanganan).
    Route::post('/inventory/{inventory}/lapor-rusak-kelengkapan', [InventoryController::class, 'laporRusakKelengkapan']);
    Route::post('/inventory/{inventory}/pasang-pengganti-kelengkapan', [InventoryController::class, 'pasangPenggantiKelengkapan']);

    Route::post('/supplier/import', [SupplierController::class, 'import']);
    Route::apiResource('supplier', SupplierController::class)->except(['index', 'show']);

    // Kategori: jenis kategori barang di Inventory (Barang Utama,
    // Kelengkapan, dst), dikelola admin lewat Master Data -- dipakai buat
    // dropdown pilih Kategori waktu bikin/edit Inventory.
    Route::apiResource('kategori', KategoriController::class)->except(['show']);
});