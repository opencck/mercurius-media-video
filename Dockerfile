# media-video: ffmpeg behind a small HTTP API (a module of the Mercurius agent).
FROM php:8.2-cli-bookworm AS build
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN apt-get update && apt-get install -y --no-install-recommends git unzip && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY composer.json ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --no-scripts
COPY src ./src
COPY bin ./bin

FROM php:8.2-cli-bookworm
RUN apt-get update \
    && apt-get install -y --no-install-recommends ffmpeg \
    && docker-php-ext-install pcntl \
    && rm -rf /var/lib/apt/lists/* \
    && useradd --system --uid 10040 --home /app media \
    && echo 'memory_limit=512M' > /usr/local/etc/php/conf.d/media.ini
WORKDIR /app
COPY --from=build /app /app
USER media
EXPOSE 8091
HEALTHCHECK --interval=30s --timeout=5s --retries=3 CMD php -r 'exit(@file_get_contents("http://127.0.0.1:8091/health") === false ? 1 : 0);'
CMD ["php", "bin/media-video"]
