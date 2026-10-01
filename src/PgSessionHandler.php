<?php
declare(strict_types=1);

namespace App;

use PDO;
use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;

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
 * SessionUpdateTimestampHandlerInterface makes session.use_strict_mode work
 * (unknown session ids from a client are rejected and replaced) and lets
 * session.lazy_write skip rewriting unchanged data.
 * Locking: like PHP's file handler, a request holds a lock on its session
 * from session_start() until the session is written and closed, so parallel
 * requests (AJAX) for the same user cannot overwrite each other's changes.
 * It uses a Postgres advisory lock, which Postgres also releases by itself
 * if the PHP process dies and the connection drops.
 * Call session_write_close() early in long requests to release the lock.
 */
final class PgSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    private ?string $lockedId = null;

    public function __construct(private PDO $pdo, private int $lifetime = 86400, private bool $locking = true)
    {
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        $this->unlock();
        return true;
    }

    private function lock(string $id): void
    {
        if (!$this->locking || $this->lockedId === $id) {
            return;
        }
        $this->unlock();
        $stmt = $this->pdo->prepare('SELECT pg_advisory_lock(hashtextextended(:id, 0))');
        $stmt->execute([':id' => $id]);
        $this->lockedId = $id;
    }

    private function unlock(): void
    {
        if ($this->lockedId === null) {
            return;
        }
        $stmt = $this->pdo->prepare('SELECT pg_advisory_unlock(hashtextextended(:id, 0))');
        $stmt->execute([':id' => $this->lockedId]);
        $this->lockedId = null;
    }

    public function read(string $id): string|false
    {
        $this->lock($id);
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
        $this->unlock();
        $stmt = $this->pdo->prepare('DELETE FROM php_sessions WHERE id = :id');
        return $stmt->execute([':id' => $id]);
    }

    public function gc(int $max_lifetime): int|false
    {
        return $this->pdo->exec('DELETE FROM php_sessions WHERE expires_at < now()');
    }

    public function validateId(string $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM php_sessions WHERE id = :id AND expires_at > now()');
        $stmt->execute([':id' => $id]);
        return (bool) $stmt->fetchColumn();
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        $stmt = $this->pdo->prepare('UPDATE php_sessions SET expires_at = to_timestamp(:exp) WHERE id = :id');
        $stmt->bindValue(':id', $id);
        $stmt->bindValue(':exp', time() + $this->lifetime, PDO::PARAM_INT);
        return $stmt->execute();
    }
}
