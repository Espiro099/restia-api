#!/usr/bin/env bash
# Certificado autofirmado para el proxy TLS local (Actividad 3.2). RSA 2048 con SHA-256, válido 825 días,
# para localhost, 127.0.0.1 y 10.0.2.2 (la PC vista desde el emulador de Android). La llave privada nunca
# se versiona (certs/ está en .gitignore). Imprime la huella SPKI que ancla la app Android.
set -euo pipefail
cd "$(dirname "$0")"
mkdir -p certs logs

MSYS_NO_PATHCONV=1 openssl req -x509 -newkey rsa:2048 -sha256 -days 825 -nodes \
  -keyout certs/restia.key -out certs/restia.crt \
  -subj "/C=MX/ST=Jalisco/L=Guadalajara/O=Restia (desarrollo)/CN=localhost" \
  -addext "subjectAltName=DNS:localhost,IP:127.0.0.1,IP:10.0.2.2" \
  -addext "keyUsage=critical,digitalSignature,keyEncipherment" \
  -addext "extendedKeyUsage=serverAuth"

echo "Certificado: $(pwd)/certs/restia.crt"
openssl x509 -in certs/restia.crt -noout -subject -dates -ext subjectAltName
echo -n "Huella SPKI (sha256, base64) para el anclaje: "
openssl x509 -in certs/restia.crt -pubkey -noout | openssl pkey -pubin -outform der | openssl dgst -sha256 -binary | openssl enc -base64
