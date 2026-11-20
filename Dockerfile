# Imagen de la API para el ambiente de pruebas en Render (docs/Despliegue_Staging.md).
# FrankenPHP sirve public/ con PHP 8.4; el TLS lo pone Render delante, así que aquí solo hay HTTP.
# La misma imagen corre el proceso de tareas (`php artisan schedule:work`) en el servicio worker.
FROM dunglas/frankenphp:1-php8.4-bookworm

RUN install-php-extensions mongodb bcmath intl zip opcache pcntl

# La imagen trae frankenphp con la capacidad cap_net_bind_service (para escuchar en 80/443). Render se niega
# a ejecutar binarios con capacidades ("exec: frankenphp: Operation not permitted"); aquí se escucha en 8080,
# así que no hace falta.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libcap2-bin \
    && setcap -r /usr/local/bin/frankenphp \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Primero las dependencias: se reconstruyen solo si cambia composer.lock.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

COPY . .
RUN composer dump-autoload --no-dev --optimize \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

# Sin host: Caddy escucha HTTP en 8080 y no intenta pedir certificados.
ENV SERVER_NAME=:8080
EXPOSE 8080

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
