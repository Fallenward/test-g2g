<?php

namespace App\Providers;
use App\Domain\Ledger\Ledger;
use App\Domain\Ledger\LedgerContract;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(LedgerContract::class, Ledger::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
