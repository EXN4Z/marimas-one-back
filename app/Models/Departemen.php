<?php
// app/Models/Departemen.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Departemen extends Model
{
    protected $table = 'departemen';
    protected $fillable = ['nama'];

    public function pekerja()
    {
        return $this->hasMany(Pekerja::class, 'departemen_id');
    }
}