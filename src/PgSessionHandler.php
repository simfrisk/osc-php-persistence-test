<?php
declare(strict_types=1);

namespace App;

use PDO;
use SessionHandlerInterface;

/**
 * Stores PHP sessions in a Postgres table, so they survive a My App restart.
 *
 * Table (created by bin/bootstrap.php):
 *   CREATE TABLE IF NOT EXISTS php_sessions (
 *     id         TEXT PRIMARY KEY,
 *     data       BYTEA NOT NULL,
 *     expires_at TIMESTAMPTZ NOT NULL
 *   );
 *
 * BYTEA, not TEXT: PHP's session format can contain NUL bytes (private and
 * protected object properties), which a Postgres TEXT column rejects.
 * No row locking: two parallel requests with the same session can overwrite
 * each other's changes (last write wins). The default file handler locks.
 */
final class PgSessionHandler implements SessionHandlerInterface
{
    public function __construct(private PDO $pdo, private int $lifetime = 86400)
    {
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $stmt = $this->pdo->prepare('SELECT data FROM php_sessions WHERE id = :id AND expires_at > now()');
        $stmt->execute([':id' => $id]);
        $data = $stmt->fetchColumn();
        if ($data === false) {
            return '';
        }
        // pdo_pgsql returns BYTEA as a stream resource, not a string.
        return is_resource($data) ? (string) stream_get_contents($data) : (string) $data;
    }

    public function write(string $id, string $data): bool
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO php_sessions (id, data, expires_at)
             VALUES (:id, :data, to_timestamp(:exp))
             ON CONFLICT (id) DO UPDATE SET data = EXCLUDED.data, expires_at = EXCLUDED.expires_at'
        );
        $stmt->bindValue(':id', $id);
        $stmt->bindValue(':data', $data, PDO::PARAM_LOB);
        $stmt->bindValue(':exp', time() + $this->lifetime, PDO::PARAM_INT);
        return $stmt->execute();
    }

    public function destroy(string $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM php_sessions WHERE id = :id');
        return $stmt->execute([':id' => $id]);
    }

    public function gc(int $max_lifetime): int|false
    {
        return $this->pdo->exec('DELETE FROM php_sessions WHERE expires_at < now()');
    }
}
