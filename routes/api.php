<?php

use App\Http\Controllers\Auth\AuthController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Karyawan\UserController;
use App\Http\Controllers\Organisasi\DepartemenController;
use App\Http\Controllers\Organisasi\JabatanController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Dashboard\DashboardController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Karyawan\AdminUserController;
use App\Http\Controllers\Inventaris\SupplierController;
use App\Http\Controllers\Inventaris\AsetController;
use App\Http\Controllers\Inventaris\AsetPemakaiController;
use App\Http\Controllers\Inventaris\AsetPenangananController;
use App\Http\Controllers\Inventaris\AsetKelengkapanController;
use App\Http\Controllers\Organisasi\CabangController;
use App\Http\Controllers\PushSubscriptionController;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\ImportController;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
Route::post('/resend-otp', [AuthController::class, 'resendOtp']);
Route::post('/login', [AuthController::class, 'login']);

// TODO: route debug ini gak ada middleware auth sama sekali, publik.
// Kalau ini sisa development, hapus. Kalau masih dipakai, minimal
// kasih ['auth:sanctum', 'role:admin'].
Route::get('/debug-keuangan', [DashboardController::class, 'debugKeuangan']);

Broadcast::routes(['middleware' => ['auth:sanctum']]);

Route::middleware(['auth:sanctum', 'role:admin,hr'])->group(function () {
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
Route::middleware(['auth:sanctum', 'role:admin'])->post('/admin/users/{id}/reset-password', [AdminUserController::class, 'resetPassword']);

Route::middleware(['auth:sanctum', 'role:karyawan,manajer,hr,admin'])->group(function () {
    Route::post('/aset-penanganan', [AsetPenangananController::class, 'store']); // karyawan: lapor kerusakan aset yang lagi dia pakai

    Route::prefix('dashboard')->group(function () {
        Route::get('/kpd', [DashboardController::class, 'KaryawanPerDepart']);
    });

    Route::get('/user', [AuthController::class, 'user']);
    Route::get('/karyawan', [UserController::class, 'index']);
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
    Route::get('/karyawan/{user}', [UserController::class, 'edit']);
    Route::put('/karyawan/{user}', [UserController::class, 'update']);
    Route::delete('/karyawan/{user}', [UserController::class, 'destroy']);
    Route::post('/karyawan', [UserController::class, 'store']);
});

Route::middleware(['auth:sanctum', 'role:admin,hr'])->group(function () {
    Route::apiResource('departemen', DepartemenController::class)->except(['show']);
    Route::apiResource('jabatan', JabatanController::class)->except(['show']);
});

Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
    Route::get('/audit-log', [AuditLogController::class, 'index']);
    Route::get('/audit-log/trash', [AuditLogController::class, 'trash']);
    Route::post('/aset-penanganan/{asetPenanganan}/terima', [AsetPenangananController::class, 'terima']); // admin: terima & mulai tangani laporan
    Route::post('/aset-penanganan/{asetPenanganan}', [AsetPenangananController::class, 'update']);
    Route::post('/import-aset', [ImportController::class, 'import']);
    Route::post('/import-karyawan', [ImportController::class, 'importKaryawan']);
    Route::post('/import-aset-penanganan', [ImportController::class, 'importAsetPenanganan']); // import bulk laporan penanganan aset (Berhasil Diperbaiki / Rusak Berat)
    Route::post('/aset-kelengkapan/{asetKelengkapan}/pemakai', [AsetPemakaiController::class, 'storeKelengkapan']);
    Route::apiResource('aset-kelengkapan', AsetKelengkapanController::class)->except(['destroy']);// sesuaikan sama pola route aset utama kamu yang sekarang
    Route::delete('/aset-kelengkapan/{asetKelengkapan}', [AsetKelengkapanController::class, 'destroy']); // pakai POST + _method=PUT biar konsisten sama pola aset/{aset}
});

Route::middleware(['auth:sanctum', 'role:karyawan,manajer,hr,admin'])->group(function () {
    Route::get('/aset', [AsetController::class, 'index']);
    Route::get('/aset/{aset}', [AsetController::class, 'show']);
    Route::get('/supplier', [SupplierController::class, 'index']);

    // admin: riwayat GLOBAL semua aset. karyawan/manajer/hr: riwayat
    // dibatasi cuma punya sendiri (dicek & difilter di dalam controller,
    // BUKAN cuma di middleware — biar gak ada celah data orang lain bocor).
    Route::get('/aset-pemakai/riwayat', [AsetPemakaiController::class, 'riwayat']);

    // PINDAH ke sini (dari grup admin-only) — pemakai (karyawan/cabang) yang
    // lagi pegang aset ini harus bisa ngembaliin sendiri, bukan cuma admin.
    // Otorisasi detail (harus admin ATAU pemilik pemakaian ini) dicek di
    // dalam AsetPemakaiController::kembalikan(), bukan cuma di middleware.
    Route::post('/aset-pemakai/{asetPemakai}/kembalikan', [AsetPemakaiController::class, 'kembalikan']);
});

// BARU: dipisah ke grup admin+hr — endpoint ini nampilin SEMUA laporan
// kerusakan dari SELURUH karyawan tanpa filter, jadi gak boleh diakses
// karyawan/manajer biasa (data pribadi karyawan lain).
Route::middleware(['auth:sanctum', 'role:admin,hr'])->group(function () {
    Route::get('/aset-penanganan', [AsetPenangananController::class, 'index']);
    Route::get('/aset-penanganan/foto', [AsetPenangananController::class, 'foto']); // tab "Rusak" di halaman Foto Aset
});

Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
    Route::post('/aset', [AsetController::class, 'store']);
    Route::post('/aset/{aset}', [AsetController::class, 'update']); // pakai POST + _method=PUT dari frontend krn ada file upload
    Route::delete('/aset/{aset}', [AsetController::class, 'destroy']);
    Route::get('/aset-pemakai/foto', [AsetPemakaiController::class, 'foto']);
    Route::post('/aset/{aset}/pemakai', [AsetPemakaiController::class, 'store']);

    Route::delete('/aset-penanganan/{asetPenanganan}', [AsetPenangananController::class, 'destroy']);
    Route::delete('/aset-pemakai/{asetPemakai}', [AsetPemakaiController::class, 'destroy']);
    Route::post('/aset/{aset}/jual', [AsetController::class, 'jual']);

    Route::apiResource('supplier', SupplierController::class)->except(['index', 'show']);
});
Route::get('/debug-webpush-test', function () {
    $auth = [
        'VAPID' => [
            'subject' => config('webpush.vapid.subject'),
            'publicKey' => config('webpush.vapid.public_key'),
            'privateKey' => config('webpush.vapid.private_key'),
        ],
    ];
    $webPush = new \Minishlink\WebPush\WebPush($auth);
    $sub = DB::table('push_subscriptions')->where('subscribable_id', 1)
        ->where('subscribable_id', 1)
        ->where('endpoint', 'like', '%notify.windows.com%')
        ->latest('created_at')
        ->first();
    $subscription = \Minishlink\WebPush\Subscription::create([
        'endpoint' => $sub->endpoint,
        'publicKey' => $sub->public_key,
        'authToken' => $sub->auth_token,
        'contentEncoding' => $sub->content_encoding ?? 'aes128gcm',
    ]);
    $report = $webPush->sendOneNotification(
        $subscription,
        json_encode(['title' => 'Test', 'body' => 'Halo dari route'])
    );

    return response()->json([
        'uri' => (string) $report->getRequest()->getUri(),
        'success' => $report->isSuccess(),
        'reason' => $report->getReason(),
        'status_code' => $report->getResponse()?->getStatusCode(),
        'body' => (string) $report->getResponse()?->getBody(),
    ]);
});