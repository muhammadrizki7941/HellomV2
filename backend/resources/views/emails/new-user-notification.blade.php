<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pendaftar Baru</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background-color: #f8f9fa; padding: 20px; border-radius: 8px;">
        <h2 style="color: #28a745; margin-bottom: 20px;">🎉 Pendaftar Baru!</h2>

        <p>Hai Owner,</p>

        <p>Ada user baru yang mendaftar ke platform Hellom:</p>

        <div style="background-color: white; padding: 15px; border-radius: 5px; border-left: 4px solid #28a745; margin: 20px 0;">
            <p><strong>Nama:</strong> {{ $user->name }}</p>
            <p><strong>Email:</strong> {{ $user->email }}</p>
            <p><strong>Produk:</strong> {{ $product }}</p>
            <p><strong>Waktu Daftar:</strong> {{ $user->created_at->format('d M Y H:i') }}</p>
        </div>

        <p>Silakan hubungi user jika diperlukan untuk onboarding atau follow-up.</p>

        <p>Salam,<br>Hellom Platform</p>
    </div>
</body>
</html>