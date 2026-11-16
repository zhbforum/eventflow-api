#!/bin/sh

set -eu

apk add --no-cache openssl >/dev/null

mkdir -p /tls

if [ ! -s /tls/ca.crt ] \
   || [ ! -s /tls/server.key ] \
   || ! openssl x509 \
        -in /tls/server.crt \
        -noout \
        -checkend 86400 >/dev/null 2>&1; then

  echo "Generating test TLS certificates"

  openssl req \
    -x509 \
    -newkey rsa:2048 \
    -sha256 \
    -nodes \
    -days 90 \
    -keyout /tls/ca.key \
    -out /tls/ca.crt \
    -subj '/CN=EventFlow-Test-CA' \
    -addext 'basicConstraints=critical,CA:TRUE' \
    -addext 'keyUsage=critical,keyCertSign,cRLSign'

  openssl req \
    -newkey rsa:2048 \
    -nodes \
    -keyout /tls/server.key \
    -out /tls/server.csr \
    -subj '/CN=webhook-receiver'

  printf '%s\n' \
    'subjectAltName=DNS:webhook-receiver' \
    'extendedKeyUsage=serverAuth' \
    > /tls/server.ext

  openssl x509 \
    -req \
    -in /tls/server.csr \
    -CA /tls/ca.crt \
    -CAkey /tls/ca.key \
    -CAcreateserial \
    -out /tls/server.crt \
    -days 90 \
    -sha256 \
    -extfile /tls/server.ext
fi

exec node /app/tests/Fixtures/https-receiver/server.mjs
