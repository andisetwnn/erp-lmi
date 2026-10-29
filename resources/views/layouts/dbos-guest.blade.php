@php
    /*
     * Peraga halaman masuk DBOS: layar pilih unit versi mini (Blok AD 01–12) dengan warna
     * status yang sama seperti di aplikasi. Unit tersedia diketuk → jadi booking → muncul
     * notifikasi, bergiliran di tiga unit. T = terjual, B = booking, A = tersedia.
     */
    $unitPeraga = ['01' => 'T', '02' => 'T', '03' => 'B', '04' => 'A', '05' => 'T', '06' => 'A',
        '07' => 'A', '08' => 'B', '09' => 'T', '10' => 'A', '11' => 'T', '12' => 'A'];
    $giliran = ['04', '07', '10'];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
        @include('partials.dbos-head')
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@112,700;112,800&family=IBM+Plex+Mono:wght@500&display=swap" rel="stylesheet">
        <style>
            .dbos-masuk { --oranye: #ea580c; --oranye-tua: #c2410c; --hijau: #10b981; --kuning: #f59e0b; --biru: #3b82f6;
                display: flex; justify-content: center; min-height: 100dvh;
                background:
                    radial-gradient(60% 45% at 85% 4%, rgba(253, 224, 71, .5), rgba(253, 224, 71, 0) 70%),
                    radial-gradient(70% 55% at 0% 100%, rgba(124, 45, 18, .45), rgba(124, 45, 18, 0) 70%),
                    linear-gradient(165deg, #fb923c 0%, var(--oranye) 42%, var(--oranye-tua) 100%); }
            /* DBOS aplikasi HP: di layar lebar pun tampil satu kolom selebar HP di tengah */
            .dbos-kolom { width: 100%; max-width: 460px; min-height: 100dvh; display: flex; flex-direction: column; }
            @media (min-width: 640px) { .dbos-kolom { height: 100dvh; min-height: 0; padding: clamp(12px, 3vh, 32px) 0; } }

            /* ---------- sisi oranye: merek, judul, peraga ---------- */
            .dbos-pagi { position: relative; display: flex; flex-direction: column; flex: 1; min-height: 0; color: #fff; padding: clamp(18px, 3.2vh, 32px) 22px 34px; }
            @media (min-width: 640px) { .dbos-pagi { min-height: 0; padding: 6px 6px 22px; } }

            .dbos-merek { display: flex; align-items: center; gap: 10px; }
            .dbos-merek-ikon { width: 36px; height: 36px; border-radius: 10px; background: #fff; display: grid; place-items: center; box-shadow: 0 6px 16px rgba(124, 45, 18, .3); }
            .dbos-merek-ikon img { width: 26px; height: 26px; }
            .dbos-merek-nama { font-family: 'Archivo', 'Instrument Sans', sans-serif; font-stretch: 112%; font-weight: 800; font-size: 20px; letter-spacing: .02em; line-height: 1; }
            .dbos-merek-arti { font-size: 11.5px; color: rgba(255, 247, 237, .85); margin-top: 2px; }

            .dbos-judul { margin: clamp(14px, 3.6vh, 44px) 0 0; font-family: 'Archivo', 'Instrument Sans', sans-serif; font-stretch: 112%; font-weight: 800;
                font-size: clamp(24px, min(7.4vw, 4.6vh), 34px); line-height: 1.03; letter-spacing: -.02em; max-width: 19ch; text-wrap: balance; }
            .dbos-sub { margin: clamp(6px, 1.4vh, 14px) 0 0; max-width: 40ch; font-size: clamp(13.5px, 1.9vh, 16px); line-height: 1.5; color: rgba(255, 247, 237, .9); }
            @media (max-height: 700px) { .dbos-sub { display: none; } }

            /* Peraga diskalakan mengikuti ruang tersisa: semua ukuran di dalamnya memakai em */
            .dbos-peraga { flex: 1; min-height: 70px; container-type: size; display: flex; align-items: center; justify-content: center; padding-top: 10px; }
            /* Terlalu kecil untuk terbaca → tidak usah ditampilkan */
            @container (max-height: 110px) { .dbos-layar { display: none; } }
            .dbos-layar { position: relative; container-type: inline-size; width: min(100cqw, 470px, calc(100cqh * 1.5)); aspect-ratio: 1.5; }
            .dbos-layar-isi { position: absolute; inset: 0; font-size: calc(100cqw / 47); transform: perspective(70em) rotateX(12deg) rotateZ(-3deg);
                animation: dbos-melayang 6s ease-in-out infinite; }
            .dbos-kartu { position: absolute; inset: 7em 0 0; background: #fff; border-radius: 1.8em; padding: 1.6em 1.8em; color: #1c1917;
                box-shadow: 0 2.6em 5em rgba(124, 45, 18, .38), 0 .3em .8em rgba(124, 45, 18, .18); }
            .dbos-kartu-kepala { display: flex; justify-content: space-between; align-items: baseline; }
            .dbos-kartu-judul { font-size: 1.55em; font-weight: 700; }
            .dbos-kartu-proyek { font-family: 'IBM Plex Mono', ui-monospace, monospace; font-size: 1.05em; color: #78716c; letter-spacing: .04em; }
            .dbos-petak { display: grid; grid-template-columns: repeat(6, 1fr); gap: .9em; margin-top: 1.4em; }
            .dbos-unit { position: relative; aspect-ratio: 1; border-radius: .9em; display: grid; place-items: center; color: #fff; font-weight: 700; font-size: 1.45em; }
            .dbos-unit.A { background: var(--hijau); }
            .dbos-unit.B { background: var(--kuning); }
            .dbos-unit.T { background: var(--biru); }
            .dbos-legenda { display: flex; gap: 1.6em; margin-top: 1.3em; font-size: 1.05em; color: #57534e; }
            .dbos-legenda span::before { content: ''; display: inline-block; width: .8em; height: .8em; border-radius: 50%; margin-right: .5em; vertical-align: -.05em; }
            .dbos-legenda .A::before { background: var(--hijau); }
            .dbos-legenda .B::before { background: var(--kuning); }
            .dbos-legenda .T::before { background: var(--biru); }

            .dbos-ketuk { position: absolute; inset: 18%; border-radius: 50%; background: rgba(255, 255, 255, .85); opacity: 0; pointer-events: none; }
            .dbos-notif { position: absolute; left: 50%; top: 0; transform: translateX(-50%); display: flex; align-items: center; gap: .9em; white-space: nowrap;
                padding: 1em 1.5em; border-radius: 1.3em; background: #1c1917; color: #fff; font-size: 1.3em; box-shadow: 0 1.2em 2.4em rgba(28, 25, 23, .35); opacity: 0; }
            .dbos-notif b { font-family: 'IBM Plex Mono', ui-monospace, monospace; font-weight: 500; color: #fcd34d; }
            .dbos-notif i { width: .65em; height: .65em; border-radius: 50%; background: var(--kuning); box-shadow: 0 0 0 .3em rgba(245, 158, 11, .3); }

            /* Giliran 9 detik: unit diketuk → jadi booking → notifikasi muncul, lalu unit berikutnya */
            .dbos-unit.giliran { animation: dbos-jadi-booking 9s linear infinite both; animation-delay: var(--mulai); }
            .dbos-unit.giliran .dbos-ketuk { animation: dbos-riak 9s ease-out infinite both; animation-delay: var(--mulai); }
            .dbos-notif { animation: dbos-notif 9s ease-out infinite both; animation-delay: var(--mulai); }
            @keyframes dbos-jadi-booking { 0%, 3% { background: var(--hijau); } 4%, 96% { background: var(--kuning); } 100% { background: var(--hijau); } }
            @keyframes dbos-riak { 0% { opacity: 0; transform: scale(.4); } 1.5% { opacity: .9; } 9% { opacity: 0; transform: scale(2.1); } 100% { opacity: 0; } }
            @keyframes dbos-notif { 0%, 4% { opacity: 0; translate: 0 -.6em; } 8%, 28% { opacity: 1; translate: 0 0; } 32%, 100% { opacity: 0; translate: 0 -.4em; } }
            @keyframes dbos-melayang { 50% { translate: 0 -.5em; } }
            @media (prefers-reduced-motion: reduce) {
                .dbos-layar-isi, .dbos-unit.giliran, .dbos-ketuk, .dbos-notif { animation: none; }
                .dbos-unit.giliran.pertama { background: var(--kuning); }
                .dbos-notif.pertama { opacity: 1; }
            }

            /* ---------- lembar form ---------- */
            .dbos-lembar { position: relative; z-index: 1; margin-top: -22px; border-radius: 24px 24px 0 0; background: #fff; padding: 26px 22px 18px;
                display: flex; flex-direction: column; box-shadow: 0 -10px 30px rgba(124, 45, 18, .12); }
            .dark .dbos-lembar { background: #18181b; }
            @media (min-width: 640px) { .dbos-lembar { margin-top: 0; border-radius: 24px; padding: 28px 26px 18px; box-shadow: 0 30px 60px rgba(124, 45, 18, .35), 0 4px 12px rgba(124, 45, 18, .15); } }
            .dbos-lembar-isi { width: 100%; }
            .dbos-kaki { text-align: center; font-size: 12px; color: #8b8794; margin-top: 16px; }
            /* Layar pendek: form dipadatkan supaya tidak perlu digulir */
            @media (max-height: 760px) { .dbos-kaki { display: none; } .dbos-lembar { padding-top: 22px; padding-bottom: 16px; } }
        </style>
    </head>
    <body class="min-h-screen bg-white antialiased">
        <div class="dbos-masuk">
        <div class="dbos-kolom">
            <section class="dbos-pagi">
                <div class="dbos-merek">
                    <span class="dbos-merek-ikon"><img src="{{ asset('images/logo-mark.png') }}" alt=""></span>
                    <div>
                        <div class="dbos-merek-nama">DBOS</div>
                        <div class="dbos-merek-arti">Data Booking Online System</div>
                    </div>
                </div>

                <h1 class="dbos-judul">Booking kavling langsung dari lapangan.</h1>
                <p class="dbos-sub">Catat prospek, booking unit, dan pantau SPR konsumen dari HP.</p>

                <div class="dbos-peraga" aria-hidden="true">
                    <div class="dbos-layar">
                        <div class="dbos-layar-isi">
                            @foreach ($giliran as $i => $no)
                                <div class="dbos-notif {{ $i === 0 ? 'pertama' : '' }}" style="--mulai: {{ 1.2 + $i * 3 }}s"><i></i><span><b>AD-{{ $no }}</b> · booking tercatat</span></div>
                            @endforeach
                            <div class="dbos-kartu">
                                <div class="dbos-kartu-kepala">
                                    <span class="dbos-kartu-judul">Blok AD</span>
                                    <span class="dbos-kartu-proyek">GRHA ARYANA</span>
                                </div>
                                <div class="dbos-petak">
                                    @foreach ($unitPeraga as $no => $status)
                                        @php $urutGiliran = array_search($no, $giliran, true); @endphp
                                        <div class="dbos-unit {{ $status }} {{ $urutGiliran !== false ? 'giliran' : '' }} {{ $urutGiliran === 0 ? 'pertama' : '' }}"
                                             @if ($urutGiliran !== false) style="--mulai: {{ 1.2 + $urutGiliran * 3 }}s" @endif>
                                            {{ $no }}
                                            @if ($urutGiliran !== false)<span class="dbos-ketuk"></span>@endif
                                        </div>
                                    @endforeach
                                </div>
                                <div class="dbos-legenda">
                                    <span class="A">tersedia</span>
                                    <span class="B">booking</span>
                                    <span class="T">terjual</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <main class="dbos-lembar">
                <div class="dbos-lembar-isi">
                    {{ $slot }}
                </div>
                <p class="dbos-kaki">&copy; {{ date('Y') }} PT Langit Membangun Indonesia</p>
            </main>
        </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
