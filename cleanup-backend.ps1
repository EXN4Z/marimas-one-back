# Jalankan dari root folder marimas-one-back-main
# Hapus semua file yang cuma dipakai oleh fitur Absensi, Izin/Cuti,
# Ticketing, Agenda, dan Chatbot yang sudah dihapus.

$files = @(
    "app\Models\Ticket.php",
    "app\Models\PengajuanCuti.php",
    "app\Console\Commands\DeleteExpiredCuti.php",
    "app\Http\Controllers\Dashboard\LaporanController.php",
    "config\absensi.php",
    "database\migrations\2026_07_12_090000_create_absensis_table.php",
    "database\migrations\2026_07_14_020606_add_index_to_absensis_table.php",
    "database\migrations\2026_07_16_081334_add_faces_to_absensis_table.php",
    "database\migrations\2026_07_16_093011_add_face_verification_to_absensis_table.php",
    "database\migrations\2026_07_16_093914_remove_verif_from_absensis_table.php",
    "database\migrations\2026_07_16_144000_add_status_pulang_to_absensis_table.php",
    "database\migrations\2026_07_13_012912_create_pengajuan_cutis_tables.php",
    "database\migrations\2026_07_13_091404_create_pengajuan_izin_tables_table.php",
    "database\migrations\2026_07_16_104341_add_kuota_izin_tahunan_to_pekerja_table.php",
    "database\migrations\2026_07_16_112035_add_tahunan_to_pengajuan_izin_table.php",
    "database\migrations\2026_07_16_120000_drop_pengajuan_izin_jenis_check_constraint.php",
    "database\migrations\2026_07_13_043442_create_tickets_table.php",
    "database\migrations\2026_07_13_060000_add_missing_columns_to_tickets_table.php",
    "database\migrations\2026_07_16_100000_create_agenda_table.php"
)

foreach ($f in $files) {
    if (Test-Path $f) {
        Remove-Item $f -Force
        Write-Host "Deleted: $f"
    } else {
        Write-Host "Skip (not found): $f"
    }
}
