<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AuditLog extends Model
{
    use SoftDeletes;

    protected $table = 'audit_logs';

    protected $fillable = [
        'user_id',
        'method',
        'endpoint',
        'deskripsi',
        'ip_address',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}