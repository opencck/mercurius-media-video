**English** | [Русский](README.ru.md)

# mercurius-media-video

A small HTTP service around ffmpeg on [AMPHP](https://amphp.org): the frames and the sound of a video, and audio
conversion. It is a module of [Mercurius](https://github.com/opencck/mercurius) and can be used on its own. When the
module runs, the agent turns a video into frames and speech for models that do not watch video. Without it the agent
works too, it just has no video tool.

## API

Every route except `/health` needs `Authorization: Bearer <MEDIA_VIDEO_TOKEN>`. The file goes in the request body.

| Route | Answer |
|---|---|
| `GET /health` | `{"status":"ok","module":"media-video"}` |
| `POST /frames?count=8` | JSON `{"seconds": 12.5, "frames": [base64 JPEG, …], "audio": base64 M4A or null}`. Up to 32 frames spread over the video, 768 px wide; the sound is mono 16 kHz |
| `POST /audio?format=mp3` | the sound as `mp3`, `wav`, `ogg` or `m4a`, mono 16 kHz |

Errors: `401` without the token, `413` for a file over the limit, `422` when ffmpeg cannot read the file (with
`error`), `500` without details.

ffmpeg runs without a shell, with two threads and a time limit; each request gets its own temporary folder, which is
removed afterwards.

## Settings

| Variable | Default | Meaning |
|---|---|---|
| `MEDIA_VIDEO_TOKEN` | — | required, 16+ characters |
| `MEDIA_VIDEO_PORT` | `8091` | port |
| `MEDIA_VIDEO_HOST` | `0.0.0.0` | address |
| `MEDIA_VIDEO_MAX_MB` | `25` | the largest file |
| `MEDIA_VIDEO_TIMEOUT` | `120` | seconds for one ffmpeg run |

## Run

```sh
docker build -t mercurius-media-video .
docker run --rm -e MEDIA_VIDEO_TOKEN=$(openssl rand -hex 32) -p 8091:8091 mercurius-media-video
```

In Mercurius the service is in the compose profile `media-video`: add it to `COMPOSE_PROFILES` and set
`MEDIA_VIDEO_TOKEN` in `.env`.

## Development

```sh
composer install
composer check   # PHPUnit, PHPStan, Psalm
```

The ffmpeg tests run where ffmpeg is installed (for example in the image) and are skipped elsewhere.

## License

MIT
