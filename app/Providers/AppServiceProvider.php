<?php

namespace App\Providers;

use App\Models\Master\ProspectCustomer;
use App\Models\Master\Rumah;
use App\Models\Master\TipeRumah;
use App\Observers\ProspectCustomerObserver;
use App\Observers\RumahObserver;
use App\Observers\TipeRumahObserver;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Rumah::observe(RumahObserver::class);
        TipeRumah::observe(TipeRumahObserver::class);
        ProspectCustomer::observe(ProspectCustomerObserver::class);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // Format tanggal pakai bahasa Indonesia ("Senin", "Februari", dll)
        Carbon::setLocale('id');
        CarbonImmutable::setLocale('id');

        $this->registerWorkingDayMacros();

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Macro hari kerja (skip Sabtu & Minggu, libur nasional belum dihandle).
     *
     * - $date->addWorkingDays(N) → tambah N hari kerja, skip weekend
     * - $date->isWorkingDay()    → true kalau bukan Sabtu/Minggu
     * - $date->nextWorkingDay()  → tanggal hari kerja berikutnya (besok kalau bukan weekend)
     */
    protected function registerWorkingDayMacros(): void
    {
        $addWorkingDays = function (int $days) {
            $date = $this->copy();
            while ($days > 0) {
                $date = $date->addDay();
                if (! $date->isWeekend()) {
                    $days--;
                }
            }

            return $date;
        };

        $isWorkingDay = fn () => ! $this->isWeekend();

        $nextWorkingDay = function () {
            $date = $this->copy()->addDay();
            while ($date->isWeekend()) {
                $date = $date->addDay();
            }

            return $date;
        };

        Carbon::macro('addWorkingDays', $addWorkingDays);
        Carbon::macro('isWorkingDay', $isWorkingDay);
        Carbon::macro('nextWorkingDay', $nextWorkingDay);

        CarbonImmutable::macro('addWorkingDays', $addWorkingDays);
        CarbonImmutable::macro('isWorkingDay', $isWorkingDay);
        CarbonImmutable::macro('nextWorkingDay', $nextWorkingDay);
    }
}
