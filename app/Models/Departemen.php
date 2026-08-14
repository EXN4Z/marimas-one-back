<?php
// app/Models/Departemen.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Departemen extends Model
{
    protected $table = 'departemen';
    protected $fillable = ['nama'];

    // BARU (eks-pekerja): karyawan sekarang langsung di tabel users.
    public function karyawan()
    {
        return $this->hasMany(User::class, 'departemen_id');
    }

    public function aset()
    {
        return $this->hasMany(Aset::class, 'departemen_id');
    }
}