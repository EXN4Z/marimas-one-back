<?php

namespace App\Models\MasterData;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;

// Data referensi role (Master Data > Role) -- `nama` dipakai buat
// mencocokkan users.role_id (FK asli), dan buat cek akses lewat
// User::isAdmin() (nama === 'admin'). REVISI: `label` & `level` dihapus
// -- hak akses sekarang cuma 2 tingkat (admin vs role lain yang semuanya
// setara), jadi level gak berarti apa-apa lagi, dan label gak pernah
// dipakai di UI (Master Data Role cuma nampilin `nama`).
// Lihat catatan lengkap di app/Models/User.php.
class Role extends Model
{
    protected $table = 'roles';

    protected $fillable = [
        'nama',
    ];

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