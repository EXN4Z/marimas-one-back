<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => 'required|string|unique:users,phone',
            'password' => 'required|min:6|confirmed',
        ]);

        $otp = rand(100000, 999999);
        $registrationId = Str::uuid()->toString();

        // simpan sementara di cache, bukan ke database, TTL 5 menit
        Cache::put("register:{$registrationId}", [
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => Hash::make($request->password),
            'otp_code' => $otp,
        ], now()->addMinutes(5));

        $this->sendOtpWhatsapp($request->phone, $otp);

        return response()->json([
            'message' => 'Kode OTP sudah dikirim ke WhatsApp kamu',
            'registration_id' => $registrationId,
        ], 200);
    }

    public function verifyOtp(Request $request)
    {
        $request->validate([
            'registration_id' => 'required|string',
            'otp_code' => 'required|string',
        ]);

        $data = Cache::get("register:{$request->registration_id}");

        if (!$data) {
            throw ValidationException::withMessages([
                'otp_code' => ['Sesi registrasi kedaluwarsa, silakan daftar ulang.'],
            ]);
        }

        if ((string) $data['otp_code'] !== (string) $request->otp_code) {
            throw ValidationException::withMessages([
                'otp_code' => ['Kode OTP salah.'],
            ]);
        }

        // OTP benar -> baru bikin akun beneran di database
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'password' => $data['password'], // udah di-hash sebelumnya
            'role' => 'karyawan',
            'phone_verified_at' => now(),
        ]);

        Cache::forget("register:{$request->registration_id}");

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ]);
    }

    public function resendOtp(Request $request)
    {
        $request->validate([
            'registration_id' => 'required|string',
        ]);

        $data = Cache::get("register:{$request->registration_id}");

        if (!$data) {
            throw ValidationException::withMessages([
                'registration_id' => ['Sesi registrasi kedaluwarsa, silakan daftar ulang.'],
            ]);
        }

        $otp = rand(100000, 999999);
        $data['otp_code'] = $otp;

        Cache::put("register:{$request->registration_id}", $data, now()->addMinutes(5));

        $this->sendOtpWhatsapp($data['phone'], $otp);

        return response()->json(['message' => 'Kode OTP baru sudah dikirim']);
    }

    private function sendOtpWhatsapp(string $phone, int $otp): void
    {
        Http::withHeaders([
            'Authorization' => env('FONNTE_TOKEN'),
        ])->post('https://api.fonnte.com/send', [
            'target' => $phone,
            'message' => "Kode verifikasi MARIMAS ONE kamu: {$otp}\n\nBerlaku 5 menit. Jangan berikan kode ini ke siapa pun.",
            'countryCode' => '62',
        ]);
    }

    public function login(Request $request)
    {
        $request->validate([
            'login' => 'required|string',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->login)
            ->orWhere('phone', $request->login)
            ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'login' => ['Email/No HP atau password salah.'],
            ]);
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ]);
    }

    public function user(Request $request)
    {
        return response()->json($request->user());
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out']);
    }
}
