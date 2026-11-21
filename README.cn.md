[English](README.md) | [Русский](README.ru.md) | **中文**

> 本文由模型翻译，尚未经人工审校；如有出入，以英文版为准。

# mercurius-media-video

一个基于 [AMPHP](https://amphp.org) 围绕 ffmpeg 构建的小型 HTTP 服务：提取视频的画面帧和声音，并进行音频转换。它是 [Mercurius](https://github.com/opencck/mercurius) 的一个模块，也可以单独使用。模块运行时，智能体会把视频转成画面帧和语音，供不能观看视频的模型使用。没有它智能体照样能工作，
只是没有视频工具。

## API

除 `/health` 外，每个路由都需要 `Authorization: Bearer <MEDIA_VIDEO_TOKEN>`。文件放在请求体中。

| 路由 | 响应 |
|---|---|
| `GET /health` | `{"status":"ok","module":"media-video"}` |
| `POST /frames?count=8` | JSON `{"seconds": 12.5, "frames": [base64 JPEG, …], "audio": base64 M4A or null}`。在视频中均匀取至多 32 帧，宽 768 px；声音为单声道 16 kHz |
| `POST /audio?format=mp3` | 声音，格式为 `mp3`、`wav`、`ogg` 或 `m4a`，单声道 16 kHz |

错误：没有令牌返回 `401`，文件超过限制返回 `413`，ffmpeg 无法读取文件时返回 `422`（附带
`error`），`500` 不附带细节。

ffmpeg 不经过 shell 运行，使用两个线程并有时间限制；每个请求都有自己的临时文件夹，处理完后会被删除。

## 设置

| 变量 | 默认值 | 含义 |
|---|---|---|
| `MEDIA_VIDEO_TOKEN` | — | 必填，至少 16 个字符 |
| `MEDIA_VIDEO_PORT` | `8091` | 端口 |
| `MEDIA_VIDEO_HOST` | `0.0.0.0` | 地址 |
| `MEDIA_VIDEO_MAX_MB` | `25` | 文件的最大大小 |
| `MEDIA_VIDEO_TIMEOUT` | `120` | 单次 ffmpeg 运行的秒数 |

## 运行

```sh
docker build -t mercurius-media-video .
docker run --rm -e MEDIA_VIDEO_TOKEN=$(openssl rand -hex 32) -p 8091:8091 mercurius-media-video
```

在 Mercurius 中，该服务位于 compose 配置 `media-video` 下：把它加入 `COMPOSE_PROFILES`，并在 `.env` 中设置
`MEDIA_VIDEO_TOKEN`。

## 开发

```sh
composer install
composer check   # PHPUnit, PHPStan, Psalm
```

ffmpeg 测试在已安装 ffmpeg 的环境（例如镜像中）运行，其他环境会跳过。

## 许可证

MIT
