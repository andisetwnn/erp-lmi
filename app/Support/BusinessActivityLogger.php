<?php

namespace App\Support;

use App\Models\Master\Booking;
use App\Models\Master\Rumah;
use App\Models\Master\Spr;
use App\Models\Master\SprRealisasiPembayaran;
use Illuminate\Support\Facades\Auth;

/**
 * Log business events untuk Monitoring page.
 * Kategori: 'penjualan' | 'keuangan'.
 * Event: 'spr.submitted', 'spr.approved', dll — pakai naming subject.action.
 */
class BusinessActivityLogger
{
    /**
     * Label formal untuk event → dipakai di UI (Log User, Monitoring, Notif bell).
     */
    public const EVENT_LABELS = [
        // Penjualan
        'booking.created' => 'Booking Baru',
        'spr.submitted' => 'SPR Diajukan',
        'spr.approved' => 'SPR Disetujui',
        'spr.rejected' => 'SPR Ditolak',
        'spr.cancelled' => 'SPR Dibatalkan',
        'spr.akad' => 'Akad Kredit',
        'spr.switched' => 'Pindah Kavling',
        'spr.swapped' => 'Swap Kavling (2 SPR)',
        'konsumen.signed' => 'Konsumen Tanda Tangan',
        // Keuangan
        'utj.verified' => 'UTJ Diverifikasi',
        'realisasi.created' => 'Realisasi Pembayaran',
        'refund.processed' => 'Refund Diproses',
        'materai.stamped' => 'e-Materai Ditempel',
        // Unit
        'unit.created' => 'Unit Baru',
        'unit.updated' => 'Unit Diubah',
        'unit.status_changed' => 'Status Unit Berubah',
    ];

    /** Konversi event code → label formal Indonesia. */
    public static function labelFor(?string $event): string
    {
        return self::EVENT_LABELS[$event ?? ''] ?? ($event ?? '—');
    }

    /**
     * Format description supaya lebih ringkas untuk display:
     * "SPR/2026/07/0190" → "#00190".
     * Menghandle log historis yang masih pakai format panjang.
     */
    public static function shortenDesc(?string $text): string
    {
        if (! $text) {
            return '';
        }

        // Match "SPR/YYYY/MM/XXXX" (optional # prefix), ganti dengan #XXXX (padded 5 digit).
        // Pakai delimiter ~ supaya karakter # bebas dipakai di dalam pattern tanpa konflik.
        return preg_replace_callback(
            '~#?SPR/\d{4}/\d{2}/(\d+)~',
            fn ($m) => '#'.str_pad($m[1], 5, '0', STR_PAD_LEFT),
            $text,
        );
    }

    public static function bookingCreated(Booking $booking): void
    {
        $unit = $booking->rumah?->blok.'-'.$booking->rumah?->nomor_unit;
        $customer = $booking->prospectCustomer?->nama_lengkap ?? '—';

        activity('penjualan')
            ->causedBy(self::causer())
            ->performedOn($booking)
            ->event('booking.created')
            ->withProperties([
                'unit' => $unit,
                'customer' => $customer,
                'proyek' => $booking->proyek?->nama_proyek,
                'sales' => $booking->sales?->nama,
            ])
            ->log("Booking unit {$unit} untuk {$customer}");
    }

    public static function sprSubmitted(Spr $spr): void
    {
        $unit = $spr->rumah?->blok.'-'.$spr->rumah?->nomor_unit;
        $customer = $spr->prospectCustomer?->nama_lengkap ?? '—';

        activity('penjualan')
            ->causedBy(self::causer())
            ->performedOn($spr)
            ->event('spr.submitted')
            ->withProperties([
                'nomor_spr' => $spr->nomor_spr,
                'unit' => $unit,
                'customer' => $customer,
                'nilai' => (float) $spr->total_harga,
                'sales' => $spr->sales?->nama,
            ])
            ->log("SPR #{$spr->nomor_display} diajukan · {$customer} · unit {$unit}");
    }

    public static function sprApproved(Spr $spr): void
    {
        $customer = $spr->prospectCustomer?->nama_lengkap ?? '—';

        activity('penjualan')
            ->causedBy(self::causer())
            ->performedOn($spr)
            ->event('spr.approved')
            ->withProperties([
                'nomor_spr' => $spr->nomor_spr,
                'customer' => $customer,
                'nilai' => (float) $spr->total_harga,
            ])
            ->log("SPR #{$spr->nomor_display} disetujui · {$customer}");
    }

    public static function sprRejected(Spr $spr, ?string $alasan = null): void
    {
        $customer = $spr->prospectCustomer?->nama_lengkap ?? '—';

        activity('penjualan')
            ->causedBy(self::causer())
            ->performedOn($spr)
            ->event('spr.rejected')
            ->withProperties([
                'nomor_spr' => $spr->nomor_spr,
                'customer' => $customer,
                'alasan' => $alasan,
            ])
            ->log("SPR #{$spr->nomor_display} ditolak · {$customer}");
    }

    public static function sprCancelled(Spr $spr, ?string $alasan = null): void
    {
        $customer = $spr->prospectCustomer?->nama_lengkap ?? '—';

        activity('penjualan')
            ->causedBy(self::causer())
            ->performedOn($spr)
            ->event('spr.cancelled')
            ->withProperties([
                'nomor_spr' => $spr->nomor_spr,
                'customer' => $customer,
                'alasan' => $alasan,
            ])
            ->log("SPR #{$spr->nomor_display} dibatalkan · {$customer}");
    }

    public static function sprAkad(Spr $spr): void
    {
        $customer = $spr->prospectCustomer?->nama_lengkap ?? '—';

        activity('penjualan')
            ->causedBy(self::causer())
            ->performedOn($spr)
            ->event('spr.akad')
            ->withProperties([
                'nomor_spr' => $spr->nomor_spr,
                'customer' => $customer,
                'nilai' => (float) $spr->total_harga,
            ])
            ->log("SPR #{$spr->nomor_display} akad · {$customer}");
    }

    /** SPR di-pindah kavling (1 customer pindah ke unit baru — SPR lama voided, SPR baru dibuat). */
    public static function sprSwitched(Spr $sprLama, Spr $sprBaru, string $alasan, int $userId): void
    {
        $customer = $sprLama->prospectCustomer?->nama_lengkap ?? '—';
        $unitLama = $sprLama->rumah ? "{$sprLama->rumah->blok}-{$sprLama->rumah->nomor_unit}" : '—';
        $sprBaru->loadMissing('rumah');
        $unitBaru = $sprBaru->rumah ? "{$sprBaru->rumah->blok}-{$sprBaru->rumah->nomor_unit}" : '—';

        activity('penjualan')
            ->causedBy($userId)
            ->performedOn($sprBaru)
            ->event('spr.switched')
            ->withProperties([
                'spr_lama' => $sprLama->nomor_spr,
                'spr_baru' => $sprBaru->nomor_spr,
                'unit_lama' => $unitLama,
                'unit_baru' => $unitBaru,
                'customer' => $customer,
                'alasan' => $alasan,
            ])
            ->log("Pindah Kavling: {$customer} unit {$unitLama} → {$unitBaru} (SPR #{$sprLama->nomor_display} → #{$sprBaru->nomor_display})");
    }

    /** Swap 2 SPR (2 customer tukar unit atomic). */
    public static function sprSwapped(Spr $sprA, Spr $sprB, Spr $sprBaruA, Spr $sprBaruB, string $alasan, int $userId): void
    {
        $customerA = $sprA->prospectCustomer?->nama_lengkap ?? '—';
        $customerB = $sprB->prospectCustomer?->nama_lengkap ?? '—';
        $unitA = $sprA->rumah ? "{$sprA->rumah->blok}-{$sprA->rumah->nomor_unit}" : '—';
        $unitB = $sprB->rumah ? "{$sprB->rumah->blok}-{$sprB->rumah->nomor_unit}" : '—';

        activity('penjualan')
            ->causedBy($userId)
            ->performedOn($sprBaruA)
            ->event('spr.swapped')
            ->withProperties([
                'spr_a_lama' => $sprA->nomor_spr,
                'spr_b_lama' => $sprB->nomor_spr,
                'spr_a_baru' => $sprBaruA->nomor_spr,
                'spr_b_baru' => $sprBaruB->nomor_spr,
                'unit_a' => $unitA,
                'unit_b' => $unitB,
                'customer_a' => $customerA,
                'customer_b' => $customerB,
                'alasan' => $alasan,
            ])
            ->log("Swap Kavling: {$customerA} ({$unitA}) ↔ {$customerB} ({$unitB})");
    }

    /**
     * Konsumen tanda tangan digital via public page (no auth).
     * Causer = null (anonymous), tapi kita simpan info konsumen di properties.
     */
    public static function konsumenSigned(Spr $spr): void
    {
        $customer = $spr->prospectCustomer?->nama_lengkap ?? '—';

        activity('penjualan')
            ->causedByAnonymous()
            ->performedOn($spr)
            ->event('konsumen.signed')
            ->withProperties([
                'nomor_spr' => $spr->nomor_spr,
                'customer' => $customer,
                'nilai' => (float) $spr->total_harga,
                'signed_at' => now()->toIso8601String(),
            ])
            ->log("Konsumen {$customer} tanda tangan SPR #{$spr->nomor_display}");
    }

    /**
     * Keuangan tempel e-Materai (finalisasi SPR).
     */
    public static function materaiStamped(Spr $spr): void
    {
        $customer = $spr->prospectCustomer?->nama_lengkap ?? '—';

        activity('keuangan')
            ->causedBy(self::causer())
            ->performedOn($spr)
            ->event('materai.stamped')
            ->withProperties([
                'nomor_spr' => $spr->nomor_spr,
                'customer' => $customer,
                'nilai' => (float) $spr->total_harga,
            ])
            ->log("e-Materai SPR #{$spr->nomor_display} ditempel · {$customer}");
    }

    public static function utjVerified(Spr $spr): void
    {
        $customer = $spr->prospectCustomer?->nama_lengkap ?? '—';
        $nominalFmt = 'Rp '.number_format((float) $spr->utj_nominal, 0, ',', '.');

        activity('keuangan')
            ->causedBy(self::causer())
            ->performedOn($spr)
            ->event('utj.verified')
            ->withProperties([
                'nomor_spr' => $spr->nomor_spr,
                'customer' => $customer,
                'nominal' => (float) $spr->utj_nominal,
            ])
            ->log("UTJ SPR #{$spr->nomor_display} diverifikasi · {$nominalFmt}");
    }

    public static function realisasiCreated(SprRealisasiPembayaran $realisasi): void
    {
        $spr = $realisasi->spr;
        $customer = $spr?->prospectCustomer?->nama_lengkap ?? '—';
        $nominalFmt = 'Rp '.number_format((float) $realisasi->jumlah, 0, ',', '.');
        $jenis = strtoupper($realisasi->jenis);

        activity('keuangan')
            ->causedBy(self::causer())
            ->performedOn($realisasi)
            ->event('realisasi.created')
            ->withProperties([
                'nomor_kwitansi' => $realisasi->nomor_kwitansi,
                'nomor_spr' => $spr?->nomor_spr,
                'customer' => $customer,
                'jenis' => $realisasi->jenis,
                'jumlah' => (float) $realisasi->jumlah,
            ])
            ->log("Realisasi {$jenis} · {$customer} · {$nominalFmt}");
    }

    /**
     * Realisasi pembayaran di-edit (biasanya koreksi typo nominal / tanggal).
     * Old values disimpan di properties supaya bisa di-audit siapa ubah apa dari nilai berapa.
     *
     * @param  array{tanggal_bayar: ?string, jumlah: float, metode: ?string, keterangan: ?string}  $old
     */
    public static function realisasiUpdated(SprRealisasiPembayaran $realisasi, array $old): void
    {
        $realisasi->loadMissing('spr.prospectCustomer');
        $spr = $realisasi->spr;
        $customer = $spr?->prospectCustomer?->nama_lengkap ?? '—';
        $nominalBaru = 'Rp '.number_format((float) $realisasi->jumlah, 0, ',', '.');
        $nominalLama = 'Rp '.number_format((float) $old['jumlah'], 0, ',', '.');
        $jenis = strtoupper($realisasi->jenis);

        activity('keuangan')
            ->causedBy(self::causer())
            ->performedOn($realisasi)
            ->event('realisasi.updated')
            ->withProperties([
                'nomor_kwitansi' => $realisasi->nomor_kwitansi,
                'nomor_spr' => $spr?->nomor_spr,
                'customer' => $customer,
                'jenis' => $realisasi->jenis,
                'old' => $old,
                'new' => [
                    'tanggal_bayar' => $realisasi->tanggal_bayar?->format('Y-m-d'),
                    'jumlah' => (float) $realisasi->jumlah,
                    'metode' => $realisasi->metode,
                    'keterangan' => $realisasi->keterangan,
                ],
            ])
            ->log("Edit realisasi {$jenis} · kwitansi {$realisasi->nomor_kwitansi} · {$customer} · {$nominalLama} → {$nominalBaru}");
    }

    public static function refundProcessed(Spr $spr, float $jumlah): void
    {
        $customer = $spr->prospectCustomer?->nama_lengkap ?? '—';
        $nominalFmt = 'Rp '.number_format($jumlah, 0, ',', '.');

        activity('keuangan')
            ->causedBy(self::causer())
            ->performedOn($spr)
            ->event('refund.processed')
            ->withProperties([
                'nomor_spr' => $spr->nomor_spr,
                'customer' => $customer,
                'jumlah' => $jumlah,
            ])
            ->log("Refund SPR #{$spr->nomor_display} · {$customer} · {$nominalFmt}");
    }

    public static function unitCreated(Rumah $rumah): void
    {
        $rumah->loadMissing('proyek', 'tipeRumah');
        $unit = $rumah->blok.'-'.$rumah->nomor_unit;

        activity('unit')
            ->causedBy(self::causer())
            ->performedOn($rumah)
            ->event('unit.created')
            ->withProperties([
                'unit' => $unit,
                'proyek' => $rumah->proyek?->nama_proyek,
                'tipe' => $rumah->tipeRumah?->tipe,
                'status' => $rumah->status,
            ])
            ->log("Unit baru {$unit} ditambahkan · {$rumah->proyek?->nama_proyek}");
    }

    public static function unitUpdated(Rumah $rumah, array $changes): void
    {
        $rumah->loadMissing('proyek');
        $unit = $rumah->blok.'-'.$rumah->nomor_unit;
        $fields = implode(', ', array_keys($changes));

        activity('unit')
            ->causedBy(self::causer())
            ->performedOn($rumah)
            ->event('unit.updated')
            ->withProperties([
                'unit' => $unit,
                'proyek' => $rumah->proyek?->nama_proyek,
                'changes' => $changes,
            ])
            ->log("Unit {$unit} diubah · field: {$fields}");
    }

    public static function unitStatusChanged(Rumah $rumah, string $from, string $to): void
    {
        $rumah->loadMissing('proyek');
        $unit = $rumah->blok.'-'.$rumah->nomor_unit;

        activity('unit')
            ->causedBy(self::causer())
            ->performedOn($rumah)
            ->event('unit.status_changed')
            ->withProperties([
                'unit' => $unit,
                'proyek' => $rumah->proyek?->nama_proyek,
                'from' => $from,
                'to' => $to,
            ])
            ->log("Unit {$unit} · {$from} → {$to}");
    }

    /**
     * Resolve causer dari guard aktif. Prioritas: web (admin/keuangan/PM/direktur) → sales (DBOS).
     *
     * Alasan web dulu: sebagian besar business event (approve SPR, verifikasi UTJ, tempel materai)
     * dilakukan user web. Kalau dev/tester login ganda (Ana sales + Uli finance di browser sama),
     * jangan sampai event Keuangan tercatat atas nama sales.
     */
    private static function causer()
    {
        if (Auth::guard('web')->check()) {
            return Auth::guard('web')->user();
        }
        if (Auth::guard('sales')->check()) {
            return Auth::guard('sales')->user();
        }

        return null;
    }
}
