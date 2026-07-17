<?php

return [
    'jam_masuk_standar' => '08:00',
    'toleransi_menit' => 15,
    'jam_pulang_standar' => '17:00',
    'office_lat' => env('OFFICE_LATITUDE'),
    'office_lng' => env('OFFICE_LONGITUDE'),
    'radius' => env('OFFICE_RADIUS_METERS', 100),
];