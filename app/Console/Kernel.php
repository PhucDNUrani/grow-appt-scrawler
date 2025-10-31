<?php

namespace App\Console;

use App\Console\Commands\CrawlCustomers;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array<class-string>
     */
    protected $commands = [
        CrawlCustomers::class,
    ];

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Define scheduled commands here if needed
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        // You can load additional console routes if present
        // $this->load(__DIR__.'/Commands');
    }
}


