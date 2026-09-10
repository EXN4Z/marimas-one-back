<?php

namespace App\Imports;

use App\Models\LokasiKantor;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;

/**
 * Import Excel data referensi Cabang / Lokasi Kantor (Master Data).
 * Mirror PerusahaanImport persis -- bedanya cuma target model (LokasiKantor,
 * bukan Perusahaan). Lihat PerusahaanImport buat penjelasan lengkap tiap
 * bagian (deteksi header, filter baris footer hasil export, dst).
 *
 * Format kolom yang diharapkan (baris pertama = header, nama kolom bebas
 * huruf besar/kecil & spasi, dinormalisasi otomatis ke snake_case):
 *   Nama | Alamat | Telepon | Link
 *
 * Setiap baris dicocokkan ke `nama` (unique). Kalau cabang dengan nama itu
 * SUDAH ada, datanya di-UPDATE (kolom yang dikosongkan di Excel TIDAK
 * menimpa data lama). Kalau belum ada, dibuatkan baris baru.
 */
class CabangImport implements ToCollection
{
    private const KOLOM_PENANDA_HEADER = 'nama';
    private const MAX_BARIS_DISCAN = 10;

    protected int $createdCount = 0;
    protected int $updatedCount = 0;
    protected array $errors = [];

    public function collection(Collection $rows)
    {
        $indexHeader = $this->cariBarisHeader($rows);

        if ($indexHeader === null) {
            $this->errors[] = 'Tidak menemukan baris header (kolom "Nama") di ' . self::MAX_BARIS_DISCAN . ' baris pertama.';
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

            if ($this->adalahBarisFooter($rowArray)) {
                continue; // baris footer disclaimer hasil Export Excel, lewati diam-diam
            }

            $row = array_combine($headers, array_pad($rowArray, count($headers), null));
            $nama = trim((string) ($row['nama'] ?? ''));

            if ($nama === '') {
                $this->errors[] = 'Baris data ke-' . ($index + 1) . ': kolom Nama kosong, dilewati.';
                continue;
            }

            $alamat = trim((string) ($row['alamat'] ?? ''));
            $telepon = trim((string) ($row['telepon'] ?? ''));
            $link = trim((string) ($row['link'] ?? ''));

            try {
                $cabang = LokasiKantor::where('nama', $nama)->first();

                if ($cabang) {
                    $cabang->update([
                        'alamat'  => $alamat !== '' ? $alamat : $cabang->alamat,
                        'telepon' => $telepon !== '' ? $telepon : $cabang->telepon,
                        'link'    => $link !== '' ? $link : $cabang->link,
                    ]);
                    $this->updatedCount++;
                } else {
                    LokasiKantor::create([
                        'nama'    => $nama,
                        'alamat'  => $alamat !== '' ? $alamat : null,
                        'telepon' => $telepon !== '' ? $telepon : null,
                        'link'    => $link !== '' ? $link : null,
                    ]);
                    $this->createdCount++;
                }
            } catch (\Exception $e) {
                $this->errors[] = 'Baris data ke-' . ($index + 1) . ' ("' . $nama . '"): ' . $e->getMessage();
            }
        }
    }

    /**
     * Deteksi baris footer disclaimer yang otomatis ditambahkan fitur Export
     * Excel ("Dokumen digenerate otomatis oleh Marimas One ..."). Kalau file
     * hasil export diimpor balik tanpa diedit, baris ini ikut kebaca sebagai
     * baris data dan bikin entri cabang palsu -- makanya harus disaring.
     */
    private function adalahBarisFooter(array $rowArray): bool
    {
        $gabungan = strtolower(implode(' ', array_map('strval', $rowArray)));

        return str_contains($gabungan, 'digenerate otomatis');
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
        $header = str_replace("\xEF\xBB\xBF", '', $header);
        $header = preg_replace('/[\x{00A0}\x{200B}\x{FEFF}]/u', ' ', $header);

        $header = trim($header);
        $header = strtolower($header);
        $header = preg_replace('/[\s\-]+/', '_', $header);
        $header = preg_replace('/[^a-z0-9_]/', '', $header);
        return trim($header, '_');
    }

    public function getCreatedCount(): int
    {
        return $this->createdCount;
    }

    public function getUpdatedCount(): int
    {
        return $this->updatedCount;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}