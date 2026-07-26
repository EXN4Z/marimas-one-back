<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use NotificationChannels\WebPush\PushSubscription;

class PushSubscriptionController extends Controller
{
    // simpan/update subscription push browser milik user yang login
    public function store(Request $request)
    {
        $validated = $request->validate([
            'endpoint' => 'required|string',
            'keys.p256dh' => 'required|string',
            'keys.auth' => 'required|string',
        ]);

        // Endpoint = device/browser, bukan sesi login. Kalau device ini
        // sebelumnya subscribe pake akun lain (device sharing, lupa
        // unsubscribe), kolom endpoint unique global bikin insert user
        // sekarang gagal (500). Reassign ke user sekarang dulu.
        PushSubscription::where('endpoint', $validated['endpoint'])
            ->where(function ($q) use ($request) {
                $q->where('subscribable_type', '!=', get_class($request->user()))
                  ->orWhere('subscribable_id', '!=', $request->user()->id);
            })
            ->delete();

        $request->user()->updatePushSubscription(
            $validated['endpoint'],
            $validated['keys']['p256dh'],
            $validated['keys']['auth']
        );

        return response()->json(['message' => 'Subscription tersimpan.']);
    }

    // browser matiin izin notif / user logout dari device ini -> hapus subscription-nya aja
    public function destroy(Request $request)
    {
        $validated = $request->validate([
            'endpoint' => 'required|string',
        ]);

        $request->user()->deletePushSubscription($validated['endpoint']);

        return response()->json(['message' => 'Subscription dihapus.']);
    }
}