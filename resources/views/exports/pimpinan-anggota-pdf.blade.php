<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Laporan Anggota Grup — {{ $grup->nama }}</title>
    <style>
        body { font-family: sans-serif; font-size: 10px; color: #1a1a1a; }
        h1 { font-size: 16px; margin: 0; }
        h2 { font-size: 12px; margin: 4px 0 12px; color: #666; font-weight: normal; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th { background: #f59e0b; color: white; padding: 6px; text-align: left; font-size: 9px; text-transform: uppercase; }
        td { border-bottom: 1px solid #e5e5e5; padding: 6px; vertical-align: top; }
        .num { text-align: right; }
        .small { font-size: 8px; color: #888; }
        .footer { margin-top: 16px; font-size: 8px; color: #999; text-align: right; }
    </style>
</head>
<body>
    <h1>Laporan Anggota Grup</h1>
    <h2>{{ $grup->nama }} · {{ now()->translatedFormat('l, d F Y · H:i') }}</h2>

    <table>
        <thead>
            <tr>
                <th>Kode</th>
                <th>Nama</th>
                <th>Jenis</th>
                <th>Status</th>
                <th class="num">Prospect</th>
                <th class="num">Booking</th>
                <th class="num">SPR</th>
                <th class="num">Akad</th>
                <th class="num">Target P</th>
                <th class="num">Target B</th>
                <th>Login Terakhir</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($anggota as $a)
                <tr>
                    <td>{{ $a->kode }}</td>
                    <td>
                        <strong>{{ $a->nama }}</strong>
                        @if ($a->telepon)
                            <div class="small">{{ $a->telepon }}</div>
                        @endif
                    </td>
                    <td>{{ $a->jenisSales?->nama ?? '—' }}</td>
                    <td>{{ $a->is_aktif ? 'Aktif' : 'Nonaktif' }}</td>
                    <td class="num">{{ $a->stat_prospect }}</td>
                    <td class="num">{{ $a->stat_booking }}</td>
                    <td class="num">{{ $a->stat_sukses }}</td>
                    <td class="num">{{ $a->stat_akad }}</td>
                    <td class="num">{{ $a->prospect_bulan_ini }}/{{ $a->target_prospect ?: '—' }}</td>
                    <td class="num">{{ $a->booking_bulan_ini }}/{{ $a->target_booking ?: '—' }}</td>
                    <td class="small">{{ $a->last_login_at?->translatedFormat('d M Y H:i') ?? 'Belum pernah' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        Dicetak oleh {{ $pimpinanNama }} pada {{ now()->translatedFormat('d F Y H:i') }}
    </div>
</body>
</html>
