<?php

declare(strict_types=1);

namespace Mercurius\MediaVideo\Test;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\PHPUnit\AsyncTestCase;
use League\Uri\Http;
use Mercurius\MediaVideo\MediaException;
use Mercurius\MediaVideo\Server;
use Mercurius\MediaVideo\Transcoder;
use Mercurius\MediaVideo\VideoParts;
use Psr\Log\NullLogger;

use function Amp\ByteStream\buffer;

final class ServerTest extends AsyncTestCase {
    private const TOKEN = 'token-0123456789abcdef';

    public function testHealthIsOpen(): void {
        $response = $this->server()->respond($this->request('/health', '', null, 'GET'));
        self::assertSame(200, $response->getStatus());
        self::assertSame('ok', self::json($response)['status']);
    }

    public function testWithoutTokenIsRefused(): void {
        self::assertSame(401, $this->server()->respond($this->request('/frames', 'video', null))->getStatus());
        self::assertSame(401, $this->server()->respond($this->request('/frames', 'video', 'wrong-token-0123456789'))->getStatus());
    }

    public function testUnknownPathIsNotFound(): void {
        self::assertSame(404, $this->server()->respond($this->request('/shell', 'x'))->getStatus());
        self::assertSame(404, $this->server()->respond($this->request('/frames', '', self::TOKEN, 'GET'))->getStatus());
    }

    public function testEmptyBodyIsRefused(): void {
        self::assertSame(400, $this->server()->respond($this->request('/frames', ''))->getStatus());
    }

    public function testTooLargeBodyIsRefused(): void {
        $response = (new Server(new FakeTranscoder(), self::TOKEN, new NullLogger(), 10))->respond($this->request('/frames', str_repeat('v', 11)));
        self::assertSame(413, $response->getStatus());
    }

    public function testFramesComeAsBase64(): void {
        $fake = new FakeTranscoder();
        $response = $this->server($fake)->respond($this->request('/frames?count=3', 'video-bytes'));
        self::assertSame(200, $response->getStatus());
        $json = self::json($response);
        self::assertSame(12.5, $json['seconds']);
        self::assertSame([base64_encode('f1'), base64_encode('f2')], $json['frames']);
        self::assertSame(base64_encode('sound'), $json['audio']);
        self::assertSame(['video-bytes', 3], $fake->framesCall);
    }

    public function testFramesCountDefaultsToEight(): void {
        $fake = new FakeTranscoder();
        $this->server($fake)->respond($this->request('/frames?count=x', 'v'));
        self::assertSame(['v', 8], $fake->framesCall);
    }

    public function testAudioReturnsBytes(): void {
        $fake = new FakeTranscoder();
        $response = $this->server($fake)->respond($this->request('/audio?format=wav', 'in'));
        self::assertSame(200, $response->getStatus());
        self::assertSame('wav:in', buffer($response->getBody()));
    }

    public function testMediaErrorIsUnprocessable(): void {
        $fake = new FakeTranscoder();
        $fake->fail = new MediaException('not a video');
        $response = $this->server($fake)->respond($this->request('/frames', 'x'));
        self::assertSame(422, $response->getStatus());
        self::assertSame('not a video', self::json($response)['error']);
    }

    public function testOtherErrorHidesDetails(): void {
        $fake = new FakeTranscoder();
        $fake->fail = new \RuntimeException('/tmp/secret path');
        $response = $this->server($fake)->respond($this->request('/frames', 'x'));
        self::assertSame(500, $response->getStatus());
        self::assertSame('internal error', self::json($response)['error']);
    }

    private function server(?Transcoder $transcoder = null): Server {
        return new Server($transcoder ?? new FakeTranscoder(), self::TOKEN, new NullLogger());
    }

    /** @param non-empty-string $method */
    private function request(string $path, string $body, ?string $token = self::TOKEN, string $method = 'POST'): Request {
        return new Request(
            $this->createMock(Client::class),
            $method,
            Http::new('http://media-video:8091' . $path),
            $token === null ? [] : ['authorization' => 'Bearer ' . $token],
            $body,
        );
    }

    /** @return array<string, mixed> */
    private static function json(Response $response): array {
        $decoded = json_decode(buffer($response->getBody()), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        return array_combine(array_map('strval', array_keys($decoded)), array_values($decoded));
    }
}

final class FakeTranscoder implements Transcoder {
    /** @var array{string, int}|null */
    public ?array $framesCall = null;
    public ?\Throwable $fail = null;

    public function frames(string $video, int $count): VideoParts {
        if ($this->fail !== null) {
            throw $this->fail;
        }
        $this->framesCall = [$video, $count];
        return new VideoParts(12.5, ['f1', 'f2'], 'sound');
    }

    public function audio(string $input, string $format): string {
        if ($this->fail !== null) {
            throw $this->fail;
        }
        return $format . ':' . $input;
    }
}
