<?php

namespace App\Http\Controllers\MasterData;

use Illuminate\Http\Request;
use App\Models\MasterData\Role;
use App\Http\Controllers\Controller;

class RoleController extends Controller
{
    public function index() {
        return response()->json(
            Role::where('nama', '!=', 'cabang')->orderBy('id')->get(['id', 'nama'])
        );
    }
}
