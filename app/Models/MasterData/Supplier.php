<?php

namespace App\Models\MasterData;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $table = 'supplier';

    protected $fillable = ['nama', 'alamat', 'telepon'];

    public function inventory()
    {
        return $this->hasMany(Inventory::class, 'supplier_id');
    }
}