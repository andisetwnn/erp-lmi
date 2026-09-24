/**
 * Service worker DBOS.
 *
 * Berkas ini sengaja diletakkan di akar situs, tapi didaftarkan dengan lingkup
 * "/dbos" saja — mempersempit lingkup selalu boleh, memperluas yang butuh header
 * khusus. Dengan begitu halaman ERP di luar /dbos tidak pernah disentuh.
 *
 * Yang di-cache hanya halaman "tidak ada koneksi" beserta ikonnya. Berkas
 * aplikasi sengaja TIDAK di-cache: sistemnya masih sering berubah, dan versi
 * lama yang nyangkut di ponsel sales jauh lebih merepotkan daripada memuat
 * ulang dari jaringan.
 */
const VERSI = 'dbos-v1';
const HALAMAN_OFFLINE = '/dbos-app/offline.html';
const BEKAL = [HALAMAN_OFFLINE, '/dbos-app/icon-192.png'];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(VERSI)
            .then((cache) => cache.addAll(BEKAL))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((kunci) => Promise.all(
                kunci.filter((k) => k !== VERSI).map((k) => caches.delete(k))
            ))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const req = event.request;

    // Hanya perpindahan halaman yang diberi jaring pengaman. Permintaan lain
    // (Livewire, unggahan, gambar) dibiarkan apa adanya supaya kegagalannya
    // ditangani halaman itu sendiri, bukan disamarkan jadi halaman offline.
    if (req.mode !== 'navigate') {
        return;
    }

    event.respondWith(
        fetch(req).catch(() => caches.match(HALAMAN_OFFLINE))
    );
});
