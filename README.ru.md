[English](README.md) | **Русский**

# mercurius-media-video

Небольшой HTTP-сервис вокруг ffmpeg на [AMPHP](https://amphp.org): кадры и звук видео, конвертация аудио. Модуль
[Меркурия](https://github.com/opencck/mercurius), работает и сам по себе. Когда модуль запущен, агент превращает видео в
кадры и речь для моделей, которые не смотрят видео. Без модуля агент тоже работает — просто без инструмента для видео.

## API

Все пути, кроме `/health`, требуют `Authorization: Bearer <MEDIA_VIDEO_TOKEN>`. Файл — в теле запроса.

| Путь | Ответ |
|---|---|
| `GET /health` | `{"status":"ok","module":"media-video"}` |
| `POST /frames?count=8` | JSON `{"seconds": 12.5, "frames": [JPEG в base64, …], "audio": M4A в base64 или null}`. До 32 кадров по всему видео, ширина 768 px; звук — моно 16 кГц |
| `POST /audio?format=mp3` | звук в `mp3`, `wav`, `ogg` или `m4a`, моно 16 кГц |

Ошибки: `401` без токена, `413` — файл больше предела, `422` — ffmpeg не прочитал файл (с `error`), `500` — без
подробностей.

ffmpeg запускается без оболочки, в два потока и с пределом времени; у каждого запроса своя временная папка, она
удаляется после ответа.

## Настройки

| Переменная | По умолчанию | Смысл |
|---|---|---|
| `MEDIA_VIDEO_TOKEN` | — | обязательна, от 16 символов |
| `MEDIA_VIDEO_PORT` | `8091` | порт |
| `MEDIA_VIDEO_HOST` | `0.0.0.0` | адрес |
| `MEDIA_VIDEO_MAX_MB` | `25` | наибольший файл |
| `MEDIA_VIDEO_TIMEOUT` | `120` | секунд на один запуск ffmpeg |

## Запуск

```sh
docker build -t mercurius-media-video .
docker run --rm -e MEDIA_VIDEO_TOKEN=$(openssl rand -hex 32) -p 8091:8091 mercurius-media-video
```

В Меркурии сервис — в профиле compose `media-video`: добавьте его в `COMPOSE_PROFILES` и задайте `MEDIA_VIDEO_TOKEN` в
`.env`.

## Разработка

```sh
composer install
composer check   # PHPUnit, PHPStan, Psalm
```

Тесты ffmpeg идут там, где он установлен (например, в образе), и пропускаются в остальных местах.

## Лицензия

MIT
