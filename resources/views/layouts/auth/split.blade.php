@php
    /*
     * Panel kiri halaman masuk: siteplan isometrik Blok AB–AG tempat semua divisi bekerja.
     * Rumah jadi naik satu per satu, rumah yang sedang dibangun tampil sebagai rangka,
     * lalu penanda dari tiap divisi muncul di kavlingnya masing-masing.
     * Pola status ditulis tetap supaya gambarnya sama setiap kali dibuka.
     */
    $skala = 25;
    $proyeksi = fn (float $x, float $y, float $z = 0) => [
        round(($x - $y) * $skala * 0.866, 1),
        round(($x + $y) * $skala * 0.5 - $z * $skala, 1),
    ];
    $titik = fn (array $sudut) => implode(' ', array_map(fn ($p) => $p[0].','.$p[1], $sudut));

    // R = rumah jadi, K = sedang dibangun, B = booking, . = kavling kosong
    $polaBaris = [
        'AB' => 'RRRRRRRRKR',
        'AC' => 'RRRRRBRRRR',
        'AD' => 'RRKRRR.RR.',
        'AE' => 'R.RBRRK..R',
        'AF' => '.RK.BR...R',
        'AG' => '..B...K...',
    ];

    // Penanda divisi: [blok, indeks kavling, divisi, isi, geser x, geser y, sisi kartu]
    $penanda = [
        ['AB', 1, 'Legal', 'AB-02 · sertifikat ke bank', -64, -46, 'kiri'],
        ['AC', 4, 'Keuangan', 'AC-05 · UM ke-4 diterima', 34, -78, 'kanan'],
        ['AD', 8, 'KPR', 'AD-09 · SP3K terbit', 74, 34, 'kanan'],
        ['AE', 6, 'Teknik', 'AE-07 · progres bangun 80%', -178, -86, 'kiri'],
        ['AG', 2, 'Marketing', 'AG-03 · booking baru', -70, 40, 'kiri'],
    ];

    $dalam = 1.25;
    $jalan = 0.95;
    $kavling = [];
    $label = [];
    $jangkar = [];
    $semuaTitik = [];
    $urutBaris = 0;

    foreach ($polaBaris as $blok => $pola) {
        $pasang = intdiv($urutBaris, 2);
        $y0 = $pasang * (2 * $dalam + $jalan) + ($urutBaris % 2) * $dalam;
        $y1 = $y0 + $dalam;
        $label[] = ['teks' => $blok, 'p' => $proyeksi(-0.3, $y0 + $dalam / 2)];

        foreach (str_split($pola) as $kolom => $status) {
            $x0 = $kolom + 0.06;
            $x1 = $kolom + 0.94;
            $tanah = [$proyeksi($x0, $y0 + 0.05), $proyeksi($x1, $y0 + 0.05), $proyeksi($x1, $y1 - 0.05), $proyeksi($x0, $y1 - 0.05)];
            array_push($semuaTitik, ...$tanah);
            $item = ['status' => $status, 'tanah' => $titik($tanah), 'urut' => $x0 + $y0];

            // Rumah lebih kecil dari kavlingnya: ada halaman depan.
            [$rx0, $rx1, $ry0, $ry1] = [$x0 + 0.13, $x1 - 0.13, $y0 + 0.16, $y1 - 0.36];
            $ym = ($ry0 + $ry1) / 2;
            $h = 0.42;
            $atap = 0.27;

            if ($status === 'R') {
                $item['bidang'] = [
                    'dinding-kanan' => $titik([$proyeksi($rx1, $ry0), $proyeksi($rx1, $ry1), $proyeksi($rx1, $ry1, $h), $proyeksi($rx1, $ry0, $h)]),
                    'dinding-depan' => $titik([$proyeksi($rx0, $ry1), $proyeksi($rx1, $ry1), $proyeksi($rx1, $ry1, $h), $proyeksi($rx0, $ry1, $h)]),
                    'atap-belakang' => $titik([$proyeksi($rx0, $ry0, $h), $proyeksi($rx1, $ry0, $h), $proyeksi($rx1, $ym, $h + $atap), $proyeksi($rx0, $ym, $h + $atap)]),
                    'pelana' => $titik([$proyeksi($rx1, $ry0, $h), $proyeksi($rx1, $ry1, $h), $proyeksi($rx1, $ym, $h + $atap)]),
                    'atap-depan' => $titik([$proyeksi($rx0, $ry1, $h), $proyeksi($rx1, $ry1, $h), $proyeksi($rx1, $ym, $h + $atap), $proyeksi($rx0, $ym, $h + $atap)]),
                ];
                $item['puncak'] = $proyeksi(($rx0 + $rx1) / 2, $ym, $h + $atap);
                $semuaTitik[] = $proyeksi($rx0, $ym, $h + $atap);
            } elseif ($status === 'K') {
                $item['rangka'] = [
                    $titik([$proyeksi($rx1, $ry0), $proyeksi($rx1, $ry1), $proyeksi($rx1, $ry1, $h), $proyeksi($rx1, $ry0, $h)]),
                    $titik([$proyeksi($rx0, $ry1), $proyeksi($rx1, $ry1), $proyeksi($rx1, $ry1, $h), $proyeksi($rx0, $ry1, $h)]),
                    $titik([$proyeksi($rx0, $ry0, $h), $proyeksi($rx1, $ry0, $h), $proyeksi($rx1, $ry1, $h), $proyeksi($rx0, $ry1, $h)]),
                ];
                $item['puncak'] = $proyeksi(($rx0 + $rx1) / 2, $ym, $h);
            } else {
                $item['puncak'] = $proyeksi(($x0 + $x1) / 2, ($y0 + $y1) / 2);
            }

            foreach ($penanda as $i => $p) {
                if ($p[0] === $blok && $p[1] === $kolom) {
                    $ujung = [$item['puncak'][0] + $p[4], $item['puncak'][1] + $p[5]];
                    $jangkar[$i] = ['dari' => $item['puncak'], 'ke' => $ujung];
                    $semuaTitik[] = $ujung;
                }
            }
            $kavling[] = $item;
        }
        $urutBaris++;
    }
    usort($kavling, fn ($a, $b) => $a['urut'] <=> $b['urut']);
    ksort($jangkar);

    $xs = array_column($semuaTitik, 0);
    $ys = array_column($semuaTitik, 1);
    $vb = [min($xs) - 30, min($ys) - 12, max($xs) - min($xs) + 46, max($ys) - min($ys) + 24];

    // Peta + kartu penanda diskalakan sebagai satu gambar. Ukuran rancangannya: peta 680 px,
    // ditambah ruang kartu di kiri/kanan/atas/bawah — jadi viewBox diperluas sebesar itu.
    $lebarPeta = 680;
    $perPx = $vb[2] / $lebarPeta;
    [$ruangKiri, $ruangKanan, $ruangAtas, $ruangBawah] = [175, 180, 30, 30];
    $vb = [
        round($vb[0] - $ruangKiri * $perPx, 1), round($vb[1] - $ruangAtas * $perPx, 1),
        round($vb[2] + ($ruangKiri + $ruangKanan) * $perPx, 1), round($vb[3] + ($ruangAtas + $ruangBawah) * $perPx, 1),
    ];
    $lebarRancangan = $lebarPeta + $ruangKiri + $ruangKanan;
    $persen = fn (array $p) => [round(($p[0] - $vb[0]) / $vb[2] * 100, 2), round(($p[1] - $vb[1]) / $vb[3] * 100, 2)];

    $jedaNaik = 0;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@112,700;112,800&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
        <style>
            .lmi-masuk { --malam: #0f1530; --senja: #26235e; --ungu: #4b2f5e; --bata: #a8480f; --garis: rgba(196, 204, 228, .32); --oranye-teks: #ff9a4d;
                display: grid; grid-template-columns: 1fr; min-height: 100dvh; }
            @media (min-width: 1024px) { .lmi-masuk { grid-template-columns: minmax(0, 56fr) minmax(0, 44fr); height: 100dvh; } }

            /* ---------- panel kiri: langit senja + siteplan ---------- */
            .lmi-langit { display: none; position: relative; overflow: hidden; color: #f4f1f6; padding: clamp(22px, 4.4vh, 44px) 52px clamp(16px, 3.2vh, 32px);
                background:
                    radial-gradient(110% 70% at 70% 118%, rgba(240, 122, 34, .6), rgba(240, 122, 34, 0) 58%),
                    radial-gradient(60% 40% at 10% 0%, rgba(92, 108, 220, .22), rgba(92, 108, 220, 0) 70%),
                    linear-gradient(180deg, var(--malam) 0%, var(--senja) 46%, var(--ungu) 80%, #7b4352 100%); }
            @media (min-width: 1024px) { .lmi-langit { display: flex; flex-direction: column; } }

            .lmi-merek { display: flex; align-items: center; gap: 12px; }
            .lmi-merek img { width: 38px; height: 38px; filter: drop-shadow(0 2px 6px rgba(0, 0, 0, .35)); }
            .lmi-merek-nama { font-family: 'Archivo', 'Instrument Sans', sans-serif; font-stretch: 112%; font-weight: 800; font-size: 19px; letter-spacing: .01em; }
            .lmi-merek-garis { width: 1px; height: 18px; background: rgba(255, 255, 255, .28); }
            .lmi-merek-pt { font-family: 'IBM Plex Mono', ui-monospace, monospace; font-size: 10.5px; letter-spacing: .22em; color: rgba(236, 232, 246, .62); }

            .lmi-judul { margin: clamp(14px, 4.4vh, 46px) 0 0; font-family: 'Archivo', 'Instrument Sans', sans-serif; font-stretch: 112%; font-weight: 800;
                font-size: clamp(26px, min(3.2vw, 5.6vh), 54px); line-height: 1.02; letter-spacing: -.02em; color: #faf7fb; max-width: 16ch; }
            .lmi-judul em { font-style: normal; color: var(--oranye-teks); }
            .lmi-sub { margin: clamp(6px, 1.6vh, 16px) 0 0; max-width: 44ch; font-size: clamp(13.5px, 1.9vh, 16px); line-height: 1.55; color: rgba(236, 232, 246, .74); }

            .lmi-siteplan { position: relative; flex: 1; min-height: 0; display: flex; flex-direction: column; justify-content: center; align-items: center; padding: clamp(4px, 1.6vh, 18px) 0 0; }
            /* Peta mengikuti ruang tersisa — lebar & tinggi — supaya halaman tidak perlu digulir */
            .lmi-peta { flex: 1; min-height: 0; width: 100%; container-type: size; display: flex; align-items: center; justify-content: center; }
            .lmi-siteplan-gambar { position: relative; container-type: inline-size; aspect-ratio: {{ $vb[2] }} / {{ $vb[3] }};
                width: min(100cqw, {{ $lebarRancangan }}px, calc(100cqh * {{ $vb[2] }} / {{ $vb[3] }})); }
            .lmi-siteplan svg { display: block; width: 100%; height: auto; overflow: visible; }
            .lmi-tanah { fill: rgba(255, 255, 255, .025); stroke: var(--garis); stroke-width: .8; }
            .lmi-tanah.booking { fill: rgba(255, 154, 77, .17); stroke: #ffae6e; stroke-dasharray: 3 2.5; }
            .dinding-kanan { fill: var(--bata); }
            .dinding-depan { fill: #d9661b; }
            .pelana { fill: #b9551a; }
            .atap-belakang { fill: #ffb680; }
            .atap-depan { fill: #f28a3c; }
            .lmi-rangka polygon { fill: rgba(255, 162, 90, .07); stroke: #ffb27a; stroke-width: .9; stroke-dasharray: 2.5 2; }
            .lmi-label-blok { font-family: 'IBM Plex Mono', ui-monospace, monospace; font-size: 8.5px; fill: rgba(236, 232, 246, .55); letter-spacing: .08em; }
            .lmi-tali { stroke: rgba(255, 255, 255, .6); stroke-width: .8; stroke-dasharray: 2 2; fill: none; }
            .lmi-tali-titik { fill: #fff; }

            .lmi-tanda { position: absolute; display: flex; flex-direction: column; gap: .19cqw; padding: .68cqw 1.06cqw .77cqw; border-radius: .97cqw; white-space: nowrap;
                background: rgba(255, 255, 255, .1); border: 1px solid rgba(255, 255, 255, .2); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
                box-shadow: 0 10px 24px rgba(8, 10, 30, .3); }
            .lmi-tanda.kiri { transform: translate(-100%, -50%); }
            .lmi-tanda.kanan { transform: translate(0, -50%); }
            .lmi-tanda-divisi { font-family: 'IBM Plex Mono', ui-monospace, monospace; font-size: {{ round(9.5 / $lebarRancangan * 100, 3) }}cqw; font-weight: 500; letter-spacing: .14em; text-transform: uppercase; color: var(--oranye-teks); }
            .lmi-tanda-isi { font-size: {{ round(12.5 / $lebarRancangan * 100, 3) }}cqw; color: #fff; }

            .lmi-legenda { display: flex; flex-wrap: wrap; justify-content: center; gap: 8px 18px; margin-top: clamp(6px, 1.4vh, 16px); font-family: 'IBM Plex Mono', ui-monospace, monospace; font-size: 10.5px; letter-spacing: .06em; color: rgba(236, 232, 246, .66); }
            .lmi-legenda span::before { content: ''; display: inline-block; width: 10px; height: 10px; margin-right: 7px; vertical-align: -1px; border-radius: 2px; }
            .lmi-legenda .rumah::before { background: #f28a3c; }
            .lmi-legenda .dibangun::before { border: 1.2px dashed #ffb27a; }
            .lmi-legenda .booking::before { border: 1.2px dashed #ffae6e; background: rgba(255, 154, 77, .2); }
            .lmi-legenda .kosong::before { border: 1px solid rgba(196, 204, 228, .6); }

            .lmi-divisi { display: flex; flex-wrap: wrap; gap: 8px; margin-top: clamp(10px, 2vh, 22px); padding-top: clamp(8px, 1.8vh, 18px); border-top: 1px solid rgba(255, 255, 255, .13); }
            .lmi-divisi span { padding: 5px 11px; border-radius: 999px; border: 1px solid rgba(255, 255, 255, .16); background: rgba(255, 255, 255, .05); font-size: 13px; color: rgba(236, 232, 246, .82); }

            /* Pembukaan: kavling muncul, rumah naik satu per satu, rangka & penanda menyusul */
            .lmi-tanah { animation: lmi-pudar .6s ease-out both; }
            .lmi-rumah { transform-box: fill-box; transform-origin: 50% 100%; animation: lmi-naik .7s cubic-bezier(.2, .8, .2, 1) both; animation-delay: var(--jeda); }
            .lmi-rangka { animation: lmi-pudar .6s ease-out both; animation-delay: var(--jeda); }
            .lmi-rangka polygon { animation: lmi-jalan 1.4s linear infinite; }
            .lmi-tali-grup, .lmi-tanda { animation: lmi-muncul .5s ease-out both, lmi-sorot 12.5s ease-in-out infinite; animation-delay: var(--muncul), var(--sorot); }
            @keyframes lmi-pudar { from { opacity: 0; } }
            @keyframes lmi-naik { from { transform: scaleY(.03); opacity: 0; } }
            @keyframes lmi-muncul { from { opacity: 0; translate: 0 6px; } }
            @keyframes lmi-jalan { to { stroke-dashoffset: -9; } }
            /* Setelah semua tampil, penanda bergantian menyala: aktivitas di divisi yang berbeda-beda */
            @keyframes lmi-sorot {
                0%, 20%, 100% { opacity: .55; }
                4%, 16% { opacity: 1; }
            }
            @media (prefers-reduced-motion: reduce) {
                .lmi-tanah, .lmi-rumah, .lmi-rangka, .lmi-rangka polygon, .lmi-tali-grup, .lmi-tanda { animation: none; }
            }

            /* ---------- panel kanan: form ---------- */
            .lmi-form-sisi { display: flex; flex-direction: column; padding: 32px 24px 24px; background: #fff; overflow-y: auto; }
            .dark .lmi-form-sisi { background: #111113; }
            .lmi-form-isi { flex: 1; display: flex; flex-direction: column; justify-content: center; width: 100%; max-width: 400px; margin: 0 auto; }
            .lmi-form-merek { display: flex; align-items: center; gap: 10px; margin-bottom: 30px; }
            .lmi-form-merek img { width: 34px; height: 34px; }
            .lmi-form-merek span { font-family: 'Archivo', 'Instrument Sans', sans-serif; font-stretch: 112%; font-weight: 800; font-size: 17px; letter-spacing: .01em; color: #1d1b26; }
            .dark .lmi-form-merek span { color: #f4f4f5; }
            .lmi-kaki { text-align: center; font-size: 12px; color: #8b8794; margin-top: 28px; }
        </style>
    </head>
    <body class="min-h-screen bg-white antialiased">
        <div class="lmi-masuk">
            <aside class="lmi-langit" aria-hidden="true">
                <div class="lmi-merek">
                    <img src="{{ asset('images/logo-mark.png') }}" alt="">
                    <span class="lmi-merek-nama">ERP LMI</span>
                    <span class="lmi-merek-garis"></span>
                    <span class="lmi-merek-pt">PT LANGIT MEMBANGUN INDONESIA</span>
                </div>

                <h2 class="lmi-judul">Dari kavling kosong sampai <em>laporan.</em></h2>
                <p class="lmi-sub">Marketing, KPR, keuangan, akunting, legal, dan teknik LMI bekerja di satu sistem.</p>

                <div class="lmi-siteplan">
                    <div class="lmi-peta">
                    <div class="lmi-siteplan-gambar">
                        <svg viewBox="{{ implode(' ', $vb) }}" xmlns="http://www.w3.org/2000/svg">
                            @foreach ($label as $l)
                                <text class="lmi-label-blok" x="{{ $l['p'][0] }}" y="{{ $l['p'][1] + 3 }}" text-anchor="end">{{ $l['teks'] }}</text>
                            @endforeach
                            @foreach ($kavling as $k)
                                <polygon class="lmi-tanah {{ $k['status'] === 'B' ? 'booking' : '' }}" points="{{ $k['tanah'] }}"/>
                                @if ($k['status'] === 'R')
                                    @php $jedaNaik += 34; @endphp
                                    <g class="lmi-rumah" style="--jeda: {{ 200 + $jedaNaik }}ms">
                                        @foreach ($k['bidang'] as $kelas => $sudut)
                                            <polygon class="{{ $kelas }}" points="{{ $sudut }}"/>
                                        @endforeach
                                    </g>
                                @elseif ($k['status'] === 'K')
                                    <g class="lmi-rangka" style="--jeda: 1500ms">
                                        @foreach ($k['rangka'] as $sudut)
                                            <polygon points="{{ $sudut }}"/>
                                        @endforeach
                                    </g>
                                @endif
                            @endforeach
                            @foreach ($jangkar as $i => $j)
                                <g class="lmi-tali-grup" style="--muncul: {{ 1.9 + $i * 0.3 }}s; --sorot: {{ 3.6 + $i * 2.5 }}s">
                                    <line class="lmi-tali" x1="{{ $j['dari'][0] }}" y1="{{ $j['dari'][1] }}" x2="{{ $j['ke'][0] }}" y2="{{ $j['ke'][1] }}"/>
                                    <circle class="lmi-tali-titik" cx="{{ $j['dari'][0] }}" cy="{{ $j['dari'][1] }}" r="1.8"/>
                                </g>
                            @endforeach
                        </svg>

                        @foreach ($jangkar as $i => $j)
                            @php [$kiri, $atas] = $persen($j['ke']); $p = $penanda[$i]; @endphp
                            <div class="lmi-tanda {{ $p[6] }}" style="left: {{ $kiri }}%; top: {{ $atas }}%; --muncul: {{ 1.9 + $i * 0.3 }}s; --sorot: {{ 3.6 + $i * 2.5 }}s">
                                <span class="lmi-tanda-divisi">{{ $p[2] }}</span>
                                <span class="lmi-tanda-isi">{{ $p[3] }}</span>
                            </div>
                        @endforeach
                    </div>
                    </div>
                    <div class="lmi-legenda">
                        <span class="rumah">rumah</span>
                        <span class="dibangun">dibangun</span>
                        <span class="booking">booking</span>
                        <span class="kosong">kavling kosong</span>
                    </div>
                </div>

                <div class="lmi-divisi">
                    <span>Marketing &amp; DBOS</span>
                    <span>KPR &amp; akad</span>
                    <span>Keuangan</span>
                    <span>Akunting</span>
                    <span>Legal</span>
                    <span>Teknik</span>
                    <span>Laporan</span>
                </div>
            </aside>

            <main class="lmi-form-sisi">
                <div class="lmi-form-isi">
                    <div class="lmi-form-merek">
                        <img src="{{ asset('images/logo-mark.png') }}" alt="">
                        <span>ERP LMI</span>
                    </div>
                    {{ $slot }}
                </div>
                <p class="lmi-kaki">&copy; {{ date('Y') }} PT Langit Membangun Indonesia</p>
            </main>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
