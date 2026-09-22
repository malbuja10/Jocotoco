<?php

declare(strict_types=1);

namespace Jocotoco\Audio;

final class StoreRecordingResult
{
    public function __construct(
        public readonly Recording $recording,
        public readonly bool $duplicate,
    ) {
    }
}
