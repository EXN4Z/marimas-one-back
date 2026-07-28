<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\ChatbotController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Karyawan\UserController;
use App\Http\Controllers\Absensi\AbsensiController;
use App\Http\Controllers\Organisasi\DepartemenController;
use App\Http\Controllers\Organisasi\JabatanController;
use App\Http\Controllers\Ticketing\TicketController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Izin\IzinController;
use App\Http\Controllers\Dashboard\DashboardController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Karyawan\AdminUserController;
use App\Http\Controllers\AgendaController;
use App\Http\Controllers\Dashboard\LaporanController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\Inventaris\SupplierController;
use App\Http\Controllers\Inventaris\JenisAsetController;
use App\Http\Controllers\Inventaris\KelengkapanMasterController;
use App\Http\Controllers\Inventaris\AsetController;
use App\Http\Controllers\Inventaris\AsetPemakaiController;
use App\Http\Controllers\Inventaris\AsetPenggantianSparepartController;
use App\Http\Controllers\Inventaris\AsetPenangananController;
use App\Http\Controllers\Organisasi\CabangController;
use App\Http\Controllers\PushSubscriptionController;


Route::post('/register', [AuthController::class, 'register']);
Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
Route::post('/resend-otp', [AuthController::class, 'resendOtp']);
Route::post('/login', [AuthController::class, 'login']);
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

    Route::get('/agenda', [AgendaController::class, 'index']);
});
Route::middleware(['auth:sanctum', 'role:admin'])->post('/admin/users/{id}/reset-password', [AdminUserController::class, 'resetPassword']);

Route::middleware(['auth:sanctum', 'role:karyawan,manajer,hr,admin'])->group(function () {
    // DICABUT: karyawan gak boleh lagi request pinjam sendiri. Aset cuma boleh
    // diserahkan admin lewat AsetPemakaiController::store() (tombol "Serahkan"
    // di tab Aset). Method requestPinjam() masih ada di controller tapi udah
    // gak ke-route kemana pun -- sengaja dibiarin nganggur, bukan dihapus,
    // biar gampang di-revert kalau alur ini mau dipake lagi nanti.
    Route::post('/aset-penanganan', [AsetPenangananController::class, 'store']); // karyawan: lapor kerusakan aset yang lagi dia pakai

    Route::prefix('absensi')->group(function () {
        Route::get('/karyawan', [AbsensiController::class, 'karyawan']);
        Route::get('/hari-ini', [AbsensiController::class, 'hariIni']);
        Route::get('/riwayat', [AbsensiController::class, 'riwayat']);
        Route::post('/scan', [AbsensiController::class, 'scan']);
        Route::post('/daftar-wajah', [AbsensiController::class, 'daftarWajah']);
        Route::get('/saya', [AbsensiController::class, 'saya']);
        });
    Route::prefix('dashboard')->group(function () {
        Route::get('/kpd', [DashboardController::class, 'KaryawanPerDepart']);
        Route::get('/izin-pending', [DashboardController::class, 'izinPending']);
        Route::get('/stats-card', [DashboardController::class, 'statsCard']);
        Route::get('/kehadiran-mingguan', [DashboardController::class, 'kehadiranMingguan']);
        Route::get('/beban-kerja', [DashboardController::class, 'bebanKerja']);
    });

    Route::prefix('izin')->group(function () {
        Route::get('/', [IzinController::class, 'index']);
        Route::get('/dashboard', [IzinController::class, 'dashboard']);
        Route::get('/statistik', [IzinController::class, 'statistik']);
        Route::get('/kuota', [IzinController::class, 'kuota']);
        Route::get('/{id}', [IzinController::class, 'show']);
        Route::post('/', [IzinController::class, 'store']);
        Route::put('/{id}', [IzinController::class, 'update']);
        Route::delete('/{id}', [IzinController::class, 'destroy']);
    });

    Route::get('/karyawan/kode/{kode}', [AbsensiController::class, 'getByKode']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/chat', [ChatbotController::class, 'ask']);
    Route::get('/karyawan', [UserController::class, 'index']);

    Route::prefix('ticketing')->group(function () {
        Route::get('/', [TicketController::class, 'index']);
        Route::get('/history', [TicketController::class, 'history']);
        Route::post('/', [TicketController::class, 'store']);
        Route::get('/{ticket}', [TicketController::class, 'show']);
    });
    Route::prefix('agenda')->group(function () {
        
    });
});

// BARU: role 'cabang' butuh akses read-only ke stats-card, kehadiran-mingguan,
// beban-kerja, dan kpd juga (dipakai DashboardCabang) -- tapi dia gak boleh
// ikut ke grup 'karyawan,manajer,hr,admin' di atas soalnya grup itu juga
// nge-cover endpoint create/update izin & ticketing milik pribadi karyawan
// yang gak relevan buat akun cabang. Jadi endpoint dashboard read-only
// dipisah sendiri biar akses cabang tetap ke-scope minimal.
Route::middleware(['auth:sanctum', 'role:cabang,karyawan,manajer,hr,admin'])->group(function () {
    Route::get('/user', [AuthController::class, 'user']);

    Route::prefix('dashboard')->group(function () {
        Route::get('/kpd', [DashboardController::class, 'KaryawanPerDepart']);
        Route::get('/stats-card', [DashboardController::class, 'statsCard']);
        Route::get('/kehadiran-mingguan', [DashboardController::class, 'kehadiranMingguan']);
        Route::get('/beban-kerja', [DashboardController::class, 'bebanKerja']);
    });
});

Route::middleware(['auth:sanctum', 'role:manajer,hr,admin,cabang'])->group(function () {
    Route::put('/ticketing/{ticket}/status', [TicketController::class, 'updateStatus']);
    Route::patch('/izin/{id}/status', [IzinController::class, 'updateStatus']);

    Route::prefix('dashboard-analytics')->group(function () {
        Route::get('/analisis-izin', [DashboardController::class, 'analisisIzin']);
        Route::get('/top-karyawan', [DashboardController::class, 'topKaryawan']);
        Route::get('/top-kehadiran', [DashboardController::class, 'topKehadiran']);
        Route::get('/grafik-pengajuan', [DashboardController::class, 'grafikPengajuan']);
        Route::get('/total-keuangan', [DashboardController::class, 'totalKeuangan']);
        Route::get('/keuangan-per-bulan', [DashboardController::class, 'keuanganPerBulan']);
    });

    Route::prefix('laporan')->group(function () {
        Route::get('/absensi', [LaporanController::class, 'absensi']);
        Route::get('/izin', [LaporanController::class, 'izin']);
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

    Route::prefix('payroll')->group(function () {
        Route::get('/', [PayrollController::class, 'index']);
        Route::get('/export', [PayrollController::class, 'export']);
    });

    Route::post('/agenda', [AgendaController::class, 'store']);
    Route::delete('/agenda/{agenda}', [AgendaController::class, 'destroy']);
});

Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
    Route::get('/audit-log', [AuditLogController::class, 'index']);
    Route::get('/audit-log/trash', [AuditLogController::class, 'trash']);
    Route::post('/aset-penanganan/{asetPenanganan}/terima', [AsetPenangananController::class, 'terima']); // admin: terima & mulai tangani laporan
    Route::post('/aset-penanganan/{asetPenanganan}', [AsetPenangananController::class, 'update']); // pakai POST + _method=PUT biar konsisten sama pola aset/{aset}
});

Route::middleware(['auth:sanctum', 'role:karyawan,manajer,hr,admin'])->group(function () {
    Route::get('/aset', [AsetController::class, 'index']);
    Route::get('/aset/{aset}', [AsetController::class, 'show']);
    Route::get('/jenis-aset', [JenisAsetController::class, 'index']);
    Route::get('/kelengkapan-master', [KelengkapanMasterController::class, 'index']);
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
});

Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
    Route::post('/aset', [AsetController::class, 'store']);
    Route::post('/aset/{aset}', [AsetController::class, 'update']); // pakai POST + _method=PUT dari frontend krn ada file upload
    Route::delete('/aset/{aset}', [AsetController::class, 'destroy']);

    Route::post('/aset/{aset}/pemakai', [AsetPemakaiController::class, 'store']);
    Route::get('/aset-pemakai/pending', [AsetPemakaiController::class, 'pending']); // admin: daftar request pinjam pending
    Route::post('/aset-pemakai/{asetPemakai}/setujui', [AsetPemakaiController::class, 'setujui']); // admin: approve
    Route::post('/aset-pemakai/{asetPemakai}/tolak', [AsetPemakaiController::class, 'tolak']); // admin: reject

    Route::delete('/aset-penanganan/{asetPenanganan}', [AsetPenangananController::class, 'destroy']);
    Route::post('/aset/{aset}/jual', [AsetController::class, 'jual']);
    Route::post('/aset/{aset}/penggantian-sparepart', [AsetPenggantianSparepartController::class, 'store']);
    Route::delete('/aset-penggantian-sparepart/{asetPenggantianSparepart}', [AsetPenggantianSparepartController::class, 'destroy']);

    Route::apiResource('jenis-aset', JenisAsetController::class)->except(['index', 'show']);
    Route::apiResource('kelengkapan-master', KelengkapanMasterController::class)->except(['index', 'show']);
    Route::apiResource('supplier', SupplierController::class)->except(['index', 'show']);
});