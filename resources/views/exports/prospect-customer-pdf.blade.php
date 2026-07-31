<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Prospect Customer — {{ now()->format('d M Y') }}</title>
    <style>
        @page { margin: 25px; }
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 9px; color: #18181b; }
        .header { border-bottom: 2px solid #ea580c; padding-bottom: 10px; margin-bottom: 12px; }
        .header h1 { font-size: 16px; margin: 0; color: #ea580c; }
        .meta { font-size: 8px; color: #71717a; margin-top: 4px; }
        .summary { background: #fafafa; padding: 8px 10px; margin-bottom: 12px; font-size: 8px; border-left: 3px solid #ea580c; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #ea580c; color: white; padding: 6px 4px; text-align: left; font-size: 8px; text-transform: uppercase; }
        td { padding: 5px 4px; border-bottom: 1px solid #e4e4e7; font-size: 8px; vertical-align: top; }
        tr:nth-child(even) td { background: #fafafa; }
        .status { display: inline-block; padding: 1px 5px; border-radius: 3px; font-size: 7px; font-weight: bold; text-transform: uppercase; }
        .status-cold { background: #dbeafe; color: #1d4ed8; }
        .status-warm { background: #fef3c7; color: #b45309; }
        .status-hot { background: #fee2e2; color: #b91c1c; }
        .status-finish { background: #d1fae5; color: #047857; }
        .status-archive { background: #f1f5f9; color: #475569; }
        .footer { position: fixed; bottom: 10px; left: 25px; right: 25px; font-size: 7px; color: #a1a1aa; border-top: 1px solid #e4e4e7; padding-top: 6px; display: flex; justify-content: space-between; }
        .pagenum:before { content: counter(page); }
    </style>
</head>
<body>

<div class="header">
    <h1>Database Prospect Customer</h1>
    <div class="meta">
        Dicetak: {{ now()->translatedFormat('d F Y, H:i') }}
        @if (! empty($filterLabel))
            · Filter: {{ $filterLabel }}
        @endif
    </div>
</div>

<div class="summary">
    Total <strong>{{ $rows->count() }}</strong> prospect customer
    @if (! empty($filterLabel)) (sesuai filter) @endif
</div>

<table>
    <thead>
        <tr>
            <th style="width: 3%;">#</th>
            <th style="width: 16%;">Nama</th>
            <th style="width: 11%;">NIK</th>
            <th style="width: 11%;">HP / WA</th>
            <th style="width: 11%;">Sales</th>
            <th style="width: 12%;">Proyek</th>
            <th style="width: 10%;">Sumber</th>
            <th style="width: 7%;">Status</th>
            <th style="width: 9%;">Dibuat</th>
            <th style="width: 10%;">Catatan</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($rows as $i => $row)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td>
                    <strong>{{ $row->nama_lengkap }}</strong>
                </td>
                <td style="font-family: monospace; font-size: 7px;">{{ $row->nik ?: '—' }}</td>
                <td style="font-family: monospace; font-size: 7px;">{{ $row->hp }}</td>
                <td>
                    {{ $row->sales?->nama ?? '—' }}
                    @if ($row->sales)
                        <br><span style="font-size: 7px; color: #71717a;">#{{ $row->sales->kode }}</span>
                    @endif
                </td>
                <td>{{ $row->proyek?->nama_proyek ?? '—' }}</td>
                <td>{{ $row->sumber }}</td>
                <td>
                    <span class="status status-{{ $row->status }}">{{ strtoupper($row->status) }}</span>
                </td>
                <td>{{ $row->created_at?->translatedFormat('d M Y') ?? '—' }}</td>
                <td style="font-size: 7px;">{{ \Illuminate\Support\Str::limit($row->catatan ?? '', 60) }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="10" style="text-align: center; padding: 30px; color: #a1a1aa;">
                    Tidak ada data prospect customer.
                </td>
            </tr>
        @endforelse
    </tbody>
</table>

<div class="footer">
    <span>PT Langit Membangun Indonesia · ERP-LMI</span>
    <span>Halaman <span class="pagenum"></span></span>
</div>

</body>
</html>
