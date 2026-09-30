<?php

namespace App\Support;

use App\Enums\BroadcastIneligibilityReason;
use App\Enums\CommunicationChannel;

final class BroadcastEligibilityResult
{
    /**
     * @param  list<BroadcastIneligibilityReason>  $reasons
     */
    public function __construct(
        public readonly CommunicationChannel $channel,
        public readonly array $reasons,
    ) {}

    public function eligible(): bool
    {
        return $this->reasons === [];
    }

    public function firstReason(): ?BroadcastIneligibilityReason
    {
        return $this->reasons[0] ?? null;
    }

    public function has(BroadcastIneligibilityReason $reason): bool
    {
        return in_array($reason, $this->reasons, true);
    }
}
