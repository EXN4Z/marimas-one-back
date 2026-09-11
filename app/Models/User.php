<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use NotificationChannels\WebPush\HasPushSubscriptions;
use App\Models\MasterData\Departemen;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, HasPushSubscriptions;

    // BARU: password default/reset dibuat dari nama depan user saja,
    // persis apa adanya (huruf besar/kecil TIDAK diubah). Contoh:
    // "Febriyan Arbi" -> "Febriyan", "FEBRIYAN ARBI" -> "FEBRIYAN".
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'role',
        // BARU: cuma dipakai buat akun role 'cabang', nunjuk ke lokasi_kantor
        // mana yang dia urus. Null buat role lain.
        'lokasi_kantor_id',
        // BARU (eks-pekerja): data karyawan sekarang nempel langsung di sini,
        // tidak ada lagi tabel/model Pekerja terpisah.
        'nik',
        'departemen_id',
        'tanggal_masuk',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];
    
    // BARU: karyawan, cabang, manajer, dan hr disetarakan levelnya (1) --
    // cuma admin yang beda/lebih tinggi. Role-role non-admin ini tetap
    // punya nama/label sendiri-sendiri (dipakai buat tampilan & filter di
    // frontend), tapi dari sisi hak akses API semuanya setara persis
    // seperti karyawan biasa. Middleware 'role:...' yang nyebut kombinasi
    // apa pun selain 'admin' murni (misal 'role:admin,hr') otomatis kebuka
    // buat semua role non-admin juga, karena level terendah di antara
    // role yang disebut sekarang selalu 1.
    protected static array $roleLevels = [
        'guest' => 0,
        'karyawan' => 1,
        'cabang' => 1,
        'manajer' => 1,
        'hr' => 1,
        'admin' => 5,
    ];
    public function hasRoleAtLeast(string $role): bool
    {
        $userLevel = self::$roleLevels[$this->role] ?? 0;
        $requiredLevel = self::roleLevel($role);

        return $userLevel >= $requiredLevel;
    }

    public static function roleLevel(string $role): int
    {
        return self::$roleLevels[$role] ?? 0;
    }

    // BARU: generate password default dari nama depan (kata pertama di
    // 'name'), persis apa adanya, huruf besar/kecil tidak diubah.
    // Contoh: "Febriyan Arbi" -> "Febriyan", "FEBRIYAN ARBI" -> "FEBRIYAN".
    public static function generatePasswordFromName(string $name): string
    {
        $firstName = trim(explode(' ', trim($name))[0] ?? '');

        return $firstName !== '' ? $firstName : Str::random(8);
    }
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'password' => 'hashed',
            'tanggal_masuk' => 'date:Y-m-d',
        ];
    }

    // BARU: lokasi kantor yang diurus akun ini — cuma relevan buat role 'cabang'.
    public function lokasiKantor()
    {
        return $this->belongsTo(LokasiKantor::class, 'lokasi_kantor_id');
    }

    // BARU (eks-pekerja): departemen karyawan ini, langsung dari users.departemen_id.
    public function departemen()
    {
        return $this->belongsTo(Departemen::class, 'departemen_id');
    }
}