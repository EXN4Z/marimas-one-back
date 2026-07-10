<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request)
{
    $query = User::query();

    if ($request->filled('role') && $request->role !== 'all') {
        $query->where('role', $request->role);
    }

    if ($request->filled('status') && $request->status !== 'all') {
        $query->where('status', $request->status);
    }

    if ($request->filled('search')) {
        $query->where(function ($q) use ($request) {
            $q->where('name', 'like', '%' . $request->search . '%')
              ->orWhere('email', 'like', '%' . $request->search . '%');
        });
    }

    return response()->json($query->latest()->get());
}
    public function edit(User $user)
    {
        return response()->json($user);
    }
    public function update(Request $request, User $user)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'phone' => 'required|string|unique:users,phone,' . $user->id,
            'role' => 'required|string|in:guest,karyawan,manajer,hr,admin',
        ]);

        $user->update($request->all());
        return response()->json($user);
    }
    public function destroy(User $user)
    {
        $user->delete();
        return response()->json(['message' => 'User deleted successfully']);
    }
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'phone' => 'required|string|unique:users',
            'role' => 'required|string|in:guest,karyawan,manajer,hr,admin',
        ]);

        $user = User::create($request->all());
        return response()->json($user, 201);
    }
}


