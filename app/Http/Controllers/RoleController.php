<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\MasterData\Role;

class RoleController extends Controller
{
    public function index() {
        return response()->json(
            Role::orderBy('id')->get(['id', 'nama'])
        );
    }
}
