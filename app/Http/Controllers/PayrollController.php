<?php

namespace App\Http\Controllers;

use App\Models\Absensi;
use App\Models\Pekerja;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PayrollController extends Controller
{
    // GET /api/payroll?bulan=7&tahun=2026 — rekap gaji sederhana per karyawan.
    // Rumus (disederhanakan): gaji_bersih = gaji_pokok (dari jabatan) - (jumlah_telat * potongan_per_telat).
    public function index(Request $request)
    {
        $bulan = (int) $request->get('bulan', now()->month);
        $tahun = (int) $request->get('tahun', now()->year);

        return response()->json(
            $this->hitungPayroll($bulan, $tahun)
        );
    }

    // GET /api/payroll/export?bulan=7&tahun=2026 — export CSV rekap gaji.
    public function export(Request $request): StreamedResponse
    {
        $bulan = (int) $request->get('bulan', now()->month);
        $tahun = (int) $request->get('tahun', now()->year);

        $data = $this->hitungPayroll($bulan, $tahun);
        $filename = "payroll-{$tahun}-{$bulan}.csv";

        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'NIP', 'Nama', 'Jabatan', 'Departemen', 'Gaji Pokok',
                'Hari Hadir', 'Hari Telat', 'Potongan Telat', 'Gaji Bersih',
            ]);
            foreach ($data as $row) {
                fputcsv($out, [
                    $row['nip'], $row['nama'], $row['jabatan'], $row['departemen'],
                    $row['gaji_pokok'], $row['hari_hadir'], $row['hari_telat'],
                    $row['potongan_telat'], $row['gaji_bersih'],
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function hitungPayroll(int $bulan, int $tahun): array
    {
        $potonganPerTelat = (float) config('payroll.potongan_per_telat', 50000);

        $pekerjaList = Pekerja::with('user', 'jabatan', 'departemen')->get();

        // Hitung kehadiran & keterlambatan per pekerja dalam satu query, biar
        // gak N+1 query ke tabel absensi tiap pekerja.
        $rekapAbsensi = Absensi::whereMonth('tanggal', $bulan)
            ->whereYear('tanggal', $tahun)
            ->whereIn('status', ['tepat_waktu', 'telat'])
            ->get()
            ->groupBy('karyawan_id');

        return $pekerjaList->map(function (Pekerja $pekerja) use ($rekapAbsensi, $potonganPerTelat) {
            $absensiPekerja = $rekapAbsensi->get($pekerja->id, collect());
            $hariHadir = $absensiPekerja->count();
            $hariTelat = $absensiPekerja->where('status', 'telat')->count();

            $gajiPokok = (float) ($pekerja->jabatan->gaji_pokok ?? 0);
            $potonganTelat = $hariTelat * $potonganPerTelat;
            $gajiBersih = max($gajiPokok - $potonganTelat, 0);

            return [
                'pekerja_id' => $pekerja->id,
                'nip' => $pekerja->nip,
                'nama' => $pekerja->user->name ?? '-',
                'jabatan' => $pekerja->jabatan->nama ?? '-',
                'departemen' => $pekerja->departemen->nama ?? '-',
                'gaji_pokok' => $gajiPokok,
                'hari_hadir' => $hariHadir,
                'hari_telat' => $hariTelat,
                'potongan_telat' => $potonganTelat,
                'gaji_bersih' => $gajiBersih,
            ];
        })->values()->toArray();
    }
}