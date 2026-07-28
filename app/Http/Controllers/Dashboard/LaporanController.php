<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;

use App\Models\Absensi;
use App\Models\PengajuanIzin;
use App\Models\Pekerja;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LaporanController extends Controller
{
    public function absensi(Request $request): StreamedResponse
    {
        $status = $request->get('status');
        $tanggalMulai = $request->get('tanggal_mulai');
        $tanggalSelesai = $request->get('tanggal_selesai');

        $query = Absensi::with('pekerja.user', 'pekerja.departemen');

        // BARU: kalau yang akses akun cabang, cuma tampilin absensi karyawan
        // yang lokasi_kantor_id-nya sama dengan cabang tsb.
        $user = Auth::user();
        if ($user && $user->role === 'cabang' && $user->lokasi_kantor_id) {
            $query->whereHas('pekerja', function ($q) use ($user) {
                $q->where('lokasi_kantor_id', $user->lokasi_kantor_id);
            });
        }

        if ($tanggalMulai && $tanggalSelesai) {
            $query->whereBetween('tanggal', [$tanggalMulai, $tanggalSelesai]);
            $labelFile = "{$tanggalMulai}_sd_{$tanggalSelesai}";
        } else {
            $bulan = (int) $request->get('bulan', now()->month);
            $tahun = (int) $request->get('tahun', now()->year);
            $query->whereMonth('tanggal', $bulan)->whereYear('tanggal', $tahun);
            $labelFile = "{$tahun}-{$bulan}";
        }

        if ($status) {
            $query->where('status', $status);
        }

        $data = $query->orderBy('tanggal')->get();

        $filename = $status
            ? "laporan-absensi-{$status}-{$labelFile}.csv"
            : "laporan-absensi-{$labelFile}.csv";

        return $this->streamCsv($filename, [
            'Tanggal', 'NIP', 'Nama', 'Departemen', 'Jam Masuk', 'Jam Pulang', 'Status Masuk', 'Status Pulang',
        ], $data->map(fn ($a) => [
            $a->tanggal,
            $a->pekerja->nip ?? '-',
            $a->pekerja->user->name ?? '-',
            $a->pekerja->departemen->nama ?? '-',
            $a->jam_masuk ?? '-',
            $a->jam_pulang ?? '-',
            $a->status ?? '-',
            $a->status_pulang ?? '-',
        ]));
    }

    public function izin(Request $request): StreamedResponse
    {
        $bulan = (int) $request->get('bulan', now()->month);
        $tahun = (int) $request->get('tahun', now()->year);

        $query = PengajuanIzin::with('karyawan', 'reviewer')
            ->whereMonth('tanggal_mulai', $bulan)
            ->whereYear('tanggal_mulai', $tahun);

        // BARU: scope ke cabang -- karyawan.pekerja.lokasi_kantor_id harus
        // sama dengan lokasi_kantor_id akun cabang yang login.
        $user = Auth::user();
        if ($user && $user->role === 'cabang' && $user->lokasi_kantor_id) {
            $query->whereHas('karyawan.pekerja', function ($q) use ($user) {
                $q->where('lokasi_kantor_id', $user->lokasi_kantor_id);
            });
        }

        $data = $query->orderBy('tanggal_mulai')->get();

        $filename = "laporan-izin-{$tahun}-{$bulan}.csv";

        return $this->streamCsv($filename, [
            'Nomor Izin', 'Nama', 'Jenis', 'Tanggal Mulai', 'Tanggal Selesai', 'Lama (hari)', 'Status', 'Direview Oleh', 'Alasan',
        ], $data->map(fn ($i) => [
            $i->nomor_izin,
            $i->karyawan->name ?? '-',
            $i->jenis_izin,
            $i->tanggal_mulai,
            $i->tanggal_selesai,
            $i->lama_izin,
            $i->status,
            $i->reviewer->name ?? '-',
            $i->alasan,
        ]));
    }

    // Helper: nge-stream data jadi file CSV yang bisa langsung dibuka di Excel.
    private function streamCsv(string $filename, array $header, $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }
}