<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Support\Facades\DB;

trait GeneratesStrukNumber
{
    /**
     * Bikin nomor struk sekuensial per hari, format: {PREFIX}-{YYYYMMDD}-{0001}.
     * Dipanggil di dalam DB::transaction() supaya lockForUpdate()-nya kepakai
     * dan gak ada nomor bentrok kalau ada 2 request barengan.
     */
    protected function generateNoStruk(string $prefix, string $table, string $column): string
    {
        $tanggal = now()->format('Ymd');
        $like = "{$prefix}-{$tanggal}-%";

        $count = DB::table($table)
            ->where($column, 'like', $like)
            ->lockForUpdate()
            ->get()
            ->count();

        return sprintf('%s-%s-%04d', $prefix, $tanggal, $count + 1);
    }

    /**
     * Bikin nomor struk ACAK buat peminjaman/pengembalian, format:
     * {6 char huruf+angka acak}-{DDMMYY}, mis. K3F9X2-020926.
     * Gak pakai prefix STJ/KBL lagi -- cukup tanggal+tahun biar tetap
     * gampang dilacak kejadiannya kapan, sisanya acak biar simple &
     * gak gampang ditebak urutannya. Charset buang I/O/0/1 biar gak
     * ketuker pas dibaca manual. Dicek unik ke DB, retry kalau tabrakan
     * (peluangnya sangat kecil, tapi tetap dijaga).
     */
    protected function generateNoStrukRandom(string $table, string $column): string
    {
        $charset = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $tanggal = now()->format('dmy');

        do {
            $random = '';
            for ($i = 0; $i < 6; $i++) {
                $random .= $charset[random_int(0, strlen($charset) - 1)];
            }
            $noStruk = "{$random}-{$tanggal}";
        } while (DB::table($table)->where($column, $noStruk)->exists());

        return $noStruk;
    }

    /**
     * Bikin nomor struk sekuensial per TAHUN, format: {YY}-{00001}, mis.
     * 26-00001 -- sama persis logic-nya kayak trigger generate_kode_inventory
     * (MAX+1, bukan COUNT, biar aman kalau ada row yang dihapus di tengah;
     * reset ke 00001 tiap ganti tahun karena pattern like-nya beda per tahun).
     * Dipanggil di dalam DB::transaction() supaya lockForUpdate()-nya kepakai.
     */
    protected function generateNoStrukTahunan(string $table, string $column): string
    {
        $tahun = now()->format('y');
        $like = "{$tahun}-%";

        $terakhir = DB::table($table)
            ->where($column, 'like', $like)
            ->lockForUpdate()
            ->pluck($column)
            ->map(fn ($kode) => (int) substr($kode, -5))
            ->max();

        $nomor = ($terakhir ?? 0) + 1;

        return sprintf('%s-%05d', $tahun, $nomor);
    }
}