<?php

// [php82shim] GPM install/uninstall hooks. Class name must match
// Grav's convention: alphanumeric form of ucfirst($slug).'Install'.

/**
 * preUninstall() is the safety gate for `bin/gpm uninstall php-82-shim`:
 * while the runtime is still PHP 8.2, removing the plugin leaves patched
 * gates accepting 8.3 packages with nothing to run them — refuse.
 * postUninstall() cleans what Folder::delete() cannot reach: the .user.ini
 * block, the composer hook and runtime state outside this directory.
 */
class Php82shimInstall
{
    public static function preUninstall()
    {
        if (PHP_VERSION_ID < 80300) {
            return 'php82shim: REFUSING to uninstall on PHP ' . PHP_VERSION
                . ' — migrate the site to PHP >= 8.3 first. Removing the shim'
                . ' now would leave patched version gates accepting packages'
                . ' your runtime cannot execute.';
        }
        return true;
    }

    public static function postUninstall()
    {
        $root = dirname(__DIR__, 3); // user/plugins/php-82-shim -> root
        $ini = $root . '/.user.ini';
        $stub = $root . '/user/php82shim-early.php';
        $messages = [];

        // Strip only our managed block; foreign directives stay untouched.
        // NOTE: keep the stub file itself — PHP may serve cached .user.ini
        // (user_ini.cache_ttl, default 300s) and a missing prepend target
        // would fatal requests in that window.
        if (is_file($ini)) {
            $cur = (string)file_get_contents($ini);
            $new = preg_replace('/; BEGIN \[php82shim\].*?; END \[php82shim\]\n?/s', '', $cur);
            $new = preg_replace(
                '/^[ \t]*auto_prepend_file[ \t]*=[ \t]*["\']?'
                    . preg_quote($stub, '/') . '["\']?[ \t]*\r?\n?/m',
                '',
                $new
            );
            if ($new !== $cur) {
                file_put_contents($ini, $new);
                $messages[] = '.user.ini block removed (stub file kept — ini cache may still reference it)';
            }
        }

        foreach (['chained_prepend.txt', 'early-heartbeat', 'native-83.flag', 'state.json'] as $f) {
            $p = $root . '/user/data/php82shim/' . $f;
            if (is_file($p)) {
                @unlink($p);
            }
        }

        // Drop the post-autoload-dump hook so `composer install` does not
        // call a script inside the deleted plugin directory.
        $composerJson = $root . '/composer.json';
        if (is_file($composerJson)) {
            $json = json_decode((string)file_get_contents($composerJson), true);
            $hook = '@php user/plugins/php-82-shim/bin/shim-vendor.php';
            $pad = $json['scripts']['post-autoload-dump'] ?? null;
            if ($pad !== null) {
                if (is_string($pad)) {
                    $pad = [$pad];
                }
                if (is_array($pad)) {
                    $pad = array_values(array_filter($pad, static fn($h) => $h !== $hook));
                    if ($pad) {
                        $json['scripts']['post-autoload-dump'] = count($pad) === 1 ? $pad[0] : $pad;
                    } else {
                        unset($json['scripts']['post-autoload-dump']);
                        if (empty($json['scripts'])) {
                            unset($json['scripts']);
                        }
                    }
                    file_put_contents(
                        $composerJson,
                        json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
                    );
                    $messages[] = 'composer.json hook removed';
                }
            }
        }

        $messages[] = 'patched core files intentionally left as-is (harmless on PHP 8.3+; the next core update restores pristine upstream)';

        return implode('; ', $messages);
    }
}
