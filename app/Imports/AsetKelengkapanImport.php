<?php

namespace App\Imports;

use App\Models\Aset;
use App\Models\AsetKelengkapan;
use App\Models\LokasiKantor;
use App\Models\Supplier;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;

/**
 * Import Excel data Aset Kelengkapan (charger, tas, mouse, dll) SEBAGAI
 * ITEM BERDIRI SENDIRI -- beda dari AsetBuktiRapiImport yang bikin
 * kelengkapan SEKALIGUS bareng aset utama barunya dalam satu bukti.
 * Import ini dipakai buat nambahin kelengkapan ke aset utama yang SUDAH
 * ADA di sistem (dicari lewat kode_aset), jadi cocok dipakai dari halaman
 * "Kelengkapan Aset" langsung.
 *
 * Format kolom yang diharapkan (baris pertama = header, nama kolom bebas
 * huruf besar/kecil & spasi, dinormalisasi otomatis ke snake_case):
 *   Kode Aset Induk | Lokasi Kantor | Nama | Merek | Tipe | Warna
 *   | Serial Number | Perusahaan | Supplier | Tanggal Pembelian
 *   | No Surat Jalan | No Good Receive | Tanggal Garansi | Status
 *   | Keterangan
 *
 * "Kode Aset Induk" DAN "Lokasi Kantor" sama-sama opsional PER BARIS,
 * tapi SALAH SATU wajib diisi (sama kayak aturan di form tambah/edit
 * kelengkapan lewat UI):
 *   - Kalau "Kode Aset Induk" diisi -> kelengkapan nempel ke aset itu
 *     (harus cocok dengan kode_aset yang sudah ada), dan kolom "Lokasi
 *     Kantor" di baris itu DIABAIKAN (kelengkapan ikut lokasi aset
 *     induknya, sama seperti mutual-exclusive-nya di form UI).
 *   - Kalau "Kode Aset Induk" kosong -> kelengkapan dianggap BERDIRI
 *     SENDIRI (tanpa induk), dan "Lokasi Kantor" WAJIB diisi & harus
 *     cocok (case-insensitive) dengan nama lokasi/cabang yang sudah ada.
 *   - Kalau DUA-DUANYA kosong -> baris itu error, dilewati.
 *
 * "Nama" wajib. Kolom lain opsional. "Supplier" dicari/dibuat otomatis
 * (firstOrCreate by nama), sama seperti pola AsetBuktiRapiImport buat
 * Departemen -- beda dengan "Lokasi Kantor" yang TIDAK dibuat otomatis
 * (harus sudah ada di data master lokasi/cabang).
 * "Status" default "tersedia" kalau kosong, harus salah satu dari
 * tersedia/dipakai/rusak kalau diisi.
 */
class AsetKelengkapanImport implements ToCollection
{
    private const KOLOM_PENANDA_HEADER = 'kode_aset_induk';
    private const MAX_BARIS_DISCAN = 5;
    private const STATUS_VALID = ['tersedia', 'dipakai', 'rusak'];

    protected int $rowCount = 0;
    protected array $errors = [];

    public function collection(Collection $rows)
    {
        $indexHeader = $this->cariBarisHeader($rows);

        if ($indexHeader === null) {
            $this->errors[] = 'Tidak menemukan baris header (kolom "Kode Aset Induk") di ' . self::MAX_BARIS_DISCAN . ' baris pertama.';
            return;
        }

        $headers = $rows[$indexHeader]
            ->map(fn ($h) => $this->normalisasiHeader((string) $h))
            ->toArray();

        $dataRows = $rows->slice($indexHeader + 1);

        foreach ($dataRows as $index => $rawRow) {
            $rowArray = $rawRow->toArray();

            if (count(array_filter($rowArray, fn ($v) => $v !== null && $v !== '')) === 0) {
                continue; // baris kosong, lewati diam-diam
            }

            $row = array_combine($headers, array_pad($rowArray, count($headers), null));
            $baris = $index + 1;

            $kodeAsetInduk = trim((string) ($row['kode_aset_induk'] ?? ''));
            $namaLokasi = trim((string) ($row['lokasi_kantor'] ?? ''));
            $nama = trim((string) ($row['nama'] ?? ''));

            if ($kodeAsetInduk === '' && $namaLokasi === '') {
                $this->errors[] = "Baris data ke-{$baris}: isi salah satu kolom \"Kode Aset Induk\" (kalau nempel ke aset) atau \"Lokasi Kantor\" (kalau berdiri sendiri), dilewati.";
                continue;
            }

            if ($nama === '') {
                $this->errors[] = "Baris data ke-{$baris}: kolom \"Nama\" kosong, dilewati.";
                continue;
            }

            // Aset induk lebih prioritas kalau dua-duanya diisi -- kelengkapan
            // yang nempel ke aset ikut lokasi aset itu, jadi "Lokasi Kantor"
            // di baris yang sama diabaikan (mutual-exclusive, sama kayak form UI).
            $asetIndukId = null;
            $lokasiKantorId = null;

            if ($kodeAsetInduk !== '') {
                $asetInduk = Aset::where('kode_aset', $kodeAsetInduk)->first();

                if (!$asetInduk) {
                    $this->errors[] = "Baris data ke-{$baris}: aset induk dengan kode \"{$kodeAsetInduk}\" tidak ditemukan, dilewati.";
                    continue;
                }

                $asetIndukId = $asetInduk->id;
            } else {
                $lokasi = LokasiKantor::whereRaw('lower(nama) = ?', [mb_strtolower($namaLokasi)])->first();

                if (!$lokasi) {
                    $this->errors[] = "Baris data ke-{$baris}: lokasi kantor \"{$namaLokasi}\" tidak ditemukan, dilewati.";
                    continue;
                }

                $lokasiKantorId = $lokasi->id;
            }

            $status = mb_strtolower(trim((string) ($row['status'] ?? '')));
            if ($status === '') {
                $status = 'tersedia';
            } elseif (!in_array($status, self::STATUS_VALID, true)) {
                $this->errors[] = "Baris data ke-{$baris}: nilai kolom \"Status\" (\"{$row['status']}\") tidak dikenali (harus tersedia/dipakai/rusak), dilewati.";
                continue;
            }

            $namaSupplier = trim((string) ($row['supplier'] ?? ''));
            $supplierId = null;
            if ($namaSupplier !== '') {
                $supplierId = Supplier::firstOrCreate(['nama' => $namaSupplier])->id;
            }

            $serialNumber = trim((string) ($row['serial_number'] ?? '')) ?: null;
            if ($serialNumber && AsetKelengkapan::where('serial_number', $serialNumber)->exists()) {
                $this->errors[] = "Baris data ke-{$baris}: serial number \"{$serialNumber}\" sudah dipakai kelengkapan lain, dilewati.";
                continue;
            }

            try {
                AsetKelengkapan::create([
                    'aset_id'            => $asetIndukId,
                    'lokasi_kantor_id'   => $lokasiKantorId,
                    'nama'               => $nama,
                    'merek'              => trim((string) ($row['merek'] ?? '')) ?: null,
                    'tipe'               => trim((string) ($row['tipe'] ?? '')) ?: null,
                    'warna'              => trim((string) ($row['warna'] ?? '')) ?: null,
                    'serial_number'      => $serialNumber,
                    'perusahaan'         => trim((string) ($row['perusahaan'] ?? '')) ?: null,
                    'supplier_id'        => $supplierId,
                    'tanggal_pembelian'  => $this->parseTanggal($row['tanggal_pembelian'] ?? null),
                    'no_surat_jalan'     => trim((string) ($row['no_surat_jalan'] ?? '')) ?: null,
                    'no_good_receive'    => trim((string) ($row['no_good_receive'] ?? '')) ?: null,
                    'tanggal_garansi'    => $this->parseTanggal($row['tanggal_garansi'] ?? null),
                    'status'             => $status,
                    'keterangan'         => trim((string) ($row['keterangan'] ?? '')) ?: null,
                ]);
                $this->rowCount++;
            } catch (\Exception $e) {
                $this->errors[] = "Baris data ke-{$baris} (\"{$nama}\"): " . $e->getMessage();
            }
        }
    }

    private function cariBarisHeader(Collection $rows): ?int
    {
        $batas = min(self::MAX_BARIS_DISCAN, $rows->count());

        for ($i = 0; $i < $batas; $i++) {
            $selDinormalisasi = $rows[$i]->map(fn ($v) => $this->normalisasiHeader((string) $v));

            if ($selDinormalisasi->contains(self::KOLOM_PENANDA_HEADER)) {
                return $i;
            }
        }

        return null;
    }

    private function normalisasiHeader(string $header): string
    {
        $header = trim($header);
        $header = strtolower($header);
        $header = preg_replace('/[\s\-]+/', '_', $header);
        $header = preg_replace('/[^a-z0-9_]/', '', $header);
        return trim($header, '_');
    }

    private function parseTanggal($value)
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

        return \Carbon\Carbon::parse($teks)->format('Y-m-d');
    }

    public function getRowCount(): int
    {
        return $this->rowCount;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}