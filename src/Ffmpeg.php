<?php

declare(strict_types=1);

namespace Mercurius\MediaVideo;

use Amp\Process\Process;
use Amp\TimeoutCancellation;
use Amp\ByteStream\ReadableStream;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Future;

use function Amp\ByteStream\buffer;
use function Amp\File\createDirectoryRecursively;
use function Amp\File\deleteDirectory;
use function Amp\File\deleteFile;
use function Amp\File\exists;
use function Amp\File\listFiles;
use function Amp\File\read;
use function Amp\File\write;

/**
 * ffmpeg and ffprobe as child processes: arguments as a list (no shell), two threads (a host may limit processes and
 * ffmpeg on many cores opens dozens), a time limit; every request in its own temporary folder, removed afterwards.
 * Inputs are read with `-protocol_whitelist file`: a playlist inside a file cannot make ffmpeg go to the network.
 */
final class Ffmpeg implements Transcoder {
    public const FORMATS = ['mp3' => ['libmp3lame', 'mp3'], 'wav' => ['pcm_s16le', 'wav'], 'ogg' => ['libopus', 'ogg'], 'm4a' => ['aac', 'mp4']];

    public function __construct(
        private readonly string $ffmpeg = 'ffmpeg',
        private readonly string $ffprobe = 'ffprobe',
        private readonly float $timeout = 120.0,
        private readonly ?string $tmp = null,
    ) {}

    public function frames(string $video, int $count): VideoParts {
        $count = max(1, min(32, $count));
        return $this->inFolder(function (string $dir) use ($video, $count): VideoParts {
            $input = $dir . '/input';
            write($input, $video);
            $seconds = $this->seconds($input);
            $this->run([$this->ffmpeg, '-nostdin', '-v', 'error', '-threads', '2', '-filter_threads', '1', '-y', '-protocol_whitelist', 'file', '-i', $input,
                '-vf', sprintf('fps=%d/%d,scale=768:-2', $count, max(1, (int) ceil($seconds))), '-frames:v', (string) $count, $dir . '/frame-%02d.jpg']);
            $frames = [];
            $names = listFiles($dir);
            sort($names);
            foreach ($names as $name) {
                if (str_starts_with($name, 'frame-')) {
                    $frames[] = read($dir . '/' . $name);
                }
            }
            if ($frames === []) {
                throw new MediaException('no frames: not a video ffmpeg reads');
            }
            $audio = null;
            try {
                $this->run([$this->ffmpeg, '-nostdin', '-v', 'error', '-threads', '2', '-y', '-protocol_whitelist', 'file', '-i', $input, '-vn', '-ac', '1', '-ar', '16000', '-b:a', '48k', '-f', 'mp4', $dir . '/audio.m4a']);
                $audio = exists($dir . '/audio.m4a') ? read($dir . '/audio.m4a') : null;
            } catch (MediaException) {
                // a video without sound
            }
            return new VideoParts($seconds, $frames, $audio === '' ? null : $audio);
        });
    }

    public function audio(string $input, string $format): string {
        [$codec, $container] = self::FORMATS[$format] ?? throw new MediaException('format is one of: ' . implode(', ', array_keys(self::FORMATS)));
        return $this->inFolder(function (string $dir) use ($input, $codec, $container): string {
            write($dir . '/input', $input);
            $this->run([$this->ffmpeg, '-nostdin', '-v', 'error', '-threads', '2', '-y', '-protocol_whitelist', 'file', '-i', $dir . '/input', '-vn', '-ac', '1', '-ar', '16000', '-c:a', $codec, '-f', $container, $dir . '/output']);
            return read($dir . '/output');
        });
    }

    /** The length of the media in seconds; 1 when ffprobe cannot tell. */
    private function seconds(string $input): float {
        try {
            $out = $this->run([$this->ffprobe, '-v', 'error', '-protocol_whitelist', 'file', '-show_entries', 'format=duration', '-of', 'default=noprint_wrappers=1:nokey=1', $input]);
        } catch (MediaException) {
            return 1.0;
        }
        $seconds = (float) trim($out);
        return $seconds > 0 ? $seconds : 1.0;
    }

    /**
     * @template T
     * @param \Closure(string): T $work
     * @return T
     */
    private function inFolder(\Closure $work): mixed {
        $dir = rtrim($this->tmp ?? sys_get_temp_dir(), '/\\') . '/media-video-' . bin2hex(random_bytes(8));
        createDirectoryRecursively($dir, 0700);
        try {
            return $work($dir);
        } finally {
            foreach (listFiles($dir) as $name) {
                deleteFile($dir . '/' . $name);
            }
            deleteDirectory($dir);
        }
    }

    /**
     * What a background read gave; a Future of a first-class callable loses its type for the analysers.
     * @param Future<mixed> $future
     */
    private static function text(Future $future): string {
        /** @var mixed $value */
        $value = $future->await();
        return is_string($value) ? $value : '';
    }

    private static function read(ReadableStream $stream, Cancellation $cancellation): string {
        return buffer($stream, $cancellation);
    }

    /**
     * @param list<string> $command
     * @return string what it printed
     */
    private function run(array $command): string {
        $process = Process::start($command);
        $cancellation = new TimeoutCancellation($this->timeout);
        try {
            $stderr = \Amp\async(self::read(...), $process->getStderr(), $cancellation);
            $out = self::read($process->getStdout(), $cancellation);
            $code = $process->join($cancellation);
            $err = self::text($stderr);
        } catch (CancelledException) {
            $process->kill();
            throw new MediaException(sprintf('ffmpeg took longer than %d s', (int) $this->timeout));
        }
        if ($code !== 0) {
            throw new MediaException(mb_substr(trim($err) ?: 'ffmpeg failed', 0, 300));
        }
        return $out;
    }
}
