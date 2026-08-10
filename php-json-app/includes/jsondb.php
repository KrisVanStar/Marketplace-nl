<?php
declare(strict_types=1);

/**
 * A minimal file-based "database": everything lives in one JSON file
 * (data/db.json), guarded by flock() so concurrent requests (a page view
 * and the cron worker running at the same time, for example) never read
 * a half-written file or clobber each other's writes.
 *
 * There is no in-process caching — every call re-reads the file — since
 * PHP processes on shared hosting don't share memory between requests
 * anyway. At the scale this app operates at (tens to low hundreds of
 * leads) that's cheap enough not to matter.
 */

function jsondb_path(): string
{
    return APP_ROOT . '/data/db.json';
}

function jsondb_default(): array
{
    return [
        'businesses' => [],
        'scans' => [],
        'scan_jobs' => [],
        'scan_queue' => [],
    ];
}

/**
 * Opens db.json with an exclusive lock, decodes it, passes it to $fn
 * (which must mutate and return the full data array), writes the result
 * back, then releases the lock. Use this for every write. If you only
 * need a side value out of the transaction (e.g. a newly created id),
 * capture it via `use (&$var)` in the closure.
 */
function jsondb_transaction(callable $fn): array
{
    $path = jsondb_path();
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Kan map {$dir} niet aanmaken. Controleer schrijfrechten.");
    }

    $handle = fopen($path, 'c+');
    if ($handle === false) {
        throw new RuntimeException("Kan {$path} niet openen. Controleer schrijfrechten op de data map.");
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException("Kan geen lock krijgen op {$path}.");
        }

        $raw = stream_get_contents($handle);
        $data = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            $data = jsondb_default();
        }
        $data += jsondb_default();

        $newData = $fn($data);
        if (!is_array($newData)) {
            throw new RuntimeException('jsondb_transaction callback must return the data array');
        }

        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($newData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($handle);

        return $newData;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/** Read-only access, still lock-protected (shared lock) so it never sees a half-written file. */
function jsondb_read(): array
{
    $path = jsondb_path();
    if (!is_file($path)) {
        return jsondb_default();
    }

    $handle = fopen($path, 'r');
    if ($handle === false) {
        return jsondb_default();
    }
    flock($handle, LOCK_SH);
    $raw = stream_get_contents($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    $data = ($raw !== false && $raw !== '') ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        $data = jsondb_default();
    }
    return $data + jsondb_default();
}

function jsondb_next_id(array $rows): int
{
    if (empty($rows)) {
        return 1;
    }
    return (int) max(array_column($rows, 'id')) + 1;
}
