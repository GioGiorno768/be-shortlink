<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Verifikasi Metode Pembayaran</title>
</head>

<body>
    <h2>Halo, {{ $method->user->name }}</h2>

    <p>Kamu baru saja menambahkan metode pembayaran berikut:</p>

    <ul>
        <li><strong>Tipe:</strong> {{ ucfirst($method->type) }}</li>
        <li><strong>Nomor Akun:</strong> {{ $method->account_number }}</li>
        @if($method->bank_name)
            <li><strong>Bank:</strong> {{ $method->bank_name }}</li>
        @endif
    </ul>

    <p>Untuk mengaktifkan metode pembayaran ini, klik tombol di bawah:</p>

    <h2>Confirm Your Payment Method</h2>
    <p>Click the button below to verify your payment method:</p>
    <a href="{{ $url }}"
        style="background:#4CAF50;color:white;padding:10px 20px;border-radius:5px;text-decoration:none;">
        Verify Payment Method
    </a>
    <p>This link will expire in 30 minutes.</p>


    <p>Jika kamu tidak merasa menambahkan metode pembayaran ini, abaikan email ini.</p>
</body>

</html>