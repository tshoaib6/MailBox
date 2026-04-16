<?php

use Illuminate\Console\Scheduling\Schedule;

/*
|--------------------------------------------------------------------------
| Campaign Dispatch Schedule
|--------------------------------------------------------------------------
|
| This schedule ensures campaigns are automatically dispatched every minute.
| The CampaignDispatchService handles atomic claiming to prevent double-dispatch.
|
*/

return function (Schedule $schedule) {
    // Dispatch queued campaigns every minute with automatic 1-minute gaps between emails
    $schedule->command('sp:campaigns:dispatch')
        ->everyMinute()
        ->withoutOverlapping()
        ->appendOutputTo(storage_path('logs/campaign-dispatch.log'));
};
