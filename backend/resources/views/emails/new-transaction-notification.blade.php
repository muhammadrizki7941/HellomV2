<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaksi Masuk</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background-color: #f8f9fa; padding: 20px; border-radius: 8px;">
        <h2 style="color: {{ $status === 'paid' ? '#28a745' : '#ffc107' }}; margin-bottom: 20px;">💳 Transaksi Masuk</h2>

        <p>Hai Owner,</p>

        <p>Ada transaksi baru di platform Hellom:</p>

        <div style="background-color: white; padding: 15px; border-radius: 5px; border-left: 4px solid {{ $status === 'paid' ? '#28a745' : '#ffc107' }}; margin: 20px 0;">
            <p><strong>User:</strong> {{ $transaction->user->name ?? 'Unknown' }}</p>
            <p><strong>Deskripsi:</strong> {{ $transaction->description }}</p>
            <p><strong>Nominal:</strong> Rp {{ number_format($transaction->amount) }}</p>
            <p><strong>Status:</strong> <span style="color: {{ $status === 'paid' ? '#28a745' : '#ffc107' }}; font-weight: bold;">{{ ucfirst($status) }}</span></p>
            <p><strong>Waktu:</strong> {{ $transaction->created_at->format('d M Y H:i') }}</p>
        </div>

        <p>{{ $status === 'paid' ? 'Transaksi telah berhasil dibayar.' : 'Mohon konfirmasi pembayaran ini.' }}</p>

        <p>Salam,<br>Hellom Platform</p>
    </div>
</body>
</html>