<?php

// [php82shim] removal helper.
//
// HARD RULE: refuse to run while the site runtime is still PHP 8.2. The
// patched core files (fake version reports, relaxed gates, the composer
// hook) would keep telling GPM/Composer "8.3 is fine" while the transpiler
// that makes 8.3 packages runnable is gone — that combination installs
// fatals. Uninstall is only safe after the runtime is really PHP >= 8.3.
//
//   php user/plugins/php-82-shim/bin/uninstall.php

if (PHP_VERSION_ID < 80300) {
    fwrite(STDERR, "php82shim: REFUSING to uninstall — this runtime is PHP " . PHP_VERSION
        . ". Migrate the site to PHP >= 8.3 first; removing the shim now would\n"
        . "leave patched gates accepting 8.3 packages with nothing to run them.\n");
    exit(1);
}

$root = dirname(__DIR__, 4); // user/plugins/php-82-shim/bin -> root
$ini = $root . '/.user.ini';
$stub = $root . '/user/php82shim-early.php';

// Remove ONLY our managed block from .user.ini.
if (is_file($ini)) {
    $cur = (string)file_get_contents($ini);
    $new = preg_replace('/; BEGIN \[php82shim\].*?; END \[php82shim\]\n?/s', '', $cur);
    $new = preg_replace('/^[ \t]*auto_prepend_file[ \t]*=[ \t]*["\']?'
        . preg_quote($stub, '/') . '["\']?[ \t]*\r?\n?/m', '', $new);
    if ($new !== $cur) {
        file_put_contents($ini, $new);
        echo "removed managed block from .user.ini\n";
    }
}

// The stub stays. PHP caches .user.ini (user_ini.cache_ttl, default 300s) —
// workers may still have auto_prepend_file=<stub> after the directive is
// gone, and a missing file would fatal them. The stub is a permanent
// no-op; deleting it buys nothing and risks a broken window.
if (is_file($stub)) {
    echo "kept $stub (permanent no-op — user_ini cache may still reference it)\n";
}

// Drop the chained-prepend pointer — the foreign directive owns its slot
// again (ours is gone).
$chain = $root . '/user/data/php82shim/chained_prepend.txt';
if (is_file($chain)) {
    unlink($chain);
    echo "removed $chain\n";
}

// Remove our composer hook so `composer install` on the cleaned site does
// not call a deleted script.
$composerJson = $root . '/composer.json';
if (is_file($composerJson)) {
    $json = json_decode((string)file_get_contents($composerJson), true);
    if (is_array($json) && isset($json['scripts']['post-autoload-dump'])) {
        $pad = $json['scripts']['post-autoload-dump'];
        $hook = '@php user/plugins/php-82-shim/bin/shim-vendor.php';
        if (is_string($pad)) {
            $pad = $pad === $hook ? [] : [$pad];
        }
        if (is_array($pad)) {
            $pad = array_values(array_filter($pad, fn($h) => $h !== $hook));
        }
        if ($pad) {
            $json['scripts']['post-autoload-dump'] = $pad;
        } else {
            unset($json['scripts']['post-autoload-dump']);
        }
        file_put_contents($composerJson,
            json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        echo "removed shim-vendor hook from composer.json\n";
    }
}

echo "done — patched core files intentionally left as-is (harmless on PHP 8.3+;\n"
    . "the next core update restores pristine upstream files anyway)\n";
