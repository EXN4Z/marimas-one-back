<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'role',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];
    
    protected static array $roleLevels = [
        'guest' => 0,
        'karyawan' => 1,
        'manajer' => 2,
        'hr' => 3,
        'admin' => 4,
    ];
    public function hasRoleAtLeast(string $role): bool
    {
        $userLevel = self::$roleLevels[$this->role] ?? 0;
        $requiredLevel = self::$roleLevels[$role] ?? 0;

        return $userLevel >= $requiredLevel;
    }
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function pekerja()
    {
        return $this->hasOne(Pekerja::class, 'user_id');
    }
    public function cuti()
    {
        return $this->hasMany(PengajuanCuti::class, 'karyawan_id');
    }

    public function izin()
    {
        return $this->hasMany(PengajuanIzin::class, 'karyawan_id');
    }
}