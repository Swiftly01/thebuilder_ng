<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Videos\YoutubeSync;
use App\Models\YoutubeChannel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncYoutubeChannel implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(public string $channelId)
    {
        $this->onQueue('youtube');
    }

    /** @return array<array-key, mixed> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(YoutubeSync $sync): void
    {
        $channel = YoutubeChannel::find($this->channelId);
        if ($channel?->active) {
            $run = $sync->sync($channel);
            if ($run->status === 'failed' && in_array($run->error_code, ['provider_unreachable', 'provider_request_failed'], true)) {
                throw new \RuntimeException('YouTube sync temporarily failed.');
            }
        }
    }
}
