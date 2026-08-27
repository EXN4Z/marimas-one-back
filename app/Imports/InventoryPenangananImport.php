<?php

namespace App\Imports;

use App\Http\Controllers\Concerns\GeneratesStrukNumber;
use App\Models\MasterData\Inventory;
use App\Models\Transaksi\InventoryPemakai;
use App\Models\Transaksi\InventoryPenanganan;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;

/**
 * Import laporan penanganan yang SUDAH SELESAI (bulk import data historis)
 * — HANYA untuk 2 hasil akhir: "diperbaiki" & "rusak_berat".
 *
 * Kolom (header dinormalisasi): Kode Aset/Kode Inventory | Jenis Kerusakan
 * | Keluhan | Pelapor | Tanggal Lapor | Tanggal Diterima | Tanggal Selesai
 * | Hasil | Biaya Komponen | Biaya Jasa | Catatan
 */
class InventoryPenangananImport implements ToCollection
{
    use GeneratesStrukNumber;

    protected $rowCount = 0;
    protected $errors = [];

    private const HASIL_ALIAS = [
        'diperbaiki' => 'diperbaiki',
        'berhasil_diperbaiki' => 'diperbaiki',
        'rusak_berat' => 'rusak_berat',
    ];

    public function collection(Collection $rows)
    {
        if ($rows->isEmpty()) {
            $this->errors[] = 'File kosong.';
            return;
        }

        $headers = $rows[0]->map(fn ($h) => $this->normalisasiHeader((string) $h))->toArray();
        $dataRows = $rows->slice(1);

        foreach ($dataRows as $index => $rawRow) {
            $nomorBaris = $index + 2;

            $rowArray = $rawRow->toArray();
            if (count(array_filter($rowArray, fn ($v) => $v !== null && $v !== '')) === 0) {
                continue;
            }

            $row = array_combine($headers, array_pad($rowArray, count($headers), null));

            try {
                $berhasil = DB::transaction(fn () => $this->prosesBaris($row, $nomorBaris));
                if ($berhasil) {
                    $this->rowCount++;
                }
            } catch (\Throwable $e) {
                $this->errors[] = "Baris {$nomorBaris}: " . $e->getMessage();
            }
        }
    }

    private function prosesBaris(array $row, int $nomorBaris): bool
    {
        $kode = trim((string) ($row['kode_inventory'] ?? $row['kode_aset'] ?? ''));
        if ($kode === '') {
            $this->errors[] = "Baris {$nomorBaris}: Kode Inventory kosong, dilewati.";
            return false;
        }

        $inventory = Inventory::with('kategori')->whereRaw('LOWER(kode_inventory) = ?', [strtolower($kode)])->first();
        if (!$inventory) {
            $this->errors[] = "Baris {$nomorBaris}: Kode Inventory \"{$kode}\" tidak ditemukan, dilewati.";
            return false;
        }

        // opsi jenis kerusakan beda per kategori -- Kelengkapan (charger,
        // tas, kabel, dll) gak punya sisi "software" sama sekali, jadi
        // dikasih opsi sendiri. Lihat juga InventoryPenangananController@store.
        $opsiJenisKerusakan = $inventory->isKelengkapan()
            ? ['tidak_berfungsi', 'hancur', 'terputus_sobek']
            : ['software', 'hardware'];

        $jenisKerusakan = strtolower(trim((string) ($row['jenis_kerusakan'] ?? '')));
        $jenisKerusakan = str_replace([' ', '-', '/'], '_', $jenisKerusakan);
        if (!in_array($jenisKerusakan, $opsiJenisKerusakan, true)) {
            $this->errors[] = "Baris {$nomorBaris}: Jenis Kerusakan harus salah satu dari \"" . implode('", "', $opsiJenisKerusakan) . "\" untuk kategori barang ini, dilewati.";
            return false;
        }

        $keluhan = trim((string) ($row['keluhan'] ?? ''));
        if ($keluhan === '') {
            $this->errors[] = "Baris {$nomorBaris}: Keluhan kosong, dilewati.";
            return false;
        }

        $hasilKey = str_replace(' ', '_', strtolower(trim((string) ($row['hasil'] ?? ''))));
        $hasil = self::HASIL_ALIAS[$hasilKey] ?? null;
        if (!$hasil) {
            $this->errors[] = "Baris {$nomorBaris}: Hasil harus \"diperbaiki\" atau \"rusak berat\", dilewati.";
            return false;
        }

        $tanggalSelesai = $this->parseTanggal($row['tanggal_selesai'] ?? null);
        if (!$tanggalSelesai) {
            $this->errors[] = "Baris {$nomorBaris}: Tanggal Selesai wajib diisi & valid, dilewati.";
            return false;
        }

        $tanggalLapor = $this->parseTanggal($row['tanggal_lapor'] ?? null) ?? $tanggalSelesai;
        $tanggalDiterima = $this->parseTanggal($row['tanggal_diterima'] ?? null) ?? $tanggalSelesai;

        $biayaKomponen = null;
        $hargaJasa = null;

        if ($hasil === 'diperbaiki') {
            $biayaKomponenMentah = $row['biaya_komponen'] ?? null;
            $hargaJasaMentah = $row['biaya_jasa'] ?? $row['harga_jasa'] ?? null;

            if ($biayaKomponenMentah === null || $biayaKomponenMentah === '' || !is_numeric($biayaKomponenMentah)) {
                $this->errors[] = "Baris {$nomorBaris}: Biaya Komponen wajib diisi angka kalau Hasil = diperbaiki, dilewati.";
                return false;
            }
            if ($hargaJasaMentah === null || $hargaJasaMentah === '' || !is_numeric($hargaJasaMentah)) {
                $this->errors[] = "Baris {$nomorBaris}: Biaya Jasa wajib diisi angka kalau Hasil = diperbaiki, dilewati.";
                return false;
            }

            $biayaKomponen = (float) $biayaKomponenMentah;
            $hargaJasa = (float) $hargaJasaMentah;
        }

        $pelapor = trim((string) ($row['pelapor'] ?? ''));
        $catatan = trim((string) ($row['catatan'] ?? ''));
        if ($pelapor !== '') {
            $catatan = trim($catatan . ($catatan !== '' ? "\n" : '') . "Pelapor (import): {$pelapor}");
        }

        $noStruk = $this->generateNoStruk('PNG', 'inventory_penanganan', 'no_struk');

        InventoryPenanganan::create([
            'inventory_id' => $inventory->id,
            'inventory_pemakai_id' => null,
            'jenis_kerusakan' => $jenisKerusakan,
            'keluhan' => $keluhan,
            'tanggal_lapor' => $tanggalLapor,
            'lapor_at' => $tanggalLapor,
            'tanggal_diterima' => $tanggalDiterima,
            'diterima_at' => $tanggalDiterima,
            'tanggal_selesai' => $tanggalSelesai,
            'selesai_at' => $tanggalSelesai,
            'harga_jasa' => $hargaJasa,
            'biaya_komponen' => $biayaKomponen,
            'hasil' => $hasil,
            'no_struk' => $noStruk,
            'catatan' => $catatan !== '' ? $catatan : null,
        ]);

        if ($hasil === 'rusak_berat') {
            $inventory->update(['status' => 'rusak_berat']);

            InventoryPemakai::where('inventory_id', $inventory->id)
                ->where('status', 'disetujui')
                ->whereNull('tanggal_pengembalian')
                ->update([
                    'tanggal_pengembalian' => $tanggalSelesai,
                    'dikembalikan_at' => now(),
                    'catatan_pengembalian' => 'Dikembalikan otomatis — inventory dinyatakan rusak berat (import).',
                ]);
        }

        return true;
    }

    private function normalisasiHeader(string $header): string
    {
        $header = trim(strtolower($header));
        $header = preg_replace('/[\s\-\/]+/', '_', $header);
        $header = preg_replace('/[^a-z0-9_]/', '', $header);
        return trim($header, '_');
    }

    private function parseTanggal($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        if (is_numeric($value)) {
            return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value)->format('Y-m-d');
        }

        $teks = trim((string) $value);

        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $teks, $m)) {
            return \Carbon\Carbon::createFromDate((int) $m[3], (int) $m[2], (int) $m[1])->format('Y-m-d');
        }

        try {
            return \Carbon\Carbon::parse($teks)->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function getRowCount()
    {
        return $this->rowCount;
    }

    public function getErrors()
    {
        return $this->errors;
    }
}