#!/bin/bash
# Runs as root after composer install and before Apache starts, on every build.
# On OSC a restart is a full rebuild, so this runs again after every restart.
set -e
START=$(date +%s)
echo "[setup] starting"

# pdo_pgsql is not in the base php:8.3-apache image. libpq-dev is needed to build it.
if ! php -m | grep -qi '^pdo_pgsql$'; then
  apt-get update -y
  apt-get install -y --no-install-recommends libpq-dev
  docker-php-ext-install pdo_pgsql
fi
echo "[setup] pdo_pgsql ready after $(( $(date +%s) - START ))s"

# phpredis provides the "redis" session save handler (works with Valkey).
if ! php -m | grep -qi '^redis$'; then
  # The empty answers skip the optional igbinary, lzf, zstd, msgpack and lz4 prompts.
  printf '\n\n\n\n\n\n' | pecl install redis
  docker-php-ext-enable redis
fi
echo "[setup] redis extension ready after $(( $(date +%s) - START ))s"

# Option B for Valkey sessions: a php.ini drop-in written from env vars, no code change needed.
# The values come from the bound OSC parameter store.
if [ "$SESSION_INI_MODE" = "redis" ] && [ -n "$VALKEY_HOST" ] && [ -n "$VALKEY_PORT" ]; then
  INI=/usr/local/etc/php/conf.d/zz-session-redis.ini
  AUTH=""
  if [ -n "$VALKEY_PASSWORD" ]; then AUTH="auth=${VALKEY_PASSWORD}&"; fi
  {
    echo "session.save_handler = redis"
    echo "session.save_path = \"tcp://${VALKEY_HOST}:${VALKEY_PORT}?${AUTH}prefix=PHPSESS_INI_\""
    echo "session.gc_maxlifetime = 86400"
  } > "$INI"
  chmod 644 "$INI"
  echo "[setup] wrote $INI (values not printed)"
else
  echo "[setup] no session ini written (SESSION_INI_MODE is not redis, or VALKEY_HOST or VALKEY_PORT is missing)"
fi

# Session table and upload bucket (idempotent).
php "$(dirname "$0")/bin/bootstrap.php"

date -u +"%Y-%m-%dT%H:%M:%SZ" > /tmp/osc-setup-ran
echo "[setup] done in $(( $(date +%s) - START ))s"
