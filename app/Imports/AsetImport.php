<?php

namespace App\Imports;

use App\Models\Aset;
use App\Models\JenisAset;
use App\Models\Supplier;
use App\Models\KelengkapanMaster;
use App\Models\AsetKelengkapan;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class AsetImport implements ToCollection, WithHeadingRow
{
    protected $rowCount = 0;
    protected $errors = [];

    public function collection(Collection $rows)
    {
        if ($rows->isNotEmpty()) {
        Log::info('Header Excel terbaca:', array_keys($rows->first()->toArray()));
        }
        foreach ($rows as $index => $rawRow) {
            try {
                // BARU: normalisasi key tiap baris sebelum dipakai. Ini penting
                // karena header di file Excel sering ditulis dengan spasi
                // (mis. "No Good Receive", "Nama Kelengkapan") padahal kode
                // di bawah butuh key snake_case ("no_good_receive", dst).
                // Dengan ini, apapun variasi spasi/kapitalisasi header-nya,
                // hasilnya selalu konsisten.
                $row = collect($rawRow->toArray())
                    ->mapWithKeys(fn ($value, $key) => [$this->normalisasiHeader((string) $key) => $value])
                    ->toArray();

                // 1. Lookup / auto-create Jenis Aset
                $jenis = JenisAset::firstOrCreate(
                    ['nama' => trim($row['jenis'])]
                );

                // 2. Lookup / auto-create Supplier
                $supplier = Supplier::firstOrCreate(
                    ['nama' => trim($row['supplier'])]
                );

                // 3. Simpan / update data Aset
                //    pakai serial_number sebagai penanda unik
                $aset = Aset::updateOrCreate(
                    ['serial_number' => $row['serial_number']],
                    [
                        'jenis_id'          => $jenis->id,
                        'supplier_id'       => $supplier->id,
                        'merek'             => $row['merek'],
                        'tipe'              => $row['tipe'],
                        'warna'             => $row['warna'],
                        'tanggal_garansi'   => $this->parseTanggal($row['tanggal_garansi']),
                        'tanggal_pembelian' => $this->parseTanggal($row['tanggal_pembelian']),
                        'perusahaan'        => $row['perusahaan'],
                        'no_surat_jalan'    => $row['no_surat_jalan'],
                        'no_good_receive'   => $row['no_good_receive'],
                        'status'            => $row['status'] ?? 'aktif',
                    ]
                );

                // 4. Lookup / auto-create Kelengkapan Master
                if (!empty($row['nama_kelengkapan'])) {
                    $kelengkapanMaster = KelengkapanMaster::firstOrCreate(
                        ['nama' => trim($row['nama_kelengkapan'])]
                    );

                    // 5. Simpan relasi ke aset_kelengkapan
                    //    catatan: satu aset bisa punya beberapa baris kelengkapan yang sama,
                    //    jadi di sini pakai create biasa (bukan updateOrCreate),
                    //    kecuali kamu mau tiap aset+kelengkapan itu unik (lihat catatan di bawah)
                    AsetKelengkapan::create([
                        'aset_id'               => $aset->id,
                        'kelengkapan_master_id' => $kelengkapanMaster->id,
                        'keterangan'            => $row['keterangan_kelengkapan'] ?? null,
                    ]);
                }

                $this->rowCount++;
            } catch (\Exception $e) {
                $this->errors[] = "Baris " . ($index + 2) . ": " . $e->getMessage();
            }
        }
    }

    /**
     * Ubah nama kolom jadi format snake_case yang konsisten.
     * Contoh: "No Good Receive" -> "no_good_receive"
     *         "Nama  Kelengkapan" -> "nama_kelengkapan" (spasi ganda pun aman)
     *         "No. Surat Jalan"  -> "no_surat_jalan"
     */
    private function normalisasiHeader(string $header): string
    {
        $header = trim($header);
        $header = strtolower($header);
        $header = preg_replace('/[\s\-]+/', '_', $header); // spasi/strip berturutan -> 1 underscore
        $header = preg_replace('/[^a-z0-9_]/', '', $header); // buang karakter selain huruf/angka/underscore
        $header = trim($header, '_'); // buang underscore nyasar di awal/akhir
        return $header;
    }

    private function parseTanggal($value)
    {
        if (empty($value)) return null;

        // Excel kadang kirim tanggal sebagai serial number, kadang sebagai string
        if (is_numeric($value)) {
            return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value)->format('Y-m-d');
        }

        return \Carbon\Carbon::parse($value)->format('Y-m-d');
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