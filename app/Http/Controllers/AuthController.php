<?php

namespace App\Http\Controllers;

use App\Mail\OtpMail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Mail\NewPasswordMail;
use Illuminate\Support\Facades\Log;

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

        Cache::put("register:{$registrationId}", [
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'password' => Hash::make($request->password),
            'otp_code' => $otp,
        ], now()->addMinutes(5));

        $this->sendOtpEmail($request->email, $otp);

        return response()->json([
            'message' => 'Kode OTP sudah dikirim ke email kamu',
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

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'password' => $data['password'],
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

        $this->sendOtpEmail($data['email'], $otp);

        return response()->json(['message' => 'Kode OTP baru sudah dikirim']);
    }

    private function sendOtpEmail(string $email, int $otp): void
    {
        Mail::to($email)->send(new OtpMail($otp));
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

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|unique:users,email,' . $user->id,
            'phone' => 'nullable|string|unique:users,phone,' . $user->id,
        ]);

        $user->update($validated);

        return response()->json($user->fresh());
    }

    public function changePassword(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|min:6|confirmed',
        ]);

        if (!Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Password saat ini salah.'],
            ]);
        }

        $user->update(['password' => Hash::make($validated['password'])]);

        return response()->json(['message' => 'Password berhasil diubah.']);
    }

    public function logout(Request $request)
    {
        $user = $request->user();

        if ($user->role !== 'admin' && $user->email) {
            $newPassword = Str::random(12);

            try {
                Mail::to($user->email)->send(new NewPasswordMail($newPassword));
                $user->update(['password' => Hash::make($newPassword)]);
            } catch (\Exception $e) {
                Log::error('Gagal kirim email password baru, password TIDAK diubah: ' . $e->getMessage());
            }
        }

        $user->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out']);
    }
}
