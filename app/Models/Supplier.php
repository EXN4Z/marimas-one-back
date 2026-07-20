<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $table = 'supplier';

    protected $fillable = ['nama', 'alamat', 'telepon'];

    public function aset()
    {
        return $this->hasMany(Aset::class, 'supplier_id');
    }
}