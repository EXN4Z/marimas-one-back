<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

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