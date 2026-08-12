<?php

namespace App\Imports;

use App\Models\Aset;
use App\Models\AsetKelengkapan;
use App\Models\AsetPemakai;
use App\Models\Departemen;
use App\Models\JenisAset;
use App\Models\KelengkapanMaster;
use App\Models\Pekerja;
use App\Models\User;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Import "Data Aset Rapi" -- format BARU, BEDA dari AsetBuktiImport (format
 * lama yang 1 baris Excel bisa berisi 4 barang sekaligus lewat kolom
 * "Nama Barang 1..4"). Di format ini SATU BARIS = SATU BARANG, dan kolom
 * `Kategori` bilang eksplisit apakah baris itu "Aset Utama" (dibikinkan
 * baris baru di tabel `aset`) atau "Kelengkapan" (ditempel sebagai
 * `aset_kelengkapan` ke Aset Utama TERAKHIR yang seNo Bukti sama).
 *
 * Kolom yang diharapkan (nama header bebas huruf besar/kecil & spasi,
 * dinormalisasi otomatis):
 *   No Bukti | Tanggal | Departemen | NIK | Penerima | Nama Barang (Asli)
 *   | Jenis Aset | Merek/Tipe | Kategori | Jumlah | Keterangan | Perusahaan
 *
 * Contoh 1 kelompok baris (1 No Bukti bisa terdiri dari beberapa baris):
 *   26/00002 | ... | Laptop HP 14s-dq5001TU | Laptop | HP 14s-dq5001TU | Aset Utama | 1 | Silver; S/N : 5CD2360V48
 *   26/00002 | ... | Charger                | Charger| -               | Kelengkapan| 1 | S/N : 0A3JUGLG5L
 *   26/00002 | ... | Tas Hp                 | Tas HP | -               | Kelengkapan| 1 | Silver
 * -> hasilnya 1 baris Aset (Laptop HP), dengan Charger & Tas HP jadi 2
 *    baris aset_kelengkapan yang nempel ke Aset itu.
 *
 * PENERIMA / PEKERJA / ASET_PEMAKAI: sama seperti AsetBuktiImport -- kolom
 * NIK & Penerima dipakai cari/bikin Pekerja (NIK disimpan ke kolom `nip`
 * tabel pekerja). Kalau ketemu/berhasil dibuat, tiap Aset Utama yang
 * dibuat di baris yang sama statusnya "dipakai" (bukan "tersedia") dan
 * dibuatkan 1 baris AsetPemakai. Kalau NIK kosong tapi nama penerima ada,
 * dicatat sebagai warning & aset tetap dibuat tapi tanpa data pemakai.
 *
 * MEREK/TIPE: kolom "Merek/Tipe" di sumber datanya cuma 1 kolom gabungan
 * (bukan 2 kolom terpisah), jadi dipisah heuristik: kata pertama jadi
 * `merek`, sisanya jadi `tipe` (mis. "HP 14s-dq5001TU" -> merek "HP", tipe
 * "14s-dq5001TU"). Ini heuristik & TIDAK selalu tepat untuk merek yang
 * namanya lebih dari 1 kata (mis. "Sonic Gear" kepisah jadi merek "Sonic"),
 * tapi keterangan aslinya tetap utuh tersimpan di kolom `keterangan`
 * (dari kolom Keterangan Excel) jadi tidak ada info yang hilang total.
 *
 * KETERANGAN: dipakai apa adanya (disimpan utuh ke kolom `keterangan`),
 * SEKALIGUS diparsing lewat parseKeterangan() (logic sama seperti
 * AsetBuktiImport) buat ekstrak serial_number, warna, & kelengkapan
 * tambahan yang nyempil di teks bebas (mis. "(remote, adaptor, kabel
 * HDMI)"). Kelengkapan tambahan hasil parsing ini SELALU ditempel ke Aset
 * Utama terakhir yang lagi diproses (baik baris ini sendiri Aset Utama,
 * maupun baris Kelengkapan yang keterangannya kebetulan nyebut kelengkapan
 * lain lagi).
 */
class AsetBuktiRapiImport implements ToCollection
{
    protected $rowCount = 0;
    protected $errors = [];

    /** Maksimal berapa baris awal yang discan buat nyari baris header */
    private const MAX_BARIS_DISCAN = 10;

    /** Kolom penanda baris header -- "No Bukti" dipilih karena khas & selalu ada */
    private const KOLOM_PENANDA_HEADER = 'no_bukti';

    /** Kata kunci warna (ID + EN) buat deteksi token warna di teks keterangan */
    private const WARNA_KEYWORDS = [
        'black', 'white', 'silver', 'grey', 'gray', 'blue', 'red', 'green',
        'yellow', 'pink', 'purple', 'gold', 'navy', 'cream', 'orange',
        'coklat', 'cokelat', 'putih', 'hitam', 'merah', 'biru', 'kuning',
        'hijau', 'ungu', 'abu-abu', 'abu',
    ];

    /** Prefix token yang SENGAJA tidak dianggap warna maupun kelengkapan */
    private const KETERANGAN_PREFIX_DIABAIKAN = '/^\s*(model|imei|p\s*\/?\s*n)\b/i';

    public function collection(Collection $rows)
    {
        $indexHeader = $this->cariBarisHeader($rows);

        if ($indexHeader === null) {
            $this->errors[] = 'Tidak menemukan baris header (kolom "No Bukti") di ' . self::MAX_BARIS_DISCAN . ' baris pertama.';
            return;
        }

        $headers = $rows[$indexHeader]
            ->map(fn ($h) => $this->normalisasiHeader((string) $h))
            ->toArray();

        Log::info('Header Excel Data Aset Rapi terbaca:', $headers);

        $dataRows = $rows->slice($indexHeader + 1);

        // State yang "nempel" antar baris SELAMA masih di No Bukti yang
        // sama -- di-reset begitu ketemu baris dengan No Bukti berbeda.
        // $asetUtamaTerakhir dipakai buat nentuin ke Aset mana baris
        // Kelengkapan berikutnya harus ditempel.
        $noBuktiSaatIni = null;
        $asetUtamaTerakhir = null;

        foreach ($dataRows as $index => $rawRow) {
            try {
                DB::transaction(function () use ($rawRow, $headers, $index, &$noBuktiSaatIni, &$asetUtamaTerakhir) {
                    $rowArray = $rawRow->toArray();

                    if (count(array_filter($rowArray, fn ($v) => $v !== null && $v !== '')) === 0) {
                        return;
                    }

                    $row = array_combine(
                        $headers,
                        array_pad($rowArray, count($headers), null)
                    );

                    $noBukti = trim((string) ($row['no_bukti'] ?? ''));

                    if ($noBukti === '') {
                        return;
                    }

                    // No Bukti baru (beda dari baris sebelumnya) -> reset
                    // pointer aset utama, biar baris Kelengkapan gak salah
                    // nempel ke Aset dari bukti lain.
                    if ($noBukti !== $noBuktiSaatIni) {
                        $noBuktiSaatIni = $noBukti;
                        $asetUtamaTerakhir = null;
                    }

                    $namaDepartemen = trim((string) ($row['departemen'] ?? ''));
                    $departemenId = null;

                    if ($namaDepartemen !== '') {
                        $departemenId = Departemen::firstOrCreate(['nama' => $namaDepartemen])->id;
                    }

                    // NIK & Penerima diulang di SETIAP baris (bukan cuma
                    // baris pertama per bukti) di format ini, jadi aman
                    // diresolusi per baris -- Pekerja::where(...)->first()
                    // idempoten, gak bikin duplikat kalau sudah ada.
                    $namaPenerima = trim((string) ($row['penerima'] ?? ''));
                    $nikPenerima = trim((string) ($row['nik'] ?? ''));
                    $pekerjaPenerima = null;

                    if ($namaPenerima !== '') {
                        if ($nikPenerima !== '') {
                            $pekerjaPenerima = Pekerja::where('nik', $nikPenerima)->first();

                            if (!$pekerjaPenerima) {
                                $userPenerima = User::create([
                                    'name'     => $namaPenerima,
                                    'email'    => 'nik' . $nikPenerima . '@placeholder.local',
                                    'password' => Str::random(32),
                                    'role'     => 'karyawan',
                                ]);

                                $pekerjaPenerima = Pekerja::create([
                                    'user_id'       => $userPenerima->id,
                                    'nik'           => $nikPenerima,
                                    'departemen_id' => $departemenId,
                                ]);
                            }
                        } else {
                            $this->errors[] = 'Baris data ke-' . ($index + 1) . ': ada nama penerima ("' . $namaPenerima . '") tapi NIK kosong, aset dibuat tanpa data pemakai.';
                        }
                    }

                    $statusAset = $pekerjaPenerima ? 'dipakai' : 'tersedia';

                    $kategori = mb_strtolower(trim((string) ($row['kategori'] ?? '')));
                    $isKelengkapan = str_contains($kategori, 'lengkap');
                    $isAsetUtama = str_contains($kategori, 'utama');

                    $namaJenis = trim((string) ($row['jenis_aset'] ?? ''));
                    $keteranganAsli = $row['keterangan'] ?? null;
                    $hasilParse = $this->parseKeterangan($keteranganAsli);

                    if ($isKelengkapan) {
                        if ($namaJenis === '') {
                            $this->errors[] = 'Baris data ke-' . ($index + 1) . ': baris Kelengkapan tapi kolom "Jenis Aset" kosong, dilewati.';
                            return;
                        }

                        if (!$asetUtamaTerakhir) {
                            // Baris Kelengkapan muncul duluan sebelum ada
                            // Aset Utama di No Bukti yang sama -- gak ada
                            // aset buat ditempeli, jadi dilewati (dicatat
                            // sebagai warning, bukan bikin Aset nyasar).
                            $this->errors[] = 'Baris data ke-' . ($index + 1) . ': barang kelengkapan "' . $namaJenis . '" (No Bukti ' . $noBukti . ') dilewati karena belum ada Aset Utama di bukti yang sama untuk ditempeli.';
                            return;
                        }

                        $kelengkapanMaster = KelengkapanMaster::firstOrCreate(['nama' => $namaJenis]);

                        AsetKelengkapan::create([
                            'aset_id'               => $asetUtamaTerakhir->id,
                            'kelengkapan_master_id' => $kelengkapanMaster->id,
                            'keterangan'            => $keteranganAsli,
                        ]);

                        // Kelengkapan tambahan yang nyempil di teks
                        // Keterangan baris Kelengkapan ini (jarang, tapi
                        // dijaga-jaga) tetap ditempel ke Aset Utama yang
                        // sama.
                        $this->tempelKelengkapanTambahan($asetUtamaTerakhir, $hasilParse['kelengkapan']);

                        $this->rowCount++;
                        return;
                    }

                    if (!$isAsetUtama) {
                        $this->errors[] = 'Baris data ke-' . ($index + 1) . ': nilai kolom "Kategori" ("' . ($row['kategori'] ?? '') . '") tidak dikenali (harus "Aset Utama" atau "Kelengkapan"), baris dilewati.';
                        return;
                    }

                    if ($namaJenis === '') {
                        $this->errors[] = 'Baris data ke-' . ($index + 1) . ': baris Aset Utama tapi kolom "Jenis Aset" kosong, dilewati.';
                        return;
                    }

                    $jenis = JenisAset::firstOrCreate(['nama' => $namaJenis]);

                    [$merek, $tipe] = $this->pisahMerekTipe((string) ($row['merek_tipe'] ?? ''));

                    $aset = Aset::create([
                        'jenis_id'       => $jenis->id,
                        'departemen_id'  => $departemenId,
                        'merek'          => $merek,
                        'tipe'           => $tipe,
                        'warna'          => $hasilParse['warna'],
                        'serial_number'  => $hasilParse['serial_number'],
                        'jumlah'         => $row['jumlah'] ?? null,
                        'perusahaan'     => $row['perusahaan'] ?? null,
                        'keterangan'     => $keteranganAsli,
                        'no_bukti'       => $noBukti,
                        'tanggal'        => $this->parseTanggal($row['tanggal'] ?? null),
                        'nik'            => $nikPenerima !== '' ? $nikPenerima : null,
                        'penerima'       => $namaPenerima !== '' ? $namaPenerima : null,
                        'status'         => $statusAset,
                    ]);

                    $this->tempelKelengkapanTambahan($aset, $hasilParse['kelengkapan']);

                    if ($pekerjaPenerima) {
                        AsetPemakai::create([
                            'aset_id'            => $aset->id,
                            'pekerja_id'         => $pekerjaPenerima->id,
                            'user_id'            => $pekerjaPenerima->user_id,
                            'status'             => 'disetujui',
                            'tanggal_penerimaan' => $this->parseTanggal($row['tanggal'] ?? null),
                        ]);
                    }

                    $asetUtamaTerakhir = $aset;
                    $this->rowCount++;
                });
            } catch (\Exception $e) {
                $this->errors[] = 'Baris data ke-' . ($index + 1) . ': ' . $e->getMessage();
            }
        }
    }

    /**
     * Tempel daftar nama kelengkapan (hasil ekstraksi dari teks bebas
     * kolom Keterangan, lihat parseKeterangan()) ke 1 Aset. Dipisah jadi
     * method sendiri karena dipanggil dari 2 tempat (baris Aset Utama &
     * baris Kelengkapan).
     *
     * @param string[] $namaNamaKelengkapan
     */
    private function tempelKelengkapanTambahan(Aset $aset, array $namaNamaKelengkapan): void
    {
        foreach ($namaNamaKelengkapan as $namaKelengkapan) {
            $kelengkapanMaster = KelengkapanMaster::firstOrCreate(['nama' => $namaKelengkapan]);

            AsetKelengkapan::create([
                'aset_id'               => $aset->id,
                'kelengkapan_master_id' => $kelengkapanMaster->id,
            ]);
        }
    }

    /**
     * Pisah teks kolom "Merek/Tipe" jadi [merek, tipe] pakai heuristik kata
     * pertama = merek, sisanya = tipe. Lihat catatan MEREK/TIPE di atas
     * class kenapa ini heuristik, bukan parsing pasti.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function pisahMerekTipe(string $teks): array
    {
        $teks = trim($teks);

        if ($teks === '' || $teks === '-') {
            return [null, null];
        }

        if (!str_contains($teks, ' ')) {
            return [$teks, null];
        }

        [$merek, $tipe] = explode(' ', $teks, 2);

        return [trim($merek), trim($tipe)];
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
        $header = preg_replace('/[\s\-\/]+/', '_', $header);
        $header = preg_replace('/[^a-z0-9_]/', '', $header);
        $header = trim($header, '_');
        return $header;
    }

    /**
     * Ekstrak serial_number, warna, dan daftar kelengkapan dari 1 teks
     * keterangan bebas. Sama persis logicnya dengan AsetBuktiImport.
     *
     * @return array{serial_number: ?string, warna: ?string, kelengkapan: string[]}
     */
    private function parseKeterangan(?string $teks): array
    {
        $hasil = ['serial_number' => null, 'warna' => null, 'kelengkapan' => []];

        if (empty(trim((string) $teks))) {
            return $hasil;
        }

        $sisaTeks = (string) $teks;

        // 1. Cari & keluarkan pola S/N atau SN (dengan/tanpa spasi & titik dua)
        if (preg_match('/\bS\s*\/?\s*N\s*:?\s*([A-Za-z0-9\-]+)/i', $sisaTeks, $match, PREG_OFFSET_CAPTURE)) {
            $hasil['serial_number'] = trim($match[1][0]);
            $panjangMatch = strlen($match[0][0]);
            $posisiMatch = $match[0][1];
            $sisaTeks = substr($sisaTeks, 0, $posisiMatch) . substr($sisaTeks, $posisiMatch + $panjangMatch);
        }

        // 2. Isi dalam kurung "(...)" selalu dianggap daftar kelengkapan
        if (preg_match_all('/\(([^)]*)\)/', $sisaTeks, $matchKurung)) {
            foreach ($matchKurung[1] as $isiKurung) {
                $hasil['kelengkapan'] = array_merge($hasil['kelengkapan'], $this->pisahDaftar($isiKurung));
            }
            $sisaTeks = preg_replace('/\(([^)]*)\)/', '', $sisaTeks);
        }

        // 3. Sisa teks dipisah per token; token pertama yang cocok kata
        //    kunci warna jadi `warna`, sisanya (kecuali Model/Imei/P/N)
        //    masuk `kelengkapan`
        foreach ($this->pisahDaftar($sisaTeks) as $token) {
            if (preg_match(self::KETERANGAN_PREFIX_DIABAIKAN, $token)) {
                continue;
            }

            if ($hasil['warna'] === null && preg_match('/\b(' . implode('|', self::WARNA_KEYWORDS) . ')\b/i', $token)) {
                $hasil['warna'] = $token;
                continue;
            }

            $hasil['kelengkapan'][] = $token;
        }

        return $hasil;
    }

    /**
     * Pisah teks jadi daftar token berdasarkan koma/titik-koma, lalu tiap
     * token dipisah lagi kalau ada kata "dan" (mis. "manual book dan garansi").
     *
     * @return string[]
     */
    private function pisahDaftar(string $teks): array
    {
        $hasil = [];

        foreach (preg_split('/[;,]/', $teks) as $bagian) {
            foreach (preg_split('/\s+dan\s+/i', $bagian) as $token) {
                $token = trim($token);
                if ($token !== '') {
                    $hasil[] = $token;
                }
            }
        }

        return $hasil;
    }

    /**
     * Beda dari AsetBuktiImport: di sini tanggal teks (bukan serial Excel)
     * DIPAKSA diparse sebagai DD/MM/YYYY dulu (format Indonesia yang
     * dipakai di sumber data ini), baru fallback ke Carbon::parse() kalau
     * polanya gak cocok. Ini PENTING -- Carbon::parse('06/01/2026') secara
     * default membaca gaya Amerika (MM/DD/YYYY) = 1 Juni, PADAHAL yang
     * dimaksud 6 Januari. Tanpa fix ini tanggal bisa salah total untuk
     * baris apa pun yang tanggal & bulannya sama-sama <= 12.
     */
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

    public function getRowCount()
    {
        return $this->rowCount;
    }

    public function getErrors()
    {
        return $this->errors;
    }
}