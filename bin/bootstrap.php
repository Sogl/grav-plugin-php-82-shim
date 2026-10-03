<?php

// [php82shim] deployment bootstrap — run once after rsync/deploy:
//   php user/plugins/php-82-shim/bin/bootstrap.php
//
// Order matters: first transpile+scan the ALREADY-deployed runtime
// (system/, vendor/, user/plugins/, user/themes/), then heal the version
// gates, and only then write the .user.ini prepend. Enabling the shim
// before the existing tree is proven 8.2-safe would fatal the first
// request the prepend is supposed to protect.

@ini_set('memory_limit', '512M'); // one-shot full-tree tokenization

$root = dirname(__DIR__, 4); // user/plugins/php-82-shim/bin -> root
$base = dirname(__DIR__) . '/classes/Php82Shim';

foreach (['ShimLog', 'Lock', 'Transpiler', 'PatchManager'] as $cls) {
    require_once $base . '/' . $cls . '.php';
}

use Grav\Plugin\Php82Shim\PatchManager;
use Grav\Plugin\Php82Shim\Transpiler;

// Hard gate: the shim exists for real PHP 8.2 hosts only. On 8.3+ it would
// patch a native runtime, downgrade plugin syntax for no reason and make
// the site report a fake PHP version to GPM — refuse, patch nothing.
if (PHP_VERSION_ID < 80200 || PHP_VERSION_ID >= 80300) {
    fwrite(STDERR, "php82shim: refusing to run — this is PHP " . PHP_VERSION
        . ", the shim only patches real PHP 8.2 hosts\n");
    exit(1);
}
echo "runtime: PHP " . PHP_VERSION . " — token validation is target-authoritative\n";

$fail = 0;

// 1) Transpile the existing deployed tree. Skip user/data and other
//    non-PHP-runtime dirs — process() itself ignores non-.php files.
foreach (['system', 'vendor', 'user/plugins', 'user/themes'] as $sub) {
    $dir = $root . '/' . $sub;
    if (!is_dir($dir)) {
        continue;
    }
    $r = Transpiler::process($dir, $sub);
    echo "$sub: {$r['files']} scanned, {$r['changed']} downgraded\n";
    $fail += Transpiler::unshimmableCount();
}

// 2) Heal the version gates.
$r = PatchManager::heal($root, 'bootstrap');
foreach ($r['patched'] as $f) {
    echo "patched: $f\n";
}
foreach ($r['failed'] as $f => $why) {
    echo "FAILED: $f — $why\n";
    $fail++;
}

if ($fail > 0) {
    fwrite(STDERR, "php82shim: $fail problem(s) — NOT enabling .user.ini prepend\n");
    exit(1);
}

// 3) Only now arm the prepend — the tree is proven 8.2-safe.
PatchManager::bootstrap($root);
echo "bootstrap complete — .user.ini armed\n";
exit(0);
