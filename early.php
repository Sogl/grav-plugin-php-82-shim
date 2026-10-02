<?php

// [php82shim] early patcher — pulled in by .user.ini auto_prepend_file,
// so it runs before index.php defines GRAV_PHP_MIN. If a core update landed
// unpatched files (or the Problems gate is about to trip), re-apply the
// marker rules now, while there is still no Grav instance around. Patched
// PHP files are transpiled in the SAME lock transaction — a concurrent
// worker must never see a patched-but-still-8.3-syntax file.

$php82shimRoot = dirname(__DIR__, 3);

// Self-standdown: on real PHP >= 8.3 there is nothing to shim — skip heal,
// transpile and heartbeat entirely (and don't mask the fact the plugin can
// be disabled). Still chain any foreign prepend that .user.ini displaced.
$php82native = PHP_VERSION_ID >= 80300;

foreach (['ShimLog', 'Lock', 'Transpiler', 'PatchManager'] as $php82cls) {
    if (!class_exists('Grav\\Plugin\\Php82Shim\\' . $php82cls, false)) {
        require_once __DIR__ . '/classes/Php82Shim/' . $php82cls . '.php';
    }
}

// Polyfills must land before any healed 8.3-syntax file is loaded — code may
// already call json_validate()/str_increment() during early bootstrap.
require_once __DIR__ . '/classes/Php82Shim/polyfill.php';

// Heartbeat: proves the prepend actually ran (web SAPI). bootstrap.php and
// the admin log page read this to verify .user.ini is live under LSAPI.
// Rate-limited: once per 60s is enough proof — no per-request write churn.
$php82hb = \Grav\Plugin\Php82Shim\ShimLog::dataDir() . '/early-heartbeat';
if (!$php82native
    && (!is_file($php82hb) || time() - (int)@file_get_contents($php82hb) > 60)) {
    @file_put_contents($php82hb, (string)time(), LOCK_EX);
}

// Log the standdown exactly once — a marker file keeps this from spamming
// on every request, and is dropped again if the runtime returns to 8.2.
$php82sd = \Grav\Plugin\Php82Shim\ShimLog::dataDir() . '/native-83.flag';
if ($php82native && !is_file($php82sd)) {
    @file_put_contents($php82sd, (string)time(), LOCK_EX);
    \Grav\Plugin\Php82Shim\ShimLog::add(
        'bootstrap',
        'runtime is PHP ' . PHP_VERSION . ' — shim standing down, no patching needed'
    );
} elseif (!$php82native && is_file($php82sd)) {
    @unlink($php82sd);
}

if (!$php82native) {
try {
    \Grav\Plugin\Php82Shim\Lock::run('live-core', static function () use ($php82shimRoot): void {
        $php82result = \Grav\Plugin\Php82Shim\PatchManager::healUnlocked($php82shimRoot, 'early');
        foreach ($php82result['patched'] as $php82file) {
            if (substr($php82file, -4) === '.php' || substr($php82file, -4) === 'grav') {
                \Grav\Plugin\Php82Shim\Transpiler::processFileUnlocked($php82shimRoot . '/' . $php82file);
            }
        }
    });
} catch (\Throwable $php82shimError) {
    // Never break the request from a prepend hook.
}
}

// A foreign auto_prepend_file displaced by our managed .user.ini block is
// chained here so it still runs — with its own failure semantics preserved
// (a security prepend that throws MUST stay fatal; never swallow it).
$php82chained = $php82shimRoot . '/user/data/php82shim/chained_prepend.txt';
if (is_file($php82chained)) {
    $php82prior = trim((string)file_get_contents($php82chained));
    // Native auto_prepend_file resolves through include_path, so a relative
    // foreign path may be valid even when is_file() fails — resolve it the
    // same way instead of silently dropping a (possibly security) prepend.
    if ($php82prior !== '' && $php82prior !== __FILE__) {
        $php82resolved = stream_resolve_include_path($php82prior);
        if ($php82resolved !== false) {
            require $php82resolved;
        }
    }
}

unset($php82shimRoot, $php82shimError, $php82result, $php82file, $php82chained, $php82prior, $php82cls, $php82hb, $php82native, $php82sd);
