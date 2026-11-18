<?php

declare(strict_types=1);

use App\Jobs\DispatchOutboxMessagesJob;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new DispatchOutboxMessagesJob(batchSize: 100))->everyMinute()->withoutOverlapping();
