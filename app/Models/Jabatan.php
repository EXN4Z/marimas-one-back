<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Jabatan extends Model
{
    protected $table = 'jabatan';

    protected $fillable = ['nama', 'gaji_pokok'];

    public function pekerja()
    {
        return $this->hasMany(Pekerja::class, 'jabatan_id');
    }
}