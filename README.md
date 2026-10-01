# osc-php-persistence-test

Example PHP app for an Eyevinn Open Source Cloud (OSC) PHP My App that keeps
user sessions and uploaded files across restarts and redeploys.

A My App has no persistent disk: every restart is a full rebuild on a fresh
container, so PHP's default file sessions and anything written to local disk
are lost. This app shows three ways around that:

| Route | What it stores | Where |
|---|---|---|
| `/valkey/login`, `/valkey/me` | PHP session | Valkey, phpredis handler set with `ini_set` |
| `/ini/login`, `/ini/me` | PHP session | Valkey, phpredis handler set in a php.ini drop-in written by `setup.sh` |
| `/pg/login`, `/pg/me` | PHP session | Postgres, custom `SessionHandlerInterface` (`src/PgSessionHandler.php`) |
| `/s3`, `/s3/upload`, `/s3/file` | Uploaded files | MinIO (S3 API, path-style) |
| `/status.json` | Diagnostics | |

## Environment (from the bound OSC parameter store)

| Key | Example |
|---|---|
| `DATABASE_URL` | `postgres://postgres:PASSWORD@<tenant>-<db>.birme-osc-postgresql.svc.cluster.local:5432/app` |
| `VALKEY_HOST` | `<tenant>-<name>.valkey-io-valkey.svc.cluster.local` |
| `VALKEY_PORT` | `6379` |
| `VALKEY_PASSWORD` | the Valkey instance password |
| `SESSION_INI_MODE` | `redis` to make `setup.sh` write the php.ini drop-in |
| `S3_ENDPOINT` | `https://<tenant>-<name>.minio-minio.auto.prod-se.osaas.io` |
| `S3_ACCESS_KEY` / `S3_SECRET_KEY` | MinIO root user and password, or a MinIO access key |
| `S3_BUCKET` | `uploads` |

`setup.sh` installs `pdo_pgsql` and the `redis` extension (neither is in the
base image), then runs `bin/bootstrap.php`, which creates the session table and
the bucket if they are missing.
