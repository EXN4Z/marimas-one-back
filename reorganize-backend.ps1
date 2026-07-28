# Jalankan di root repo marimas-one-back (folder yang ada app/, composer.json)
# Cara pakai: buka PowerShell di folder itu, jalankan: .\reorganize-backend.ps1
$ErrorActionPreference = "Stop"

$ctrl = "app/Http/Controllers"

Write-Host "== Bikin folder modul ==" -ForegroundColor Cyan
"Inventaris","Karyawan","Absensi","Organisasi","Izin","Dashboard","Ticketing","Auth" | ForEach-Object {
    New-Item -ItemType Directory -Force -Path "$ctrl/$_" | Out-Null
}

Write-Host "== Hapus junk ==" -ForegroundColor Cyan
if (Test-Path "src") { Remove-Item -Recurse -Force "src" }
if (Test-Path "top") { Remove-Item -Force "top" }
if (Test-Path "app/Notification") { Remove-Item -Recurse -Force "app/Notification" }
if (Test-Path "app/Models/pengajuan_izin_table.php") { Remove-Item -Force "app/Models/pengajuan_izin_table.php" }

Write-Host "== git mv controller ke folder modul ==" -ForegroundColor Cyan

git mv "$ctrl/AsetController.php" "$ctrl/Inventaris/AsetController.php"
git mv "$ctrl/AsetPemakaiController.php" "$ctrl/Inventaris/AsetPemakaiController.php"
git mv "$ctrl/AsetPenangananController.php" "$ctrl/Inventaris/AsetPenangananController.php"
git mv "$ctrl/AsetPenggantianSparepartController.php" "$ctrl/Inventaris/AsetPenggantianSparepartController.php"
git mv "$ctrl/JenisAsetController.php" "$ctrl/Inventaris/JenisAsetController.php"
git mv "$ctrl/KelengkapanMasterController.php" "$ctrl/Inventaris/KelengkapanMasterController.php"
git mv "$ctrl/SupplierController.php" "$ctrl/Inventaris/SupplierController.php"

git mv "$ctrl/UserController.php" "$ctrl/Karyawan/UserController.php"
git mv "$ctrl/AdminUserController.php" "$ctrl/Karyawan/AdminUserController.php"

git mv "$ctrl/AbsensiController.php" "$ctrl/Absensi/AbsensiController.php"

git mv "$ctrl/CabangController.php" "$ctrl/Organisasi/CabangController.php"
git mv "$ctrl/DepartemenController.php" "$ctrl/Organisasi/DepartemenController.php"
git mv "$ctrl/JabatanController.php" "$ctrl/Organisasi/JabatanController.php"

git mv "$ctrl/IzinController.php" "$ctrl/Izin/IzinController.php"

git mv "$ctrl/DashboardController.php" "$ctrl/Dashboard/DashboardController.php"
git mv "$ctrl/LaporanController.php" "$ctrl/Dashboard/LaporanController.php"

git mv "$ctrl/TicketController.php" "$ctrl/Ticketing/TicketController.php"

git mv "$ctrl/AuthController.php" "$ctrl/Auth/AuthController.php"

Write-Host "== Fix namespace + tambah 'use Controller' ==" -ForegroundColor Cyan
"Inventaris","Karyawan","Absensi","Organisasi","Izin","Dashboard","Ticketing","Auth" | ForEach-Object {
    $dir = $_
    Get-ChildItem -Path "$ctrl/$dir" -Filter *.php | ForEach-Object {
        $content = Get-Content $_.FullName -Raw
        $content = $content -replace [regex]::Escape("namespace App\Http\Controllers;"), "namespace App\Http\Controllers\$dir;`r`n`r`nuse App\Http\Controllers\Controller;"
        Set-Content -Path $_.FullName -Value $content -NoNewline
    }
}

Write-Host "== Fix use-statement di routes/api.php ==" -ForegroundColor Cyan
$routeFile = "routes/api.php"
$content = Get-Content $routeFile -Raw

$replacements = @{
    'use App\Http\Controllers\AuthController;' = 'use App\Http\Controllers\Auth\AuthController;'
    'use App\Http\Controllers\UserController;' = 'use App\Http\Controllers\Karyawan\UserController;'
    'use App\Http\Controllers\AbsensiController;' = 'use App\Http\Controllers\Absensi\AbsensiController;'
    'use App\Http\Controllers\DepartemenController;' = 'use App\Http\Controllers\Organisasi\DepartemenController;'
    'use App\Http\Controllers\JabatanController;' = 'use App\Http\Controllers\Organisasi\JabatanController;'
    'use App\Http\Controllers\TicketController;' = 'use App\Http\Controllers\Ticketing\TicketController;'
    'use App\Http\Controllers\IzinController;' = 'use App\Http\Controllers\Izin\IzinController;'
    'use App\Http\Controllers\DashboardController;' = 'use App\Http\Controllers\Dashboard\DashboardController;'
    'use App\Http\Controllers\AdminUserController;' = 'use App\Http\Controllers\Karyawan\AdminUserController;'
    'use App\Http\Controllers\LaporanController;' = 'use App\Http\Controllers\Dashboard\LaporanController;'
    'use App\Http\Controllers\SupplierController;' = 'use App\Http\Controllers\Inventaris\SupplierController;'
    'use App\Http\Controllers\JenisAsetController;' = 'use App\Http\Controllers\Inventaris\JenisAsetController;'
    'use App\Http\Controllers\KelengkapanMasterController;' = 'use App\Http\Controllers\Inventaris\KelengkapanMasterController;'
    'use App\Http\Controllers\AsetController;' = 'use App\Http\Controllers\Inventaris\AsetController;'
    'use App\Http\Controllers\AsetPemakaiController;' = 'use App\Http\Controllers\Inventaris\AsetPemakaiController;'
    'use App\Http\Controllers\AsetPenggantianSparepartController;' = 'use App\Http\Controllers\Inventaris\AsetPenggantianSparepartController;'
    'use App\Http\Controllers\AsetPenangananController;' = 'use App\Http\Controllers\Inventaris\AsetPenangananController;'
    'use App\Http\Controllers\CabangController;' = 'use App\Http\Controllers\Organisasi\CabangController;'
}

foreach ($key in $replacements.Keys) {
    $content = $content -replace [regex]::Escape($key), $replacements[$key]
}

# hapus import nyasar (baris utuh)
$content = ($content -split "`r?`n" | Where-Object { $_ -notmatch [regex]::Escape('use SebastianBergmann\CodeCoverage\Report\Html\Dashboard;') }) -join "`r`n"

Set-Content -Path $routeFile -Value $content -NoNewline

Write-Host ""
Write-Host "== Selesai. Verifikasi wajib sebelum commit: ==" -ForegroundColor Yellow
Write-Host "   composer dump-autoload"
Write-Host "   php artisan route:list"
Write-Host "   (pastikan semua route ke-resolve, ga ada 'Target class does not exist')"
