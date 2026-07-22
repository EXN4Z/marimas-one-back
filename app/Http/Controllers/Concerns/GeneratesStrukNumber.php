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
}
