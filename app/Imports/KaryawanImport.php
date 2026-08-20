<?php

namespace App\Imports;

use App\Models\Departemen;
use App\Models\User;
use App\Notifications\PasswordAkunBaru;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class KaryawanImport implements ToCollection
{
    protected $rowCount = 0;
    protected $errors = [];

    private const MAX_BARIS_DISCAN = 10;
    private const KOLOM_PENANDA_HEADER = 'nik';

    public function collection(Collection $rows)
    {
        $indexHeader = $this->cariBarisHeader($rows);

        if ($indexHeader === null) {
            $this->errors[] = 'Tidak menemukan baris header (kolom "NIK") di ' . self::MAX_BARIS_DISCAN . ' baris pertama.';
            return;
        }

        $headers = $rows[$indexHeader]
            ->map(fn ($h) => $this->normalisasiHeader((string) $h))
            ->toArray();

        $dataRows = $rows->slice($indexHeader + 1);

        foreach ($dataRows as $index => $rawRow) {
            try {
                DB::transaction(function () use ($rawRow, $headers, $index) {
                    $rowArray = $rawRow->toArray();

                    if (count(array_filter($rowArray, fn ($v) => $v !== null && $v !== '')) === 0) {
                        return;
                    }

                    $row = array_combine(
                        $headers,
                        array_pad($rowArray, count($headers), null)
                    );

                    $nik = trim((string) ($row['nik'] ?? ''));
                    if ($nik === '') {
                        $this->errors[] = "Baris ke-{$index}: NIK kosong, dilewati.";
                        return;
                    }

                    $email = trim((string) ($row['email'] ?? ''));
                    if ($email === '') {
                        $this->errors[] = "Baris ke-{$index}: email kosong, tidak bisa kirim password, dilewati.";
                        return;
                    }

                    $departemenId = null;
                    $namaDepartemen = trim((string) ($row['departemen'] ?? ''));
                    if ($namaDepartemen !== '') {
                        $departemenId = Departemen::firstOrCreate(['nama' => $namaDepartemen])->id;
                    }

                    $userLama = User::where('nik', $nik)->first();

                    $passwordPlain = explode(' ', trim((string) ($row['nama'] ?? '')))[0];

                    $user = User::updateOrCreate(
                        ['nik' => $nik],
                        [
                            'name'          => $row['nama'] ?? null,
                            'email'         => $email,
                            'phone'         => $row['phone'] ?? null,
                            'departemen_id' => $departemenId,
                            'tanggal_masuk' => $this->parseTanggal($row['tanggal_masuk'] ?? null),
                            'role'          => 'karyawan',
                            ...($userLama ? [] : ['password' => $passwordPlain]),
                        ]
                    );

                    if (!$userLama) {
                        DB::afterCommit(function () use ($user, $passwordPlain) {
                            $user->notify(new PasswordAkunBaru($passwordPlain));
                        });
                    }

                    $this->rowCount++;
                });
            } catch (\Exception $e) {
                $this->errors[] = "Baris ke-{$index}: " . $e->getMessage();
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
        $header = trim($header, '_');
        return $header;
    }

    private function parseTanggal($value)
    {
        if (empty($value)) return null;

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