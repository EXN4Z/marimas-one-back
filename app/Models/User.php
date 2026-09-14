<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use NotificationChannels\WebPush\HasPushSubscriptions;
use App\Models\MasterData\Departemen;
use Illuminate\Support\Facades\Cache;
use App\Models\MasterData\Role;

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
        'role_id',
        // WAJIB ada juga di sini (bukan cuma 'role_id') -- mass assignment
        // (create()/updateOrCreate()/fill()) MEMBUANG key apa pun yang gak
        // ada di $fillable SEBELUM mutator setRoleAttribute() sempat jalan.
        // Tanpa baris ini, User::create(['role' => 'admin']) diam-diam
        // gagal set role_id (jadi null -> NOT NULL constraint violation) --
        // dipakai di KaryawanImport, InventoryBuktiImport, AuthController,
        // DummySeeder, dan UserFactory.
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

    // WAJIB: tanpa ini, field "role" hilang total dari hasil toArray()/
    // toJson() (termasuk response API) karena 'role' bukan lagi kolom asli
    // di database -- sekarang murni accessor yang baca dari relasi roleRef.
    protected $appends = ['role'];

    // Relasi FK asli ke tabel roles (dulu users.role cuma string yang
    // dicocokkan manual ke roles.nama, sekarang users.role_id beneran FK
    // dengan constraint di database -- lihat migration
    // 2026_09_13_000000_convert_users_role_to_role_id).
    public function roleRef()
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    // Accessor: $user->role tetap balikin string ('admin', 'karyawan', dst)
    // persis kayak sebelumnya -- semua kode lama yang baca $user->role
    // gak perlu diubah sama sekali.
    public function getRoleAttribute(): ?string
    {
        return $this->roleRef?->nama;
    }

    // Mutator: User::create(['role' => 'admin', ...]) atau $user->role =
    // 'admin' tetap jalan -- otomatis di-resolve ke role_id yang cocok.
    // Lempar exception jelas kalau nama role gak ketemu, daripada diam-diam
    // nyimpen NULL/salah.
    public function setRoleAttribute(string $value): void
    {
        $role = Role::where('nama', $value)->first();

        if (!$role) {
            throw new \InvalidArgumentException("Role '{$value}' tidak ditemukan di tabel roles.");
        }

        $this->attributes['role_id'] = $role->id;
    }
    
    // Level tiap role sekarang diambil dari tabel `roles` (Master Data >
    // Role), BUKAN array hardcode lagi -- jadi role baru yang dibikin admin
    // lewat UI langsung punya level yang beneran berlaku buat middleware
    // 'role:...' tanpa perlu redeploy kode. Di-cache forever (key
    // 'role_levels_map') karena datanya jarang berubah dan dibaca di
    // hampir tiap request; cache-nya di-invalidate manual lewat
    // clearRoleLevelCache() tiap kali ada create/update/delete/import role
    // (lihat RoleController & RoleImport).
    //
    // Kalau nama role gak ketemu di tabel roles (misal data lama/rusak),
    // fallback ke level 0 (paling rendah) -- fail-safe ke arah lebih
    // ketat, bukan lebih longgar.
    public function hasRoleAtLeast(string $role): bool
    {
        return self::roleLevel($this->role) >= self::roleLevel($role);
    }

    public static function roleLevel(string $role): int
    {
        return self::roleLevelsMap()[$role] ?? 0;
    }

    protected static function roleLevelsMap(): array
    {
        return Cache::rememberForever('role_levels_map', function () {
            return Role::pluck('level', 'nama')->all();
        });
    }

    public static function clearRoleLevelCache(): void
    {
        Cache::forget('role_levels_map');
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