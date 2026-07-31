<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Laporan Booking Grup — {{ $grup->nama }}</title>
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
        .b-aktif { background: #dbeafe; color: #1d4ed8; }
        .b-sukses { background: #f3e8ff; color: #6d28d9; }
        .b-akad { background: #d1fae5; color: #047857; }
        .b-batal { background: #fee2e2; color: #b91c1c; }
    </style>
</head>
<body>
    <h1>Laporan Booking Grup</h1>
    <h2>{{ $grup->nama }} · {{ now()->translatedFormat('l, d F Y · H:i') }} · {{ $bookings->count() }} booking</h2>

    <table>
        <thead>
            <tr>
                <th>Kode</th>
                <th>Customer</th>
                <th>Sales</th>
                <th>Proyek / Unit</th>
                <th>Tgl Booking</th>
                <th>Expired</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($bookings as $b)
                <tr>
                    <td>BK-{{ str_pad((string) $b->id, 5, '0', STR_PAD_LEFT) }}</td>
                    <td>
                        <strong>{{ $b->prospectCustomer?->nama_lengkap ?? '—' }}</strong>
                        @if ($b->prospectCustomer?->hp)
                            <div class="small">{{ $b->prospectCustomer->hp }}</div>
                        @endif
                    </td>
                    <td>{{ $b->sales?->nama ?? '—' }}</td>
                    <td>
                        {{ $b->proyek?->nama_proyek ?? '—' }}
                        @if ($b->rumah)
                            <div class="small">{{ $b->rumah->blok }}-{{ $b->rumah->nomor_unit }}</div>
                        @endif
                    </td>
                    <td class="small">{{ $b->tanggal_booking?->translatedFormat('d M Y') }}</td>
                    <td class="small">{{ $b->tanggal_expired?->translatedFormat('d M Y') ?? '—' }}</td>
                    <td>
                        <span class="badge b-{{ $b->status }}">{{ strtoupper($b->status) }}</span>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        Dicetak oleh {{ $pimpinanNama }} pada {{ now()->translatedFormat('d F Y H:i') }}
    </div>
</body>
</html>
