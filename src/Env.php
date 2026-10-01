<?php
declare(strict_types=1);

namespace App;

use Aws\S3\S3Client;
use PDO;

/** Builds clients from the env vars that the bound OSC parameter store injects. */
final class Env
{
    public static function get(string $key, ?string $default = null): ?string
    {
        $v = getenv($key);
        return ($v === false || $v === '') ? $default : $v;
    }

    /** DATABASE_URL = postgres://user:password@host:port/dbname */
    public static function pdo(): PDO
    {
        static $pdo = null;
        if ($pdo) {
            return $pdo;
        }
        $url = self::get('DATABASE_URL') ?? throw new \RuntimeException('DATABASE_URL is not set');
        $p = parse_url($url);
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $p['host'],
            $p['port'] ?? 5432,
            ltrim($p['path'] ?? '/postgres', '/')
        );
        $pdo = new PDO($dsn, urldecode($p['user'] ?? ''), urldecode($p['pass'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        return $pdo;
    }

    /** OSC MinIO requires path-style addressing (https://host/bucket/key). */
    public static function s3(): S3Client
    {
        return new S3Client([
            'version' => 'latest',
            'region' => self::get('S3_REGION', 'us-east-1'),
            'endpoint' => self::get('S3_ENDPOINT'),
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => self::get('S3_ACCESS_KEY'),
                'secret' => self::get('S3_SECRET_KEY'),
            ],
        ]);
    }

    /** Valkey session save_path for phpredis: tcp://host:port?auth=password */
    public static function valkeySavePath(string $prefix): string
    {
        $q = ['prefix' => $prefix];
        $pw = self::get('VALKEY_PASSWORD');
        if ($pw !== null) {
            $q = ['auth' => $pw] + $q;
        }
        return sprintf('tcp://%s:%s?%s', self::get('VALKEY_HOST'), self::get('VALKEY_PORT', '6379'), http_build_query($q));
    }
}
