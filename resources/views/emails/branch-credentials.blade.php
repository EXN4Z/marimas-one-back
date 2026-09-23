<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: Arial, sans-serif; color: #333;">
    <h2>Kredensial Akun Cabang</h2>

    <p>Halo,</p>

    <p>Berikut adalah kredensial akun untuk cabang <strong>{{ $user->name }}</strong>:</p>

    <table>
        <tr>
            <td><strong>Email/Username:</strong></td>
            <td>{{ $user->email }}</td>
        </tr>
        <tr>
            <td><strong>Password:</strong></td>
            <td>{{ $password }}</td>
        </tr>
    </table>

    <p>Untuk keamanan, mohon segera login dan ganti password Anda melalui halaman berikut:</p>

    <p><a href="{{ config('app.frontend_url') }}/login">Login ke Sistem</a></p>

    <p>Terima kasih.</p>
</body>
</html>