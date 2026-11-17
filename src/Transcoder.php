<?php

declare(strict_types=1);

namespace Mercurius\MediaVideo;

/**
 * What the service does with a media file: frames and sound of a video, audio in another format.
 */
interface Transcoder {
    /**
     * Up to $count frames evenly over the video (JPEG, 768 px wide) and its sound (M4A, mono 16 kHz; null — none).
     *
     * @throws MediaException the file is not a video ffmpeg reads, or it took too long
     */
    public function frames(string $video, int $count): VideoParts;

    /**
     * The sound of an audio or video file in $format (mp3, wav, ogg, m4a), mono 16 kHz — what speech models take.
     *
     * @throws MediaException
     */
    public function audio(string $input, string $format): string;
}
