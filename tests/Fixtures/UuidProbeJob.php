<?php

namespace Tests\Fixtures;

use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class UuidProbeJob implements ShouldQueue
{
    use Batchable, Queueable;

    public function handle(): void
    {
        Cache::store('array')->put('uuid-job-executed', true, 60);
    }
}
