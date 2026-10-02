<?php

namespace Grav\Plugin\Php82Shim;

/**
 * Append-only JSONL log of what the shim caught and rewrote: transpiled
 * syntax, applied patches, stubbed platform checks. Self-contained — works
 * from auto_prepend/CLI contexts where no Grav instance exists yet.
 */
class ShimLog
{
    private const MAX_BYTES = 524288;  // rotate when file exceeds ~512 KB
    private const KEEP_BYTES = 262144; // keep the newest ~256 KB

    public static function add(string $event, string $detail): void
    {
        $path = self::path();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $line = json_encode([
            'ts'     => date('Y-m-d H:i:s'),
            'event'  => $event,
            'detail' => $detail,
        ]) . "\n";

        // Append + size-check + rotate must be ONE locked transaction on the
        // same descriptor — otherwise a concurrent append between the size
        // test and the truncation is silently discarded.
        $fh = @fopen($path, 'c+');
        if (!$fh) {
            return;
        }
        try {
            if (!flock($fh, LOCK_EX)) {
                return;
            }
            fseek($fh, 0, SEEK_END);
            fwrite($fh, $line);
            $size = ftell($fh);
            if ($size !== false && $size > self::MAX_BYTES) {
                self::rotateLocked($fh, $size);
            }
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /**
     * Newest-first entries.
     *
     * @return list<array{ts:string,event:string,detail:string}>
     */
    public static function read(int $limit = 300): array
    {
        $path = self::path();
        if (!is_file($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) {
            return [];
        }

        $out = [];
        foreach (array_slice($lines, -$limit) as $line) {
            $rec = json_decode($line, true);
            if (is_array($rec)) {
                $out[] = $rec;
            }
        }
        return array_reverse($out);
    }

    public static function clear(): void
    {
        $path = self::path();
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** Rotate while the caller holds LOCK_EX on $fh — keep the newest tail. */
    private static function rotateLocked($fh, int $size): void
    {
        $keepFrom = max(0, $size - self::KEEP_BYTES);
        fseek($fh, $keepFrom);
        $data = stream_get_contents($fh);
        if ($data === false || $data === '') {
            return;
        }
        if ($keepFrom > 0) {
            // Drop a possibly-truncated first line.
            $nl = strpos($data, "\n");
            $data = $nl === false ? '' : substr($data, $nl + 1);
        }
        rewind($fh);
        ftruncate($fh, 0);
        fwrite($fh, $data);
    }

    private static function path(): string
    {
        return self::dataDir() . '/log.jsonl';
    }

    /** Shim-owned data directory (user/data/php82shim) — usable pre-Grav. */
    public static function dataDir(): string
    {
        return self::root() . '/user/data/php82shim';
    }

    private static function root(): string
    {
        if (defined('GRAV_ROOT')) {
            return rtrim(GRAV_ROOT, '/');
        }
        // classes/Php82Shim -> classes -> plugin -> plugins -> user -> root
        return dirname(__DIR__, 5);
    }
}
