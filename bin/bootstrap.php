<?php
// Run from setup.sh on every build. Idempotent: creates the session table and the upload bucket.
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Env;

if (Env::get('DATABASE_URL')) {
    Env::pdo()->exec(
        'CREATE TABLE IF NOT EXISTS php_sessions (
            id         TEXT PRIMARY KEY,
            data       BYTEA NOT NULL,
            expires_at TIMESTAMPTZ NOT NULL
        )'
    );
    Env::pdo()->exec('CREATE INDEX IF NOT EXISTS php_sessions_expires_idx ON php_sessions (expires_at)');
    echo "[bootstrap] php_sessions table ready\n";
} else {
    echo "[bootstrap] DATABASE_URL not set, skipping session table\n";
}

if (Env::get('S3_ENDPOINT') && Env::get('S3_BUCKET')) {
    $s3 = Env::s3();
    $bucket = Env::get('S3_BUCKET');
    if (!$s3->doesBucketExistV2($bucket)) {
        $s3->createBucket(['Bucket' => $bucket]);
        echo "[bootstrap] created bucket\n";
    } else {
        echo "[bootstrap] bucket exists\n";
    }
} else {
    echo "[bootstrap] S3_ENDPOINT or S3_BUCKET not set, skipping bucket\n";
}
