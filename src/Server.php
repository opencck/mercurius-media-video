<?php

declare(strict_types=1);

namespace Mercurius\MediaVideo;

use Amp\Http\HttpStatus;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\Socket\InternetAddress;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Throwable;

/**
 * The HTTP side, behind a bearer token: POST /frames?count=N — the video in the body, JSON with the frames (base64
 * JPEG), the sound (base64 M4A or null) and the length; POST /audio?format=mp3|wav|ogg|m4a — the sound converted.
 * GET /health is open. A body larger than the limit is refused before it is read whole.
 */
final class Server {
    public function __construct(
        private readonly Transcoder $transcoder,
        #[SensitiveParameter] private readonly string $token,
        private readonly LoggerInterface $logger,
        private readonly int $maxBytes = 25_000_000,
    ) {}

    public function respond(Request $request): Response {
        $path = $request->getUri()->getPath();
        if ($path === '/health') {
            return self::json(HttpStatus::OK, ['status' => 'ok', 'module' => 'media-video']);
        }
        if ($request->getMethod() !== 'POST' || !in_array($path, ['/frames', '/audio'], true)) {
            return new Response(HttpStatus::NOT_FOUND, [], '');
        }
        if (!hash_equals('Bearer ' . $this->token, trim($request->getHeader('authorization') ?? ''))) {
            return new Response(HttpStatus::UNAUTHORIZED, [], '');
        }
        try {
            $request->getBody()->increaseSizeLimit($this->maxBytes);
            $body = $request->getBody()->buffer(limit: $this->maxBytes);
            if (strlen($body) > $this->maxBytes) {
                throw new MediaException('too large');
            }
        } catch (Throwable) {
            return self::json(HttpStatus::PAYLOAD_TOO_LARGE, ['error' => sprintf('the file is larger than %d MB', intdiv($this->maxBytes, 1_000_000))]);
        }
        if ($body === '') {
            return self::json(HttpStatus::BAD_REQUEST, ['error' => 'the file goes in the body']);
        }
        parse_str($request->getUri()->getQuery(), $query);
        /** @var mixed $count */
        $count = $query['count'] ?? null;
        /** @var mixed $format */
        $format = $query['format'] ?? null;
        try {
            if ($path === '/frames') {
                $parts = $this->transcoder->frames($body, is_numeric($count) ? (int) $count : 8);
                return self::json(HttpStatus::OK, [
                    'seconds' => $parts->seconds,
                    'frames' => array_map('base64_encode', $parts->frames),
                    'audio' => $parts->audio === null ? null : base64_encode($parts->audio),
                ]);
            }
            return new Response(HttpStatus::OK, ['content-type' => 'application/octet-stream'], $this->transcoder->audio($body, is_string($format) ? $format : 'mp3'));
        } catch (MediaException $e) {
            return self::json(HttpStatus::UNPROCESSABLE_ENTITY, ['error' => $e->getMessage()]);
        } catch (Throwable $e) {
            $this->logger->error('media-video failed', ['error' => $e::class]);
            return self::json(HttpStatus::INTERNAL_SERVER_ERROR, ['error' => 'internal error']);
        }
    }

    /** @param int<1, 65535> $port */
    public function start(string $host, int $port): SocketHttpServer {
        $server = SocketHttpServer::createForDirectAccess($this->logger, connectionLimit: 64, connectionLimitPerIp: 64);
        $server->expose(new InternetAddress($host, $port));
        $server->start(new ClosureRequestHandler($this->respond(...)), new DefaultErrorHandler());
        return $server;
    }

    /** @param array<string, mixed> $data */
    private static function json(int $status, array $data): Response {
        return new Response($status, ['content-type' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
