<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Data referensi role (Master Data > Role) -- `nama` dipakai buat
// mencocokkan users.role (plain string, bukan foreign key), `level`
// dipakai User::hasRoleAtLeast()/roleLevel() buat hak akses lintas role.
// Lihat catatan lengkap di app/Models/User.php.
class Role extends Model
{
    protected $table = 'roles';

    protected $fillable = [
        'nama',
        'label',
        'level',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
        ];
    }

    // Jumlah user yang lagi pakai role ini -- dipakai buat cegah hapus/
    // ganti nama role yang masih terpakai (lihat RoleController::destroy()).
    public function users()
    {
        return $this->hasMany(User::class, 'role', 'nama');
    }
}