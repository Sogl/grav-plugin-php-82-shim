<?php

namespace Grav\Plugin\Php82Shim;

use Grav\Common\Grav;

/**
 * Applies idempotent marker-based patches to Grav core files so the site
 * reports PHP 8.3 to GPM/Composer while running 8.2, and re-applies them
 * after core updates overwrite the files (system/, vendor/, index.php are
 * all replaced by self-upgrade; user/ survives — that's us).
 */
class PatchManager
{
    private const MARKER = '[php82shim]';
    private const FAKE_PHP = '8.3.99';
    private const STATE = 'user://data/php82shim/state.json';

    /**
     * Real PHP 8.2 runtime? Every mutating entry point must refuse to run
     * anywhere else — on native 8.3+ a patch would fake the reported PHP and
     * mangle a runtime that needs no shim, and below 8.2 nothing here works.
     */
    private static function isTarget82(): bool
    {
        return PHP_VERSION_ID >= 80200 && PHP_VERSION_ID < 80300;
    }

    /** @return array<string, list<array{search:string, replace:string, applied:string}>> */
    private static function rules(): array
    {
        $php = self::FAKE_PHP;

        return [
            'index.php' => [
                [
                    'search' => "\\define('GRAV_PHP_MIN', '8.3.0');",
                    'replace' => "\\define('GRAV_PHP_MIN', '8.2.0'); // " . self::MARKER,
                    'applied' => self::MARKER,
                ],
            ],
            'system/defines.php' => [
                [
                    'search' => 'define("GRAV_PHP_MIN", "8.3.0");',
                    'replace' => 'define("GRAV_PHP_MIN", "8.2.0"); // ' . self::MARKER,
                    'applied' => self::MARKER,
                ],
            ],
            'bin/grav' => [
                [
                    'search' => 'if (PHP_VERSION_ID < 80300) {',
                    'replace' => 'if (PHP_VERSION_ID < 80200) { // ' . self::MARKER,
                    'applied' => self::MARKER,
                ],
            ],
            // GPM reports our PHP to getgrav.org in every repo index query —
            // report the fake version so it serves Grav-2/current packages.
            'system/src/Grav/Common/GPM/Remote/AbstractPackageCollection.php' => [
                [
                    'search' => "'&php=' . PHP_VERSION",
                    'replace' => "'&php=' . '" . $php . "' /* " . self::MARKER . ' */',
                    'applied' => self::MARKER,
                ],
            ],
            'system/src/Grav/Common/GPM/Remote/GravCore.php' => [
                [
                    'search' => "'&php=' . PHP_VERSION",
                    'replace' => "'&php=' . '" . $php . "' /* " . self::MARKER . ' */',
                    'applied' => self::MARKER,
                ],
            ],
            // Self-upgrade / install preflight checks.
            'system/src/Grav/Common/GPM/Upgrader.php' => [
                [
                    'search' => "version_compare(PHP_VERSION, \$this->minPHPVersion(), '<')",
                    'replace' => "version_compare('" . $php . "', \$this->minPHPVersion(), '<') /* " . self::MARKER . ' */',
                    'applied' => self::MARKER,
                ],
            ],
            'system/src/Grav/Common/GPM/GPM.php' => [
                [
                    'search' => 'version_compare($testVersion, PHP_VERSION)',
                    'replace' => "version_compare(\$testVersion, '" . $php . "') /* " . self::MARKER . ' */',
                    'applied' => self::MARKER,
                ],
            ],
            'system/src/Grav/Installer/Install.php' => [
                [
                    'search' => "checkVersion(\$results, 'php', 'php', \$this->requires['php'], PHP_VERSION)",
                    'replace' => "checkVersion(\$results, 'php', 'php', \$this->requires['php'], '" . $php . "' /* " . self::MARKER . ' */)',
                    'applied' => self::MARKER,
                ],
            ],
            // unZip() is THE choke point: plugin/theme installs, direct-install
            // and self-upgrade (which includes the payload's own system/
            // install.php and runs its preflight!) all extract through here.
            // Patch at extraction time so even the payload's own preflight
            // sees the fake version and files land already 8.2-ready.
            'system/src/Grav/Common/GPM/Installer.php' => [
                [
                    'search' => "            self::\$error = self::OK;\n\n            return \$destination . '/' . \$package_folder_name;",
                    'replace' => "            self::\$error = self::OK;\n\n            // " . self::MARKER . " downgrade extracted payload + re-apply core patches\n            \$php82shimDir = GRAV_ROOT . '/user/plugins/php-82-shim/classes/Php82Shim';\n            foreach (['ShimLog', 'Lock', 'Transpiler', 'PatchManager'] as \$php82cls) {\n                if (!class_exists('Grav\\\\Plugin\\\\Php82Shim\\\\' . \$php82cls) && is_file(\$php82shimDir . '/' . \$php82cls . '.php')) {\n                    require_once \$php82shimDir . '/' . \$php82cls . '.php';\n                }\n            }\n            \$php82payload = \$destination . '/' . \$package_folder_name;\n            if (class_exists(\\Grav\\Plugin\\Php82Shim\\Transpiler::class)) {\n                \\Grav\\Plugin\\Php82Shim\\Transpiler::process(\$php82payload, (string)\$package_folder_name);\n                if (\\Grav\\Plugin\\Php82Shim\\Transpiler::unshimmableCount() > 0\n                    && \\Grav\\Common\\Grav::instance()['config']->get('plugins.php-82-shim.block_unshimmable')) {\n                    throw new \\RuntimeException('php82shim: package contains PHP 8.3-only constructs that cannot run on PHP 8.2 — install aborted (see /admin/php82shim)');\n                }\n            }\n            // Core payloads MUST take every patch anchor — a missed anchor\n            // means the payload expects code paths our rules don't know; let\n            // the update abort rather than install an unpatched gate.\n            if (class_exists(\\Grav\\Plugin\\Php82Shim\\PatchManager::class)) {\n                \$php82patch = \\Grav\\Plugin\\Php82Shim\\PatchManager::patchTree(\$php82payload);\n                if (\$php82patch['failed']) {\n                    throw new \\RuntimeException('php82shim: core patch anchors failed (' . implode(',', array_keys(\$php82patch['failed'])) . ') — update aborted, shim rules need refresh');\n                }\n            }\n\n            return \$destination . '/' . \$package_folder_name;",
                    'applied' => 'downgrade extracted payload',
                ],
                [
                    'search' => "        if (!file_exists(\$extracted)) {\n            self::\$error = self::INVALID_SOURCE;\n            return false;\n        }\n",
                    'replace' => "        if (!file_exists(\$extracted)) {\n            self::\$error = self::INVALID_SOURCE;\n            return false;\n        }\n\n        // " . self::MARKER . " downgrade incoming package to PHP 8.2 syntax\n        \$php82shimDir = GRAV_ROOT . '/user/plugins/php-82-shim/classes/Php82Shim';\n        foreach (['ShimLog', 'Lock', 'Transpiler', 'PatchManager'] as \$php82cls) {\n            if (!class_exists('Grav\\\\Plugin\\\\Php82Shim\\\\' . \$php82cls) && is_file(\$php82shimDir . '/' . \$php82cls . '.php')) {\n                require_once \$php82shimDir . '/' . \$php82cls . '.php';\n            }\n        }\n        if (class_exists(\\Grav\\Plugin\\Php82Shim\\Transpiler::class)) {\n            \\Grav\\Plugin\\Php82Shim\\Transpiler::process(\$extracted, basename((string)\$install_path));\n            if (\\Grav\\Plugin\\Php82Shim\\Transpiler::unshimmableCount() > 0\n                && \\Grav\\Common\\Grav::instance()['config']->get('plugins.php-82-shim.block_unshimmable')) {\n                throw new \\RuntimeException('php82shim: package contains PHP 8.3-only constructs that cannot run on PHP 8.2 — install aborted (see /admin/php82shim)');\n            }\n        }\n        // " . self::MARKER . " patch core payloads so they land already 8.2-ready — anchors MUST apply, else abort\n        if (class_exists(\\Grav\\Plugin\\Php82Shim\\PatchManager::class)) {\n            \$php82patch = \\Grav\\Plugin\\Php82Shim\\PatchManager::patchTree(\$extracted);\n            if (\$php82patch['failed']) {\n                throw new \\RuntimeException('php82shim: core patch anchors failed (' . implode(',', array_keys(\$php82patch['failed'])) . ') — update aborted, shim rules need refresh');\n            }\n        }\n",
                    'applied' => 'patch core payloads so they land',
                ],
            ],
        ];
    }

    /**
     * composer.json gets real JSON surgery, not str_replace — string edits
     * can duplicate keys (`"scripts"` already exists, `"config"` ordering)
     * and silently produce invalid or mis-precedented manifests.
     */
    private static function patchComposerJson(string $path, array &$patched, bool &$touched, array &$failed): void
    {
        $raw = (string)file_get_contents($path);
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            $failed['composer.json'] = 'invalid JSON';
            return;
        }
        $hook = '@php user/plugins/php-82-shim/bin/shim-vendor.php';
        $before = $json;

        if (isset($json['require']['php'])) {
            $json['require']['php'] = '^8.2';
        }
        $json['config']['platform-check'] = false;
        $pad = $json['scripts']['post-autoload-dump'] ?? null;
        if (is_string($pad)) {
            $pad = [$pad];
        }
        if (!is_array($pad)) {
            $pad = [];
        }
        if (!in_array($hook, $pad, true)) {
            $pad[] = $hook;
        }
        $json['scripts']['post-autoload-dump'] = $pad;

        if ($json === $before) {
            return; // already patched — idempotent
        }
        $out = json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        if (!self::writeAtomic($path, $out)) {
            $failed['composer.json'] = 'not writable';
            return;
        }
        $patched[] = 'composer.json';
        $touched = true;
    }

    /**
     * Full bootstrap for fresh deploys / CLI: heal the tree AND write the
     * .user.ini managed block. Deployment invariant — run once post-deploy:
     * `php user/plugins/php-82-shim/bin/bootstrap.php`
     *
     * @return array{patched:list<string>, failed:array<string,string>}
     */
    public static function bootstrap(string $root): array
    {
        if (!self::isTarget82()) {
            ShimLog::add('bootstrap', 'refusing — runtime is PHP ' . PHP_VERSION . ', not 8.2 (no patching, no prepend)');
            return ['patched' => [], 'failed' => []];
        }
        $root = rtrim($root, '/');
        $result = self::heal($root, 'bootstrap');
        if ($result['failed'] === []) {
            self::writeUserIni($root);
        }
        return $result;
    }

    /**
     * Verify/apply all patches on the live site root. Runs cheap strpos
     * checks; writes only on drift.
     *
     * @return array{patched:list<string>, failed:array<string,string>}
     */
    public static function ensure(Grav $grav): array
    {
        if (!self::isTarget82()) {
            return ['patched' => [], 'failed' => []];
        }
        $root = rtrim(GRAV_ROOT, '/');
        $result = self::heal($root, 'ensure');
        // Same gate bin/bootstrap.php applies: when the tree could not be
        // fully patched, the prepend must NOT be armed — an armed shim on a
        // half-patched core is exactly the state bootstrap refuses to leave.
        if ($result['failed'] === []) {
            self::writeUserIni($root);
        }
        self::saveState($grav, $result['failed']);

        return $result;
    }

    /**
     * Apply the rule table to an extracted package payload. Only fires on
     * trees that look like a Grav core release (index.php + system/defines.php);
     * plugin/theme payloads are ignored — Transpiler handles those.
     *
     * @return array{patched:list<string>, failed:array<string,string>}
     */
    public static function patchTree(string $root): array
    {
        if (!self::isTarget82()) {
            return ['patched' => [], 'failed' => []];
        }
        $root = rtrim($root, '/');
        if (!is_file($root . '/index.php') || !is_file($root . '/system/defines.php')) {
            return ['patched' => [], 'failed' => []];
        }

        return self::heal($root, 'payload');
    }

    /**
     * Apply every rule under $root. Self-contained: needs no Grav instance,
     * so it is safe from auto_prepend/early contexts and mid-install calls.
     * $src names the caller in the shim log (ensure|payload|early).
     *
     * @return array{patched:list<string>, failed:array<string,string>}
     */
    public static function heal(string $root, string $src = 'heal'): array
    {
        // Two workers can observe the same unpatched file at once — serialize
        // the rewrite so they don't interleave writes/log entries.
        if (class_exists(Lock::class)) {
            // live-core domain — serializes against web startup only; vendor/
            // payload scans run on their own lock so they don't stall pages.
            // ensure() runs on every request — make it non-blocking: a
            // contended run means another worker is healing right now, so
            // skipping is safe (idempotent).
            $out = Lock::run('live-core', fn() => self::healInner($root, $src), $src !== 'ensure');
            return $out ?? ['patched' => [], 'failed' => []];
        }
        return self::healInner($root, $src);
    }

    /**
     * heal() with NO internal locking — for callers holding the live-core
     * lock themselves (early.php runs heal+transpile as one transaction).
     */
    public static function healUnlocked(string $root, string $src): array
    {
        return self::healInner($root, $src);
    }

    private static function healInner(string $root, string $src): array
    {
        // Innermost mutator — the runtime gate lives here so EVERY path in
        // (ensure, bootstrap, payload patching, early prepend) refuses off 8.2.
        if (!self::isTarget82()) {
            return ['patched' => [], 'failed' => []];
        }
        // A Grav core self-update is in flight — upstream Installer drops a
        // `.upgrading` marker before swapping live files. Writing now could
        // atomically rename stale bytes back over the new release.
        if (is_file($root . '/.upgrading')) {
            return ['patched' => [], 'failed' => []];
        }

        $patched = [];
        $failed = [];
        $touched = false;
        $pending = []; // file => new content — computed first, written last

        foreach (self::rules() as $file => $rules) {
            $path = $root . '/' . $file;
            if (!is_file($path)) {
                $failed[$file] = 'missing';
                continue;
            }
            $content = (string)file_get_contents($path);
            $new = $content;

            foreach ($rules as $rule) {
                if (strpos($new, $rule['applied']) !== false) {
                    continue; // already patched
                }
                if (strpos($new, $rule['search']) !== false) {
                    $new = str_replace($rule['search'], $rule['replace'], $new);
                } else {
                    $failed[$file] = 'anchor not found';
                }
            }

            if ($new !== $content) {
                $pending[$file] = $new;
            }
        }

        // Fail-closed: a drifted anchor must NOT leave a half-patched live
        // core — e.g. version gates relaxed while the Installer interception
        // silently failed would let an update publish untranspiled 8.3 code.
        // All-or-nothing: anchor drift aborts the whole heal (admin surfaces
        // $failed via state.json and tells the operator to update the shim).
        if ($failed === []) {
            foreach ($pending as $file => $new) {
                if (!self::writeAtomic($root . '/' . $file, $new)) {
                    $failed[$file] = 'not writable';
                } else {
                    $patched[] = $file;
                    $touched = true;
                }
            }
        }

        // composer.json gets real JSON surgery — see patchComposerJson().
        $composerJson = $root . '/composer.json';
        if ($failed === [] && is_file($composerJson)) {
            self::patchComposerJson($composerJson, $patched, $touched, $failed);
        } elseif ($failed === [] && is_file($root . '/index.php')) {
            // Only a Grav-core-looking tree is expected to carry one.
            $failed['composer.json'] = 'missing';
        }

        if ($failed === []) {
            self::stubPlatformCheck($root . '/vendor/composer/platform_check.php', $patched, $touched, $failed);
        }

        foreach ($patched as $file) {
            ShimLog::add('patch', "$src: patched $file");
        }
        foreach ($failed as $file => $reason) {
            // ensure() re-runs every request; a persistent anchor drift is
            // already surfaced via state.json — don't spam one line per hit.
            if ($src !== 'ensure') {
                ShimLog::add('patch-fail', "$src: $file — $reason");
            }
        }

        if ($touched && function_exists('opcache_reset')) {
            @opcache_reset();
        }

        return ['patched' => $patched, 'failed' => $failed];
    }

    /**
     * Temp file + atomic rename: a concurrent worker must never read a
     * half-truncated PHP/INI file.
     */
    private static function writeAtomic(string $path, string $contents): bool
    {
        $tmp = @tempnam(dirname($path), '.php82shim-');
        if ($tmp === false || @file_put_contents($tmp, $contents) === false) {
            if ($tmp !== false) {
                @unlink($tmp);
            }
            return false; // never truncate-and-write a live file — fail visibly
        }
        @chmod($tmp, (fileperms($path) ?: 0644) & 0777);
        if (@rename($tmp, $path)) {
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($path, true);
            }
            return true;
        }
        @unlink($tmp);
        return false; // never truncate-and-write a live file — fail visibly
    }

    /**
     * Ensure .user.ini carries our auto_prepend_file as a managed block —
     * merges into an existing user file instead of owning it. If a foreign
     * auto_prepend_file already exists, ours wins (last directive), and the
     * displaced path is saved so early.php can include it afterwards.
     */
    private static function writeUserIni(string $root): void
    {
        // Own domain — .user.ini may also be touched by cPanel/deploy tools,
        // so at minimum our read-modify-write must be serialized internally.
        // Non-blocking: a contended run means another worker just did it.
        if (class_exists(Lock::class)) {
            Lock::run('user-ini', fn() => self::writeUserIniInner($root), false);
            return;
        }
        self::writeUserIniInner($root);
    }

    private static function writeUserIniInner(string $root): void
    {
        $ini = $root . '/.user.ini';
        $early = dirname(__DIR__, 2) . '/early.php';

        // The prepend target lives OUTSIDE plugins/: if the plugin is deleted
        // or replaced mid-update, a dangling auto_prepend_file would fatal
        // every request. The stub checks `enabled` and delegates to early.php.
        $stub = $root . '/user/php82shim-early.php';
        $stubCode = <<<'PHP'
<?php
// [php82shim] stable prepend target — lives outside plugins/ so removing or
// replacing the plugin can never leave auto_prepend_file pointing at a corpse.
$cfg = __DIR__ . '/config/plugins/php-82-shim.yaml';
if (is_file($cfg) && preg_match('/^\s*enabled\s*:\s*(?:0|off|false|no)\s*$/mi', (string)file_get_contents($cfg))) {
    return; // plugin disabled — stay out of the request
}
$early = __DIR__ . '/plugins/php-82-shim/early.php';
if (is_file($early)) {
    require $early;
}
PHP;
        if (!is_file($stub) || (string)@file_get_contents($stub) !== $stubCode) {
            // Fail-closed: if the stub can't be written, arming .user.ini with
            // a dangling auto_prepend_file would fatal EVERY request.
            @mkdir(dirname($stub), 0775, true);
            if (!self::writeAtomic($stub, $stubCode)) {
                ShimLog::add('patch-fail', 'user-ini: cannot write prepend stub ' . $stub);
                return;
            }
        }

        $block = "; BEGIN " . self::MARKER . "\n"
            . 'auto_prepend_file="' . $stub . '"' . "\n"
            . "; END " . self::MARKER . "\n";

        $cur = is_file($ini) ? (string)file_get_contents($ini) : '';

        // Strip our managed block AND legacy bare lines pointing at either the
        // old early.php target or the stub — then re-append the block LAST so
        // no foreign directive added later can silently supersede it.
        $stripped = preg_replace(
            '/; BEGIN ' . preg_quote(self::MARKER, '/') . '.*?; END ' . preg_quote(self::MARKER, '/') . '\n?/s',
            '',
            $cur
        );
        $stripped = preg_replace(
            '/^[ \t]*auto_prepend_file[ \t]*=[ \t]*["\']?'
                . '(?:' . preg_quote($early, '/') . '|' . preg_quote($stub, '/') . ')'
                . '["\']?[ \t]*\r?\n?/m',
            '',
            $stripped
        );

        // Re-scan foreign auto_prepend_file EVERY run (ini: last wins). cPanel
        // or a deploy script may add one after our block; chain the effective
        // last foreign value so early.php can include it. Handles quoted paths.
        $foreign = null;
        if (preg_match_all('/^[ \t]*auto_prepend_file[ \t]*=[ \t]*("([^"]*)"|\'([^\']*)\'|[^\s;]+)/m', $stripped, $mm)) {
            foreach ($mm[1] as $i => $raw) {
                $foreign = $mm[2][$i] !== '' ? $mm[2][$i] : ($mm[3][$i] !== '' ? $mm[3][$i] : $raw);
            }
        }
        $chainFile = $root . '/user/data/php82shim/chained_prepend.txt';
        if ($foreign !== null) {
            $prior = trim($foreign);
            if (!is_file($chainFile) || trim((string)@file_get_contents($chainFile)) !== $prior) {
                @mkdir(dirname($chainFile), 0775, true);
                // Fail-closed: our directive is about to supersede the foreign
                // one. If we cannot record the displaced path, a WAF/security
                // prepend would silently stop running — abort the arm.
                if (!self::writeAtomic($chainFile, $prior)) {
                    ShimLog::add('patch-fail', 'user-ini: cannot record chained prepend ' . $chainFile);
                    return;
                }
                ShimLog::add('user-ini', 'foreign auto_prepend_file chained via early.php: ' . $prior);
            }
        } elseif (is_file($chainFile)) {
            @unlink($chainFile); // foreign directive removed — drop the chain
        }

        $new = rtrim($stripped) . ($stripped === '' || rtrim($stripped) === '' ? '' : "\n\n") . $block;
        if ($new !== $cur) {
            // Cheap TOCTOU guard: cPanel/deploy tools can edit .user.ini
            // between our read and the rename. Re-read; if it moved, skip —
            // the next run rebuilds from the fresh content.
            $now = is_file($ini) ? (string)file_get_contents($ini) : '';
            if ($now !== $cur) {
                ShimLog::add('user-ini', '.user.ini changed mid-write — skipped, will retry next run');
                return;
            }
            if (!self::writeAtomic($ini, $new)) {
                return;
            }
            ShimLog::add('user-ini', 'wrote .user.ini managed block (stub target, end-anchored)');
        }
    }

    private static function stubPlatformCheck(string $path, array &$patched, bool &$touched, array &$failed): void
    {
        if (!is_file($path)) {
            return;
        }
        if (strpos((string)file_get_contents($path), self::MARKER) !== false) {
            return;
        }
        $stub = "<?php\n\n// " . self::MARKER . " platform check disabled — this site runs Grav on PHP 8.2\n";
        if (!self::writeAtomic($path, $stub)) {
            $failed['vendor/composer/platform_check.php'] = 'not writable';
            return;
        }
        $patched[] = 'vendor/composer/platform_check.php';
        $touched = true;
    }

    private static function saveState(Grav $grav, array $failed): void
    {
        try {
            $locator = $grav['locator'];
            $file = $locator->findResource(self::STATE, true, true);
            // Write only when the failure set actually changes — running this
            // every request is pointless I/O churn on shared storage.
            if (is_file($file)) {
                $prev = json_decode((string)file_get_contents($file), true);
                if (is_array($prev) && ($prev['failed'] ?? null) === $failed) {
                    return;
                }
            }
            $dir = dirname($file);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            self::writeAtomic(
                $file,
                json_encode(['failed' => $failed, 'ts' => time()], JSON_PRETTY_PRINT)
            );
        } catch (\Throwable $e) {
            // state is advisory only — never break a request over it
        }
    }

    /** @return array<string,string> */
    public static function lastFailures(Grav $grav): array
    {
        try {
            $file = $grav['locator']->findResource(self::STATE);
            if ($file && is_file($file)) {
                $state = json_decode((string)file_get_contents($file), true);
                return $state['failed'] ?? [];
            }
        } catch (\Throwable $e) {
        }
        return [];
    }
}
