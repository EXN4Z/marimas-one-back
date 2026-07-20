<?php

return [
    // Potongan per hari telat, disederhanakan jadi angka flat biar gampang dipahami.
    // Kalau maunya proporsional ke gaji, tinggal ubah rumus di PayrollController.
    'potongan_per_telat' => 50000,

    // Jenis pengajuan izin (lihat PengajuanIzin::JENIS) yang dianggap TIDAK
    // dibayar kalau statusnya disetujui -- dipotong proporsional dari gaji
    // pokok per hari kerja. Jenis lain (tahunan, sakit, dinas, terlambat,
    // pulang_cepat) dianggap izin yang dibayar / tidak memotong gaji.
    'jenis_izin_tanpa_gaji' => ['pribadi', 'lainnya'],
];
