<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Notifications/AsetKerusakanDilaporkan.php, AsetKerusakanSelesai.php,
     * dan AsetKelengkapanKerusakanDilaporkan.php sempat nyimpen `url` basi
     * ('/penanganan-aset', '/master-data?tab=kelengkapan_aset') -- sudah
     * diperbaiki di kode Notification-nya, TAPI baris `notifications` yang
     * SUDAH kesimpen sebelum perbaikan itu tetap bawa `url` lama di kolom
     * `data` (JSON), karena Laravel nyimpen isi notifikasi apa adanya pas
     * dibuat, bukan referensi live ke kode. Migrasi ini backfill baris-baris
     * lama itu biar notif yang udah ada di database ikut ke-fix juga, bukan
     * cuma notif yang baru dibuat setelah deploy.
     */
    public function up(): void
    {
        DB::table('notifications')
            ->where('type', \App\Notifications\AsetKerusakanDilaporkan::class)
            ->orWhere('type', \App\Notifications\AsetKerusakanSelesai::class)
            ->get(['id', 'type', 'data'])
            ->each(function ($row) {
                $data = json_decode($row->data, true);
                if (!is_array($data) || ($data['url'] ?? null) !== '/penanganan-aset') {
                    return;
                }
                $data['url'] = '/penanganan-inventory';
                DB::table('notifications')->where('id', $row->id)->update(['data' => json_encode($data)]);
            });

        DB::table('notifications')
            ->where('type', \App\Notifications\AsetKelengkapanKerusakanDilaporkan::class)
            ->get(['id', 'data'])
            ->each(function ($row) {
                $data = json_decode($row->data, true);
                if (!is_array($data) || ($data['url'] ?? null) !== '/master-data?tab=kelengkapan_aset') {
                    return;
                }
                $data['url'] = '/master-data?tab=kelengkapan_inventory';
                DB::table('notifications')->where('id', $row->id)->update(['data' => json_encode($data)]);
            });
    }

    public function down(): void
    {
        // Data backfill satu arah -- gak ada nilai baliknya (url lama udah
        // gak valid, gak masuk akal buat "dikembalikan").
    }
};