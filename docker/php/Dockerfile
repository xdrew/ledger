# Development PHP image. The production multi-stage image (RoadRunner runtime)
# is introduced later in `add-deployment`; this one runs the console app, the
# test suites, and the RoadRunner HTTP worker for local serving.
FROM php:8.5-cli

# ext-sockets: spiral/roadrunner-worker; ext-pcntl: the worker loop's signal handling.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libpq-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql sockets pcntl \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# The RoadRunner server binary (the worker is our PHP script under public/).
COPY --from=ghcr.io/roadrunner-server/roadrunner:2024 /usr/bin/rr /usr/local/bin/rr

ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app
