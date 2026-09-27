<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    @php
        $brand = \App\Models\BrandSetting::global();
    @endphp
    <title>{{ $title ?? ($brand?->business_name ?? 'Gateway') }}</title>
    @if($brand?->faviconUrl())
        <link rel="icon" href="{{ $brand->faviconUrl() }}" />
    @endif
    <style>
        :root {
            --primary-color: {{ $brand?->primary_color ?? '#0f172a' }};
            --secondary-color: {{ $brand?->secondary_color ?? '#334155' }};
            --accent-color: {{ $brand?->accent_color ?? '#10b981' }};
            --background-color: {{ $brand?->background_color ?? '#f8fafc' }};
            --button-radius: {{ (int)($brand?->button_radius ?? 18) }}px;
            --font-family: {{ $brand?->font_family ?? 'system-ui' }};
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: var(--font-family, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif);
            background: var(--background-color);
            color: #1f2937;
            line-height: 1.5;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 1rem;
        }

        /* Navigation */
        .navbar {
            background: white;
            border-bottom: 1px solid #e5e7eb;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .navbar-inner {
            display: flex;
            justify-content: space-between;
            align-items: center;
            height: 4rem;
            padding: 0 1rem;
        }

        .navbar-brand {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary-color);
        }

        .navbar-nav {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .nav-link {
            color: #6b7280;
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 500;
            padding: 0.5rem 0.75rem;
            border-radius: 0.375rem;
            transition: all 0.2s;
        }

        .nav-link:hover {
            color: #374151;
            background: #f3f4f6;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font-size: 0.875rem;
            font-weight: 500;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            filter: brightness(0.92);
        }

        /* Main Content */
        .main-content {
            min-height: calc(100vh - 8rem);
            padding: 2rem 0;
        }

        /* App Shell (analytics-like) */
        .app-shell {
            display: grid;
            grid-template-columns: 260px 1fr;
            gap: 2rem;
            align-items: start;
        }

        .app-panel {
            background: linear-gradient(180deg, rgba(255,255,255,0.9), rgba(255,255,255,0.85));
            border-radius: 20px;
            padding: 1.5rem;
            box-shadow: 0 8px 40px rgba(2,6,23,0.06);
        }

        .sidebar {
            width: 100%;
            height: auto;
            padding: 1rem;
            border-radius: var(--button-radius);
            background: linear-gradient(180deg, var(--background-color), #ffffff);
            border: 1px solid #eef2f7;
        }

        .sidebar .brand {
            font-weight: 700;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }

        .sidebar .nav {
            display: grid;
            gap: 0.6rem;
            margin-top:1rem;
        }

        .sidebar .nav a {
            display:flex;align-items:center;gap:0.8rem;padding:0.6rem 0.8rem;border-radius:10px;color:#374151;text-decoration:none;font-weight:600;
        }

        .sidebar .nav a.active{background:#eef2ff;color:#1e3a8a}

        .topbar {
            display:flex;align-items:center;gap:1rem;margin-bottom:1rem;
        }

        .search {
            flex:1;display:flex;align-items:center;background:#f1f5f9;padding:0.6rem 0.8rem;border-radius:999px;border:1px solid #e6eef8;
        }

        .user-pill {display:flex;align-items:center;gap:0.6rem}

        /* Small stat cards */
        .stat-small {padding:1rem;border-radius:12px;background:white;border:1px solid #eef2f7;box-shadow:0 6px 20px rgba(2,6,23,0.04)}

        /* Responsive adjustments */
        @media (max-width: 1024px) {
            .app-shell {grid-template-columns: 220px 1fr;}
        }

        @media (max-width: 768px) {
            .app-shell {grid-template-columns: 1fr;}
            .sidebar {display:flex;gap:0.6rem;overflow:auto}
            .sidebar .nav {display:flex;gap:0.6rem}
        }

        /* Cards */
        .card {
            display: block;
            background: white;
            border-radius: 0.75rem;
            border: 1px solid #e5e7eb;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        /* Anchor-style cards */
        a.card {
            display: block;
            text-decoration: none;
            color: inherit;
        }

        .card-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e5e7eb;
        }

        .card-body {
            padding: 1.5rem;
        }

        /* Grid */
        .grid {
            display: grid;
            gap: 1.5rem;
        }

        .grid-cols-1 {
            grid-template-columns: repeat(1, minmax(0, 1fr));
        }

        .grid-cols-2 {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .grid-cols-3 {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .grid-cols-4 {
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }

        .gap-6 {
            gap: 1.5rem;
        }

        /* Spacing */
        .space-y-4 > * + * {
            margin-top: 1rem;
        }

        .space-y-6 > * + * {
            margin-top: 1.5rem;
        }

        .space-y-8 > * + * {
            margin-top: 2rem;
        }

        .space-x-4 > * + * {
            margin-left: 1rem;
        }

        /* Flexbox */
        .flex {
            display: flex;
        }

        .flex-col {
            flex-direction: column;
        }

        .items-center {
            align-items: center;
        }

        .items-start {
            align-items: flex-start;
        }

        .justify-between {
            justify-content: space-between;
        }

        .justify-center {
            justify-content: center;
        }

        /* Text */
        .text-center {
            text-align: center;
        }

        .text-2xl {
            font-size: 1.5rem;
            line-height: 2rem;
        }

        .text-3xl {
            font-size: 1.875rem;
            line-height: 2.25rem;
        }

        .text-lg {
            font-size: 1.125rem;
            line-height: 1.75rem;
        }

        .text-sm {
            font-size: 0.875rem;
            line-height: 1.25rem;
        }

        .font-bold {
            font-weight: 700;
        }

        .font-semibold {
            font-weight: 600;
        }

        .font-medium {
            font-weight: 500;
        }

        .capitalize {
            text-transform: capitalize;
        }

        /* Colors */
        .text-gray-500 {
            color: #6b7280;
        }

        .text-gray-600 {
            color: #4b5563;
        }

        .text-gray-700 {
            color: #374151;
        }

        .text-gray-900 {
            color: #111827;
        }

        .text-blue-100 {
            color: #dbeafe;
        }

        .text-red-400 {
            color: #f87171;
        }

        .text-red-700 {
            color: #b91c1c;
        }

        .text-red-800 {
            color: #991b1b;
        }

        /* Backgrounds */
        .bg-blue-50 {
            background: #eff6ff;
        }

        .bg-purple-50 {
            background: #faf5ff;
        }

        .bg-green-50 {
            background: #ecfdf5;
        }

        .bg-gray-50 {
            background: #f9fafb;
        }

        .bg-white {
            background: white;
        }

        .bg-red-50 {
            background: #fef2f2;
        }

        .bg-red-100 {
            background: #fee2e2;
        }

        .bg-red-200 {
            background: #fecaca;
        }

        /* Semi-transparent backgrounds */
        .bg-white\/20 {
            background: rgba(255, 255, 255, 0.2);
        }

        /* Gradients */
        .bg-gradient-blue-purple {
            background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
        }

        /* Borders */
        .border {
            border: 1px solid #e5e7eb;
        }

        .border-b {
            border-bottom: 1px solid #e5e7eb;
        }

        .rounded-xl {
            border-radius: 0.75rem;
        }

        .rounded-lg {
            border-radius: 0.5rem;
        }

        /* Padding */
        .p-4 {
            padding: 1rem;
        }

        .p-6 {
            padding: 1.5rem;
        }

        .p-8 {
            padding: 2rem;
        }

        .px-4 {
            padding-left: 1rem;
            padding-right: 1rem;
        }

        .py-2 {
            padding-top: 0.5rem;
            padding-bottom: 0.5rem;
        }

        .py-4 {
            padding-top: 1rem;
            padding-bottom: 1rem;
        }

        /* Margin */
        .mb-2 {
            margin-bottom: 0.5rem;
        }

        .mb-4 {
            margin-bottom: 1rem;
        }

        .ml-2 {
            margin-left: 0.5rem;
        }

        .ml-3 {
            margin-left: 0.75rem;
        }

        .ml-4 {
            margin-left: 1rem;
        }

        .mt-4 {
            margin-top: 1rem;
        }

        /* Width/Height */
        .w-6 {
            width: 1.5rem;
        }

        .w-8 {
            width: 2rem;
        }

        .w-12 {
            width: 3rem;
        }

        .h-6 {
            height: 1.5rem;
        }

        .h-8 {
            height: 2rem;
        }

        .h-12 {
            height: 3rem;
        }

        /* Hover effects */
        .hover\:shadow-lg:hover {
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }

        .hover\:bg-blue-200:hover {
            background: #bfdbfe;
        }

        .hover\:bg-purple-200:hover {
            background: #e9d5ff;
        }

        .hover\:bg-green-200:hover {
            background: #a7f3d0;
        }

        /* Transitions */
        .transition-all {
            transition: all 0.2s;
        }

        .transition-colors {
            transition: color 0.2s, background-color 0.2s;
        }

        /* Stat cards */
        .stat-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 0.75rem;
            padding: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .stat-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 3rem;
            height: 3rem;
            min-width:3rem;
            border-radius: var(--button-radius);
            background: var(--primary-color);
            color: white;
        }

        /* Ensure small icons inside stat-icon */
        .stat-icon svg {
            width: 1.25rem;
            height: 1.25rem;
        }

        /* Limit SVG sizes inside card bodies to prevent oversized art */
        .card .card-body svg {
            max-width: 48px;
            max-height: 48px;
            width: auto;
            height: auto;
        }

        .stat-content {
            flex: 1;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: #111827;
            line-height: 1;
        }

        .stat-label {
            font-size: 0.875rem;
            color: #6b7280;
            font-weight: 500;
            margin-top: 0.25rem;
        }

        /* Compact action cards used in gateway tenant dashboard */
        .action-card {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.9rem 1rem;
            border-radius: 0.5rem;
            border: 1px solid #edf2f7;
            background: white;
            transition: box-shadow 0.15s ease, transform 0.12s ease;
            text-decoration: none;
            color: inherit;
            flex-wrap: wrap;
        }

        .action-card:hover {
            box-shadow: 0 8px 24px rgba(16,24,40,0.06);
            transform: translateY(-4px);
            border-color: var(--primary-color);
        }

        .action-card .btn {
            font-size: 0.85rem;
            padding: 0.35rem 0.9rem;
        }

        /* Keep the action button to the far right when space allows */
        .action-card > div:last-child {
            margin-left: auto;
        }

        /* Alerts */
        .alert {
            padding: 1.5rem;
            border-radius: 0.75rem;
            border: 1px solid;
        }

        .alert-error {
            background: #fef2f2;
            border-color: #fecaca;
            color: #991b1b;
        }

        /* Footer */
        .footer {
            background: white;
            border-top: 1px solid #e5e7eb;
            padding: 1rem 0;
            margin-top: auto;
        }

        .footer-text {
            text-align: center;
            font-size: 0.875rem;
            color: #6b7280;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .grid-cols-3 {
                grid-template-columns: repeat(1, minmax(0, 1fr));
            }

            .grid-cols-4 {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .md\:grid-cols-4 {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }

            .navbar-nav {
                display: none;
            }
        }

        @media (min-width: 640px) {
            .sm\:grid-cols-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }

        @media (min-width: 768px) {
            .md\:grid-cols-4 {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }

            .md\:grid-cols-3 {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }
    </style>
</head>
<body>
    <div class="navbar">
        <div class="container">
            <div class="navbar-inner">
                <div class="navbar-brand">{{ $brand?->business_name ?? 'Gateway' }}</div>
                <div class="navbar-nav">
                    <a href="/gateway" class="nav-link">Home</a>
                    @if(auth()->user()?->hasRole('super_admin'))
                        <a href="/gateway/super" class="nav-link">Super Admin</a>
                        <a href="{{ route('gateway.super.landing-page.edit') }}" class="nav-link">Landing Page</a>
                    @endif
                    <form method="POST" action="/logout" class="inline">
                        @csrf
                        <button type="submit" class="btn btn-primary">
                            <svg class="icon mr-2">
                                <path d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                            </svg>
                            Logout
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <main class="main-content">
        <div class="container">
            {{ $slot }}
        </div>
    </main>

    <footer class="footer">
        <div class="container">
            <div class="footer-text">Foundation mode: dummy auth system</div>
        </div>
    </footer>
</body>
</html>
