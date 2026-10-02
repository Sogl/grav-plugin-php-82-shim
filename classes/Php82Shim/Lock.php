<?php

namespace Grav\Plugin\Php82Shim;

/**
 * Advisory cross-process locking for heal/transpile work. Two LSAPI/FPM
 * workers can observe "file not patched" simultaneously and rewrite the same
 * files — atomic writes prevent torn output, but not duplicate work and
 * double log entries. Self-contained: works before Grav boots.
 */
class Lock
{
    /**
     * Run $fn holding an exclusive lock. Blocking by default — healing is
     * fast, and the caller usually *needs* the work done before proceeding
     * (e.g. a package payload must be transpiled before install).
     * With $blocking=false a contended call returns null and skips the work:
     * correct only for idempotent, repeatable operations.
     */
    public static function run(string $name, callable $fn, bool $blocking = true)
    {
        $dir = self::dir();
        $fh = @fopen($dir . '/' . $name . '.lock', 'c');
        if (!$fh) {
            return self::fail($name, 'cannot create lock file', $blocking);
        }

        $acquired = $blocking
            ? flock($fh, LOCK_EX)
            : flock($fh, LOCK_EX | LOCK_NB);

        if (!$acquired) {
            fclose($fh);
            if (!$blocking) {
                return null; // another worker is healing — idempotent, skip
            }
            return self::fail($name, 'flock failed', $blocking);
        }
        try {
            return $fn();
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /**
     * Serialization could not be established for a blocking mutation. Fail
     * closed: mutating a live PHP file unsynchronized is worse than refusing
     * the operation — the caller aborts the install/heal instead.
     */
    private static function fail(string $name, string $why, bool $blocking)
    {
        if (class_exists(ShimLog::class)) {
            ShimLog::add('lock-fail', "$name: $why — mutation refused");
        }
        throw new \RuntimeException("php82shim: lock $name unavailable ($why)");
    }

    private static function dir(): string
    {
        $root = defined('GRAV_ROOT') ? rtrim(GRAV_ROOT, '/') : dirname(__DIR__, 5);
        $dir = $root . '/user/data/php82shim';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }
}
