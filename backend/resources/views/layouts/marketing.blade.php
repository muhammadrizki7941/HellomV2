<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>{{ $title ?? 'SaaS Kasir' }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brandPink: '#ff6bcb'
                    }
                }
            }
        }
    </script>
</head>
<body class="antialiased text-gray-900 bg-white">
    <header class="bg-gradient-to-br from-pink-50 via-yellow-50 to-yellow-100">
        <div class="container mx-auto px-6 lg:px-20 py-6 flex items-center justify-between">
            <div class="flex items-center gap-6">
                <div class="text-2xl font-extrabold tracking-tight">SaaS Kasir</div>
                <nav class="hidden md:flex items-center gap-4 text-sm text-gray-700">
                    <a href="/" class="hover:underline">Home</a>
                    <a href="/features" class="hover:underline">Features</a>
                    <a href="/pricing" class="hover:underline">Pricing</a>
                    <a href="/contact" class="hover:underline">Contact</a>
                </nav>
            </div>

            <div class="flex items-center gap-3">
                <a href="#" class="hidden sm:inline-block px-4 py-2 rounded-md bg-white text-gray-800 border">Is this for me?</a>
                <a href="/login" class="inline-block px-4 py-2 rounded-md bg-pink-500 hover:bg-pink-600 text-white font-semibold">Login</a>
            </div>
        </div>
    </header>

    <main>
        {{ $slot }}
    </main>

    <footer class="bg-white">
        <div class="container mx-auto px-6 lg:px-20 py-8 text-center text-sm text-gray-500">Foundation mode: no DB / no migrations.</div>
    </footer>
</body>
</html>
