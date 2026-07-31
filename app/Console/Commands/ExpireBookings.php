<?php

namespace App\Console\Commands;

use App\Models\Master\Booking;
use App\Models\Master\Rumah;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ExpireBookings extends Command
{
    protected $signature = 'bookings:expire';

    protected $description = 'Lepas unit dari booking yang sudah lewat tanggal_expired (status tetap aktif, unit balik ke available)';

    public function handle(): int
    {
        $now = Carbon::now();

        $stale = Booking::query()
            ->where('status', 'aktif')
            ->whereNotNull('tanggal_expired')
            ->where('tanggal_expired', '<=', $now)
            ->whereNull('unit_dilepas_at')
            ->get();

        if ($stale->isEmpty()) {
            $this->info('Tidak ada booking yang perlu di-expire.');
            return self::SUCCESS;
        }

        $count = 0;
        DB::transaction(function () use ($stale, &$count) {
            foreach ($stale as $booking) {
                // unit_dilepas_at = tanggal_expired itself (tanggal unit conceptually dilepas)
                $booking->update([
                    'unit_dilepas_at' => $booking->tanggal_expired->toDateString(),
                ]);

                // Lepas unit kembali ke available, hanya kalau status rumah masih 'booking'
                if ($booking->rumah && $booking->rumah->status === 'booking') {
                    Rumah::where('id', $booking->rumah_id)->update(['status' => 'available']);
                }

                $count++;
            }
        });

        $this->info("Berhasil expire {$count} booking.");
        return self::SUCCESS;
    }
}
