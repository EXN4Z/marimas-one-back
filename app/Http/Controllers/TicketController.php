<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use App\Notifications\TicketBaruMasuk;

class TicketController extends Controller
{
    // GET /api/ticketing — laporan yang masih pending/diproses.
    // Karyawan cuma lihat laporan miliknya sendiri, staff (manajer/hr/admin) lihat semua.
    public function index(Request $request)
    {
        $tickets = $this->scopedQuery($request->user())
            ->whereIn('status', Ticket::STATUS_AKTIF)
            ->with(['pelapor:id,name,role', 'penanggungJawab:id,name'])
            ->latest()
            ->get();

        return response()->json($tickets);
    }

    // GET /api/ticketing/history — laporan yang sudah selesai/ditolak.
    public function history(Request $request)
    {
        $tickets = $this->scopedQuery($request->user())
            ->whereIn('status', Ticket::STATUS_HISTORY)
            ->with(['pelapor:id,name,role', 'penanggungJawab:id,name'])
            ->latest('selesai_at')
            ->get();

        return response()->json($tickets);
    }

    // GET /api/ticketing/{ticket} — detail satu laporan.
    public function show(Request $request, Ticket $ticket)
    {
        $this->authorizeAccess($request->user(), $ticket);

        return response()->json(
            $ticket->load(['pelapor:id,name,role', 'penanggungJawab:id,name'])
        );
    }

    // POST /api/ticketing — bikin laporan baru (tombol "Buat Laporan").
    public function store(Request $request)
    {
        $validated = $request->validate([
            'judul' => 'required|string|max:150',
            'deskripsi' => 'required|string|max:2000',
            'kategori' => 'nullable|string|max:50',
        ]);

        $ticket = Ticket::create([
            ...$validated,
            'user_id' => $request->user()->id,
            'status' => Ticket::STATUS_PENDING,
        ]);

        // TAMBAH: notif ke manajer/hr/admin tiap ada laporan baru masuk
        // PENTING: try-catch supaya laporan yang SUDAH tersimpan di atas tidak
        // ikut gagal kalau pengiriman notifikasi bermasalah.
        try {
            Notification::send(
                User::whereIn('role', ['manajer', 'hr', 'admin'])->get(),
                new TicketBaruMasuk($ticket)
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Gagal mengirim notifikasi tiket baru', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return response()->json(
            $ticket->load('pelapor:id,name,role'),
            201
        );
    }

    // PUT /api/ticketing/{ticket}/status — ubah status laporan.
    // Dibatasi lewat route (role:manajer,hr,admin) supaya karyawan biasa
    // tidak bisa menutup/mengubah status laporannya sendiri.
    public function updateStatus(Request $request, Ticket $ticket)
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,diproses,selesai,ditolak',
            'catatan_admin' => 'nullable|string|max:2000',
        ]);

        $ticket->update([
            'status' => $validated['status'],
            'catatan_admin' => $validated['catatan_admin'] ?? $ticket->catatan_admin,
            'ditangani_oleh' => $request->user()->id,
            'selesai_at' => in_array($validated['status'], Ticket::STATUS_HISTORY, true)
                ? now()
                : null,
        ]);

        return response()->json(
            $ticket->load(['pelapor:id,name,role', 'penanggungJawab:id,name'])
        );
    }

    private function scopedQuery(User $user)
    {
        if ($user->hasRoleAtLeast('manajer')) {
            return Ticket::query();
        }

        return Ticket::where('user_id', $user->id);
    }

    private function authorizeAccess(User $user, Ticket $ticket): void
    {
        $isOwner = $ticket->user_id === $user->id;
        $isStaff = $user->hasRoleAtLeast('manajer');

        abort_unless($isOwner || $isStaff, 403, 'Kamu tidak punya akses ke laporan ini.');
    }
}