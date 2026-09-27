<!DOCTYPE html>
<html>
<head>
    <title>Test</title>
</head>
<body>
    <h1>Test Page</h1>
    <p>Brand: {{ $brand?->business_name ?? 'No brand' }}</p>
    <p>Packages: {{ $featuredPackages->count() }}</p>
</body>
</html>
