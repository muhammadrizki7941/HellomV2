<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>{{ $title ?? 'Cashier' }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body {
            font-family: 'Inter', ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            min-height: 100vh;
            margin: 0;
        }
        .cashier-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 1rem;
        }
        .cashier-header {
            background: white;
            border-radius: 1rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .cashier-nav {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .cashier-menu {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-left: 2rem;
        }
        .cashier-menu a {
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font-weight: 500;
            text-decoration: none;
            transition: background-color 0.2s, color 0.2s;
            color: #6b7280;
        }
        .cashier-menu a:hover {
            background: #f3f4f6;
            color: #1f2937;
        }
        .cashier-menu a.active {
            background: #dbeafe;
            color: #1d4ed8;
        }
        .cashier-logo {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1f2937;
        }
        .cashier-user {
            font-size: 0.875rem;
            color: #6b7280;
        }
        .btn-logout {
            background: #dc2626;
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .btn-logout:hover {
            background: #b91c1c;
        }
        .cashier-content {
            background: white;
            border-radius: 1rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            padding: 2rem;
            margin-bottom: 1.5rem;
        }
        .cashier-footer {
            text-align: center;
            color: #6b7280;
            font-size: 0.875rem;
            margin-top: 2rem;
        }
    </style>
</head>
<body>
    <div class="cashier-container">
        <header class="cashier-header">
            <div class="cashier-nav">
                <div class="cashier-logo">Cashier System</div>
                <div class="cashier-user">
                    Tenant: {{ $tenant->name ?? 'N/A' }} | User: {{ $user['email'] ?? 'N/A' }}
                </div>
                <nav class="cashier-menu">
                    <a href="{{ $bp }}/cashier" class="{{ request()->routeIs('cashier.home') ? 'active' : '' }}">Dashboard</a>
                    <a href="{{ $bp }}/cashier/orders" class="{{ request()->routeIs('cashier.orders.*') ? 'active' : '' }}">Orders</a>
                    <a href="{{ $bp }}/admin/categories" class="{{ request()->routeIs('admin.categories.*') ? 'active' : '' }}">Kategori</a>
                    <a href="{{ $bp }}/admin/products" class="{{ request()->routeIs('admin.products.*') ? 'active' : '' }}">Produk</a>
                </nav>
            </div>
            <form method="POST" action="{{ $cashierLogoutUrl ?? '#' }}">
                @csrf
                <button type="submit" class="btn-logout">Logout</button>
            </form>
        </header>

        <main class="cashier-content">
            {{ $slot }}
        </main>

        <footer class="cashier-footer">
            <p>&copy; 2026 Self Order Menu - Professional Cashier Interface</p>
        </footer>
    </div>
</body>
</html>
