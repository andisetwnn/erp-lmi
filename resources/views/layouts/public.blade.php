<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>{{ $title ?? 'PT Langit Membangun Indonesia' }}</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script>
        // Force light mode — konsumen tidak familiar dengan dark theme
        (function () {
            document.documentElement.classList.remove('dark');
            document.documentElement.classList.add('light');
            document.documentElement.style.colorScheme = 'light';
        })();
    </script>
</head>
<body class="min-h-screen bg-linear-to-br from-zinc-100 via-slate-100 to-zinc-100">
    {{ $slot }}
</body>
</html>
