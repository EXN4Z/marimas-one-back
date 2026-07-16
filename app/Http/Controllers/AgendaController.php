<?php

namespace App\Http\Controllers;

use App\Models\Agenda;
use Illuminate\Http\Request;

class AgendaController extends Controller
{
    // GET /api/agenda — dipakai widget "Agenda Mendatang" di Dashboard.
    // Cuma nampilin agenda yang belum lewat, diurutkan dari yang paling deket.
    public function index(Request $request)
    {
        $limit = (int) $request->query('limit', 5);

        $agenda = Agenda::where('start_at', '>=', now()->startOfDay())
            ->orderBy('start_at')
            ->limit($limit)
            ->get(['id', 'title', 'description', 'start_at']);

        return response()->json($agenda);
    }

    // POST /api/agenda — dibatasi lewat route (role:admin,hr).
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:150',
            'description' => 'nullable|string|max:1000',
            'start_at' => 'required|date',
        ]);

        $agenda = Agenda::create([
            ...$validated,
            'created_by' => $request->user()->id,
        ]);

        return response()->json($agenda, 201);
    }

    // DELETE /api/agenda/{agenda} — dibatasi lewat route (role:admin,hr).
    public function destroy(Agenda $agenda)
    {
        $agenda->delete();

        return response()->json(['message' => 'Agenda dihapus.']);
    }
}
