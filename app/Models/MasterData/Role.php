<?php

namespace App\Models\MasterData;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;

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
    // Sejak users.role_id jadi FK asli (lihat migration
    // 2026_09_13_000000_convert_users_role_to_role_id), relasi ini join
    // by id, bukan lagi nyocokin string role/nama manual.
    public function users()
    {
        return $this->hasMany(User::class, 'role_id', 'id');
    }
}