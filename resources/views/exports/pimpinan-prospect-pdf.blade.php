<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Laporan Prospect Grup — {{ $grup->nama }}</title>
    <style>
        body { font-family: sans-serif; font-size: 9px; color: #1a1a1a; }
        h1 { font-size: 16px; margin: 0; }
        h2 { font-size: 11px; margin: 4px 0 12px; color: #666; font-weight: normal; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th { background: #f59e0b; color: white; padding: 5px; text-align: left; font-size: 8px; text-transform: uppercase; }
        td { border-bottom: 1px solid #e5e5e5; padding: 5px; vertical-align: top; }
        .small { font-size: 8px; color: #888; }
        .footer { margin-top: 16px; font-size: 8px; color: #999; text-align: right; }
        .badge { padding: 1px 4px; border-radius: 8px; font-size: 7px; font-weight: bold; text-transform: uppercase; }
        .b-cold { background: #dbeafe; color: #1d4ed8; }
        .b-warm { background: #fef3c7; color: #b45309; }
        .b-hot { background: #fee2e2; color: #b91c1c; }
        .b-finish { background: #d1fae5; color: #047857; }
        .b-archive { background: #e5e5e5; color: #525252; }
    </style>
</head>
<body>
    <h1>Laporan Prospect Grup</h1>
    <h2>{{ $grup->nama }} · {{ now()->translatedFormat('l, d F Y · H:i') }} · {{ $rows->count() }} prospect</h2>

    <table>
        <thead>
            <tr>
                <th>Customer</th>
                <th>HP</th>
                <th>Sales</th>
                <th>Proyek</th>
                <th>Sumber</th>
                <th>Status</th>
                <th>Tanggal</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $r)
                <tr>
                    <td>
                        <strong>{{ $r->nama_lengkap }}</strong>
                        @if ($r->nik)
                            <div class="small">{{ $r->nik }}</div>
                        @endif
                    </td>
                    <td>{{ $r->hp }}</td>
                    <td>{{ $r->sales?->nama ?? '—' }}</td>
                    <td>{{ $r->proyek?->nama_proyek ?? '—' }}</td>
                    <td>{{ $r->sumber }}</td>
                    <td>
                        <span class="badge b-{{ $r->status }}">{{ strtoupper($r->status) }}</span>
                    </td>
                    <td class="small">{{ $r->created_at?->translatedFormat('d M Y') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        Dicetak oleh {{ $pimpinanNama }} pada {{ now()->translatedFormat('d F Y H:i') }}
    </div>
</body>
</html>
