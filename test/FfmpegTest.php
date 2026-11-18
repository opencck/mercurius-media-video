<?php

declare(strict_types=1);

namespace Mercurius\MediaVideo\Test;

use Amp\PHPUnit\AsyncTestCase;
use Amp\Process\Process;
use Mercurius\MediaVideo\Ffmpeg;
use Mercurius\MediaVideo\MediaException;

/**
 * Runs against a real ffmpeg; skipped where there is none (CI keeps to the HTTP side, the Docker image runs these).
 */
final class FfmpegTest extends AsyncTestCase {
    protected function setUp(): void {
        parent::setUp();
        if (!self::hasFfmpeg()) {
            self::markTestSkipped('ffmpeg is not installed');
        }
    }

    public function testFramesAndSoundOfAClip(): void {
        $parts = (new Ffmpeg())->frames(self::clip(), 4);
        self::assertGreaterThan(1.5, $parts->seconds);
        self::assertNotEmpty($parts->frames);
        self::assertLessThanOrEqual(4, count($parts->frames));
        self::assertStringStartsWith("\xFF\xD8", $parts->frames[0]);
        self::assertNotNull($parts->audio);
    }

    public function testAudioConversion(): void {
        $wav = (new Ffmpeg())->audio(self::clip(), 'wav');
        self::assertStringStartsWith('RIFF', $wav);
    }

    public function testNotAVideo(): void {
        $this->expectException(MediaException::class);
        (new Ffmpeg())->frames('plain text, not a video', 2);
    }

    public function testAPlaylistDoesNotMakeFfmpegGoToTheNetwork(): void {
        // a server on the loopback: a playlist names it, ffmpeg must not connect
        $server = \Amp\Socket\listen('127.0.0.1:0');
        $address = $server->getAddress();
        self::assertInstanceOf(\Amp\Socket\InternetAddress::class, $address);
        $came = false;
        \Amp\async(static function () use ($server, &$came): void {
            if ($server->accept() !== null) {
                $came = true;
            }
        })->ignore();
        $playlist = "#EXTM3U\n#EXT-X-TARGETDURATION:1\n#EXTINF:1,\nhttp://127.0.0.1:" . $address->getPort() . "/part.ts\n#EXT-X-ENDLIST\n";
        try {
            (new Ffmpeg())->audio($playlist, 'wav');
        } catch (MediaException) {
            // nothing to read: that is the point
        }
        \Amp\delay(0.2);
        $server->close();
        self::assertFalse($came, 'ffmpeg reads files only');
    }

    public function testUnknownFormat(): void {
        $this->expectException(MediaException::class);
        (new Ffmpeg())->audio('x', 'exe');
    }

    private static function hasFfmpeg(): bool {
        try {
            return Process::start(['ffmpeg', '-version'])->join() === 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /** A two-second test clip with a tone, made by ffmpeg itself. */
    private static function clip(): string {
        $file = sys_get_temp_dir() . '/media-video-clip-' . bin2hex(random_bytes(4)) . '.mp4';
        $process = Process::start(['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc=duration=2:size=320x240:rate=10',
            '-f', 'lavfi', '-i', 'sine=frequency=440:duration=2', '-pix_fmt', 'yuv420p', '-shortest', $file]);
        self::assertSame(0, $process->join());
        $bytes = (string) file_get_contents($file);
        unlink($file);
        return $bytes;
    }
}
