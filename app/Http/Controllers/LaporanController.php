<?php

namespace App\Http\Controllers;

use App\Models\Absensi;
use App\Models\MutasiBarang;
use App\Models\PengajuanIzin;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LaporanController extends Controller
{
    // GET /api/laporan/absensi?bulan=7&tahun=2026 — export CSV absensi bulanan.
   // GET /api/laporan/absensi?bulan=&tahun= ATAU ?tanggal_mulai=&tanggal_selesai=&status=
    public function absensi(Request $request): StreamedResponse
    {
        $status = $request->get('status');
        $tanggalMulai = $request->get('tanggal_mulai');
        $tanggalSelesai = $request->get('tanggal_selesai');

        $query = Absensi::with('pekerja.user', 'pekerja.departemen');

        if ($tanggalMulai && $tanggalSelesai) {
            // Mode rentang tanggal (dipakai chat AI: hari ini/kemarin/N hari lalu)
            $query->whereBetween('tanggal', [$tanggalMulai, $tanggalSelesai]);
            $labelFile = "{$tanggalMulai}_sd_{$tanggalSelesai}";
        } else {
            // Mode bulan (dipakai halaman Laporan.tsx yang sudah ada)
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

    // GET /api/laporan/izin?bulan=7&tahun=2026 — export CSV pengajuan izin bulanan.
    public function izin(Request $request): StreamedResponse
    {
        $bulan = (int) $request->get('bulan', now()->month);
        $tahun = (int) $request->get('tahun', now()->year);

        $data = PengajuanIzin::with('karyawan', 'reviewer')
            ->whereMonth('tanggal_mulai', $bulan)
            ->whereYear('tanggal_mulai', $tahun)
            ->orderBy('tanggal_mulai')
            ->get();

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

    // GET /api/laporan/inventaris?bulan=7&tahun=2026 — export CSV mutasi barang bulanan.
    public function inventaris(Request $request): StreamedResponse
    {
        $bulan = (int) $request->get('bulan', now()->month);
        $tahun = (int) $request->get('tahun', now()->year);

        $data = MutasiBarang::with('barang', 'user')
            ->whereMonth('created_at', $bulan)
            ->whereYear('created_at', $tahun)
            ->orderBy('created_at')
            ->get();

        $filename = "laporan-inventaris-{$tahun}-{$bulan}.csv";

        return $this->streamCsv($filename, [
            'Tanggal', 'Kode Barang', 'Nama Barang', 'Tipe', 'Jumlah', 'Stok Sebelum', 'Stok Sesudah', 'Oleh', 'Catatan',
        ], $data->map(fn ($m) => [
            $m->created_at->format('Y-m-d H:i'),
            $m->barang->kode_barang ?? '-',
            $m->barang->nama ?? '-',
            $m->tipe,
            $m->jumlah,
            $m->stok_sebelum,
            $m->stok_sesudah,
            $m->user->name ?? '-',
            $m->catatan ?? '-',
        ]));
    }

    // Helper: nge-stream data jadi file CSV yang bisa langsung dibuka di Excel.
    private function streamCsv(string $filename, array $header, $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            // BOM biar karakter non-ASCII (misal nama pakai huruf khusus) kebaca bener di Excel.
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