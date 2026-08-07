<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;

class LogActivity
{
    // Route yang gak perlu dicatat, biar gak infinite logging & gak berisik
    protected array $excluded = [
        'api/audit-log',
        'api/audit-log/*',
        'api/login',
        'api/logout',
    ];

    // Cuma method yang benar-benar mengubah data yang dicatat. GET (buka
    // halaman/liat data/pindah tab) sengaja gak dicatat -- dulu tiap
    // navigasi/fetch list ikut nyatet, jadi log kebanjiran "melihat data"
    // dan menutupi aktivitas ubah data yang justru penting.
    protected array $loggedMethods = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        $this->log($request);

        return $response;
    }

    protected function log(Request $request): void
    {
        if (!in_array($request->method(), $this->loggedMethods, true)) {
            return;
        }

        foreach ($this->excluded as $pattern) {
            if ($request->is($pattern)) {
                return;
            }
        }

        AuditLog::create([
            'user_id' => $request->user()?->id,
            'method' => $request->method(),
            'endpoint' => $request->path(),
            'deskripsi' => $this->buatDeskripsi($request),
            'ip_address' => $request->ip(),
        ]);
    }

    protected function buatDeskripsi(Request $request): string
    {
        $nama = $request->user()?->name ?? 'Guest';
        $method = $request->method();
        $path = $request->path();

        $khusus = $this->deskripsiInventaris($request, $nama);
        if ($khusus !== null) {
            return $khusus;
        }

        return match ($method) {
            'POST' => "{$nama} membuat data baru di /{$path}",
            'PUT', 'PATCH' => "{$nama} mengubah data di /{$path}",
            'DELETE' => "{$nama} menghapus data di /{$path}",
            default => "{$nama} mengakses /{$path}",
        };
    }

    /**
     * Deskripsi khusus buat aksi-aksi inventaris (serah-terima, pengembalian,
     * lapor kerusakan, penanganan, jual aset) supaya audit log kebaca jelas
     * siapa-ngapain-ke-apa, bukan cuma "mengubah data di /aset-pemakai/12".
     * Dicocokkan pakai pola URI route (bukan raw path yang isinya id angka),
     * jadi gak keliru walau id-nya beda-beda. Kembalikan null kalau bukan
     * salah satu route inventaris yang dikenali, biar fallback ke deskripsi
     * generik seperti biasa.
     */
    protected function deskripsiInventaris(Request $request, string $nama): ?string
    {
        $route = $request->route();
        if (!$route) {
            return null;
        }

        $uri = $route->uri(); // contoh: "aset/{aset}/pemakai"
        $method = $request->method();
        $kodeAset = $this->kodeAsetDariRoute($route);

        return match (true) {
            $uri === 'aset' && $method === 'POST' => "{$nama} menambahkan aset baru" . ($request->input('merek') ? " ({$request->input('merek')} {$request->input('tipe')})" : ''),
            $uri === 'aset/{aset}' && $method === 'POST' => "{$nama} mengubah data aset" . ($kodeAset ? " {$kodeAset}" : ''),
            $uri === 'aset/{aset}' && $method === 'DELETE' => "{$nama} menghapus aset" . ($kodeAset ? " {$kodeAset}" : ''),
            $uri === 'aset/{aset}/pemakai' => "{$nama} meminjamkan aset" . ($kodeAset ? " {$kodeAset}" : '') . " ke " . $this->namaPenerima($request),
            $uri === 'aset-pemakai/{asetPemakai}/kembalikan' => "{$nama} mencatat pengembalian aset dari peminjam",
            $uri === 'aset-pemakai/{asetPemakai}' && $method === 'DELETE' => "{$nama} menghapus riwayat pemakaian aset",
            $uri === 'aset-penanganan' && $method === 'POST' => "{$nama} melaporkan kerusakan aset" . ($request->input('keluhan') ? ": {$request->input('keluhan')}" : ''),
            $uri === 'aset-penanganan/{asetPenanganan}/terima' => "{$nama} mulai menangani laporan kerusakan aset",
            $uri === 'aset-penanganan/{asetPenanganan}' && in_array($method, ['POST', 'PUT', 'PATCH'], true) => "{$nama} memperbarui status penanganan aset" . ($request->input('hasil') ? " (hasil: {$request->input('hasil')})" : ''),
            $uri === 'aset-penanganan/{asetPenanganan}' && $method === 'DELETE' => "{$nama} menghapus laporan penanganan aset",
            $uri === 'aset/{aset}/jual' => "{$nama} menjual/writeoff aset" . ($kodeAset ? " {$kodeAset}" : ''),
            $uri === 'aset/{aset}/penggantian-sparepart' => "{$nama} mencatat penggantian sparepart aset" . ($kodeAset ? " {$kodeAset}" : ''),
            default => null,
        };
    }

    // Ambil kode_aset dari model Aset yang udah di-resolve lewat route model
    // binding (aman dipanggil setelah $next($request), binding-nya udah pasti
    // selesai karena controller sudah jalan duluan).
    protected function kodeAsetDariRoute($route): ?string
    {
        $aset = $route->parameter('aset');
        if ($aset instanceof \App\Models\Aset) {
            return $aset->kode_aset;
        }

        return null;
    }

    // Nama pekerja/akun cabang penerima aset pas serah-terima. Request body
    // cuma kirim id (pekerja_id / user_id), jadi perlu 1 query kecil buat
    // ambil namanya -- aman karena cuma jalan di request POST serah-terima,
    // bukan tiap request.
    protected function namaPenerima(Request $request): string
    {
        if ($pekerjaId = $request->input('pekerja_id')) {
            $nama = \App\Models\Pekerja::with('user:id,name')->find($pekerjaId)?->user?->name;
            if ($nama) {
                return $nama;
            }
        }

        if ($userId = $request->input('user_id')) {
            $nama = \App\Models\User::find($userId)?->name;
            if ($nama) {
                return $nama;
            }
        }

        return 'karyawan/akun terkait';
    }
}