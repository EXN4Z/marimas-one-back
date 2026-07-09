<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ChatbotController extends Controller
{
    public function ask(Request $request)
    {
        $request->validate([
            'message' => 'required|string',
        ]);

        $user = $request->user();

        // Ambil data relevan si user (masih placeholder, nanti diisi query beneran
        // setelah tabel cuti/izin/ticket dibuat)
        $context = $this->buildContext($user);

        $prompt = <<<PROMPT
        Kamu adalah AI Assistant untuk sistem MARIMAS ONE, sebuah ERP internal perusahaan.
        Jawab pertanyaan user HANYA berdasarkan data berikut. Kalau datanya tidak cukup untuk menjawab, katakan dengan jujur bahwa kamu tidak punya informasi itu.
        Jawab dengan singkat, jelas, dan sopan dalam Bahasa Indonesia.

        DATA USER:
        Nama: {$user->name}
        Role: {$user->role}
        {$context}

        PERTANYAAN USER:
        {$request->message}
        PROMPT;

        $response = Http::post(
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . env('GEMINI_API_KEY'),
            [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt],
                        ],
                    ],
                ],
            ]
        );

        if ($response->failed()) {
            return response()->json(['message' => 'Gagal menghubungi AI Assistant'], 500);
        }

        $data = $response->json();
        $reply = $data['candidates'][0]['content']['parts'][0]['text'] ?? 'Maaf, saya tidak bisa menjawab saat ini.';

        return response()->json(['reply' => trim($reply)]);
    }

    private function buildContext($user): string
    {
        // Versi awal: masih placeholder, karena tabel cuti/izin/ticket
        // belum dibuat. Nanti diisi query beneran setelah modul itu jadi, contoh:
        //
        // $cutiTerbaru = Cuti::where('karyawan_id', $user->karyawan->id ?? null)
        //     ->latest()->first();
        // return "Cuti terakhir: " . ($cutiTerbaru ? $cutiTerbaru->status : "belum ada pengajuan");

        return "Belum ada data cuti/izin/ticket yang terhubung (modul belum dibuat).";
    }
}