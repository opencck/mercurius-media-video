<?php

declare(strict_types=1);

namespace Mercurius\MediaVideo;

/** Frames of a video (JPEG bytes, in order), its sound (M4A bytes; null — none) and its length in seconds. */
final class VideoParts {
    /** @param list<string> $frames */
    public function __construct(
        public readonly float $seconds,
        public readonly array $frames,
        public readonly ?string $audio,
    ) {}
}
