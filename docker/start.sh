#!/bin/sh
# Arranque del contenedor de la API (Dockerfile). Con RESTIA_RUN_SCHEDULER=true corre también el programador de
# tareas (impresión, avisos, inventario, reportes, reservaciones y facturación) en el mismo contenedor: sirve
# para el plan gratuito de Render, que no tiene workers. Con un worker aparte, déjalo en false.
set -e

if [ "${RESTIA_RUN_SCHEDULER:-false}" = "true" ]; then
  php artisan schedule:work >/proc/1/fd/1 2>/proc/1/fd/2 &
fi

exec frankenphp run --config /etc/caddy/Caddyfile
