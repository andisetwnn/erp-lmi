{{--
    Penanda aplikasi terpasang untuk DBOS.

    Sengaja terpisah dari partials/head — berkas itu dipakai seluruh ERP juga,
    dan yang boleh dipasang sebagai aplikasi hanya DBOS.
--}}
<link rel="manifest" href="/dbos/manifest.json">
<meta name="theme-color" content="#ea580c">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="DBOS">
<link rel="apple-touch-icon" href="/dbos/icon-192.png">

<script>
    // Lingkup dipersempit ke /dbos supaya halaman ERP di luar itu tidak ikut
    // dikendalikan. Mempersempit tidak butuh header tambahan di server.
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/sw-dbos.js', { scope: '/dbos' })
                .catch((e) => console.warn('Service worker DBOS gagal didaftarkan:', e));
        });
    }
</script>
