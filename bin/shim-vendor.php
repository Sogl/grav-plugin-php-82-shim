<?php

// [php82shim] composer post-autoload-dump hook — transpiles vendor/ down to
// PHP 8.2 after `composer install/update`, covering packages that bypass
// GPM's Installer::unZip hook entirely.

$root = dirname(__DIR__, 4); // user/plugins/php-82-shim/bin -> root
$dir = $root . '/vendor';

if (!is_dir($dir)) {
    exit(0);
}

$base = dirname(__DIR__) . '/classes/Php82Shim';
foreach (['ShimLog', 'Lock', 'Transpiler'] as $cls) {
    if (!class_exists('Grav\\Plugin\\Php82Shim\\' . $cls, false)) {
        require_once $base . '/' . $cls . '.php';
    }
}

$r = \Grav\Plugin\Php82Shim\Transpiler::process($dir);
if ($r['changed'] || $r['entries']) {
    fwrite(STDERR, "php82shim: vendor transpiled — {$r['changed']} file(s) changed\n");
}

// Honor fail-closed on the composer path too — a composer-pulled package with
// unshimmable 8.3 code must fail the same way a GPM install does. The toggle
// is read straight from YAML since composer hooks run without Grav.
$blocked = \Grav\Plugin\Php82Shim\Transpiler::unshimmableCount() > 0;
if ($blocked) {
    foreach ([$root . '/user/config/plugins/php-82-shim.yaml', dirname(__DIR__) . '/php-82-shim.yaml'] as $cfg) {
        if (is_file($cfg) && preg_match('/^\s*block_unshimmable\s*:\s*(1|true|on|yes)\s*$/mi', (string)file_get_contents($cfg))) {
            fwrite(STDERR, "php82shim: {$r['files']} files scanned, " . \Grav\Plugin\Php82Shim\Transpiler::unshimmableCount() . " unshimmable — aborting (block_unshimmable is on)\n");
            exit(1);
        }
    }
}
exit(0);
