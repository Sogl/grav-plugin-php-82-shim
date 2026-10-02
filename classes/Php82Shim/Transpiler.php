<?php

namespace Grav\Plugin\Php82Shim;

use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Downgrades PHP 8.3-only syntax to PHP 8.2 in a directory tree.
 * Token-based (token_get_all lexes fine on any version) — no external deps.
 *
 * Handles: typed class constants, `new readonly class`, `Class::{$expr}`,
 * and stubs out any composer platform_check.php found in the package.
 */
class Transpiler
{
    /**
     * PHP 8.3+ surface that CANNOT be downgraded — methods on internal
     * classes and engine builtins. We can't fix these, only flag them.
     *
     * @var array<string,string> needle => human description
     */
    private static array $unshimmable = [
        '->getBytesFromString(' => 'Randomizer::getBytesFromString (8.3)',
        '->getFloat('          => 'Randomizer::getFloat (8.3)',
        '->nextFloat('         => 'Randomizer::nextFloat (8.3)',
        'ReflectionClassConstant::getType' => 'ReflectionClassConstant::getType (8.3)',
        'ReflectionClassConstant::hasType' => 'ReflectionClassConstant::hasType (8.3)',
        'DatePeriod::createFromISO8601String' => 'DatePeriod::createFromISO8601String (8.3)',
        'ReflectionMethod::createFromMethodName' => 'ReflectionMethod::createFromMethodName (8.3)',
        'socket_atmark('       => 'socket_atmark (8.3)',
        'pg_set_error_context_visibility' => 'pg_set_error_context_visibility (8.3)',
        'ldap_exop_sync'       => 'ldap_exop_sync (8.3)',
        'ldap_connect_wallet'  => 'ldap_connect_wallet (8.3)',
        'posix_sysconf('       => 'posix_sysconf (8.3)',
        'posix_eaccess('       => 'posix_eaccess (8.3)',
        'posix_fpathconf('     => 'posix_fpathconf (8.3)',
        'posix_pathconf('      => 'posix_pathconf (8.3)',
        'ldap_exop_passwd('    => 'ldap_exop_passwd (8.3)',
        '->getArchiveFlag('    => 'ZipArchive::getArchiveFlag (8.3)',
        '->setArchiveFlag('    => 'ZipArchive::setArchiveFlag (8.3)',
    ];

    /**
     * Classes that do not exist on PHP 8.2. Merely referencing them (type
     * hints, array keys, docblocks) is inert — PHP does not resolve hints
     * until call time and such calls cannot happen on 8.2. Only `new`
     * instantiation is a real fatal, so hits are UNSHIMMABLE only when the
     * reference is a `new` expression.
     *
     * @var array<string,string> class needle => human description
     */
    private static array $absentClasses = [
        'Dom\HTMLDocument'     => 'Dom\HTMLDocument (8.3 DOM API)',
        'Dom\XMLDocument'      => 'Dom\XMLDocument (8.3 DOM API)',
        'Dom\HTMLElement'      => 'Dom\HTMLElement (8.3 DOM API)',
        'Dom\HTMLCollection'   => 'Dom\HTMLCollection (8.3 DOM API)',
        'Random\IntervalBoundary' => 'Random\IntervalBoundary enum (8.3)',
    ];

    /**
     * PHP 8.3 syntax a token rewrite cannot safely lower. Flagged, not
     * transpiled — semantics would drift (e.g. static var init order) or the
     * grammar doesn't exist on 8.2 at all.
     *
     * @var array<string,string> regex => human description
     */
    private static array $unsupported = [
        '/\btrait\s+[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*\s*(?:\([^)]*\))?\s*(?:\{(?:[^{}]|\{[^{}]*\})*\})?\s*\bas\s+final\b/s'
            => 'trait method marked `as final` (8.3 syntax)',
        '/\buse\s+[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff\\\\]*\s*\{[^}]*\bas\s+final\b/s'
            => 'trait method adaptation `as final` (8.3 syntax, unparseable on 8.2)',
    ];

    /** @var string[] */
    private static array $log = [];

    /** UNSHIMMABLE hits in the last process() run — drives fail-closed mode. */
    private static int $unshimmableHits = 0;

    /**
     * @return array{files:int, changed:int, entries:string[]}
     */
    public static function process(string $dir, ?string $label = null): array
    {
        if (class_exists(Lock::class)) {
            // Payload/vendor trees get their own domain — a big vendor scan
            // must not serialize front-end requests behind live-core heals.
            $out = Lock::run('vendor', fn() => self::processInner($dir, $label));
            return $out ?? ['files' => 0, 'changed' => 0, 'entries' => []];
        }
        return self::processInner($dir, $label);
    }

    /** How many unshimmable constructs the last process() run flagged. */
    public static function unshimmableCount(): int
    {
        return self::$unshimmableHits;
    }

    /**
     * @return array{files:int, changed:int, entries:string[]}
     */
    private static function processInner(string $dir, ?string $label = null): array
    {
        self::$log = [];
        self::$unshimmableHits = 0;
        // Name of what is being processed — a tmp extraction dir's basename
        // is meaningless, so callers hand over the package slug when known.
        $label = $label ?? basename($dir);
        $files = 0;
        $changed = 0;

        // Test suites and fixtures are never autoloaded at runtime —
        // scanning them only flags fixture strings (like our own selftest)
        // and burns memory on big sample files.
        $skipDirs = ['.git', 'tests', 'test', 'Tests', 'fixtures', 'spec', '.bin'];
        $it = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                static function (SplFileInfo $f) use ($skipDirs): bool {
                    return !$f->isDir() || !in_array($f->getFilename(), $skipDirs, true);
                }
            )
        );

        /** @var SplFileInfo $file */
        foreach ($it as $file) {
            $path = $file->getPathname();

            // Composer's generated gate only — a package may legitimately ship
            // its own src/platform_check.php, don't destroy it by basename.
            if ($file->getFilename() === 'platform_check.php'
                && str_ends_with(str_replace('\\', '/', $path), '/vendor/composer/platform_check.php')) {
                self::stubPlatformCheck($path);
                continue;
            }

            if (strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $files++;

            $raw = @file_get_contents($path);
            if ($raw === false) {
                self::$unshimmableHits++;
                self::$log[] = "$path: TRANSPILE-FAIL unreadable file";
                continue;
            }
            $code = $raw;

            // Flag PHP 8.3+ surface that no transpiler can fix — internal
            // class methods and un-lowerable syntax. GUARDED hits (behind
            // a version/feature guard) are informational and do not count.
            $hits = self::scan($code, $path);
            if ($hits) {
                foreach ($hits as $hit) {
                    self::$log[] = $hit;
                    if (str_contains($hit, 'UNSHIMMABLE')) {
                        self::$unshimmableHits++;
                    }
                }
            }

            // Fast prefilter — only run the rewriter when syntax markers are
            // present; but EVERY candidate must parse on PHP 8.2 regardless,
            // transformed or untouched. A miss means 8.3+ syntax slipped past
            // the transpiler — count it as a compat failure, don't ship it.
            $out = preg_match('/\bconst\b|\breadonly\b|::\s*\{|::\s*\/[*\/]/', $code)
                ? self::transpile($code, $path)
                : $code;

            if (!self::validPhp82($out)) {
                self::$unshimmableHits++;
                self::$log[] = "$path: TRANSPILE-FAIL does not parse on PHP 8.2";
                continue;
            }
            if ($out !== $code) {
                if (!self::writeAtomic($path, $out)) {
                    self::$unshimmableHits++;
                    self::$log[] = "$path: TRANSPILE-FAIL write failed";
                } else {
                    $changed++;
                }
            }
        }

        if (!self::isTarget82()) {
            ShimLog::add('transpile', $label . ': NOTE — ran under PHP ' . PHP_VERSION
                . '; PHP 8.2 parse validation is not target-authoritative here');
        }
        foreach (self::$log as $entry) {
            $event = 'transpile';
            if (str_contains($entry, 'UNSHIMMABLE')) {
                $event = 'unshimmable';
            } elseif (str_contains($entry, 'TRANSPILE-FAIL')) {
                $event = 'transpile-fail';
            } elseif (str_contains($entry, 'GUARDED')) {
                $event = 'guarded';
            }
            ShimLog::add($event, $label . ': ' . str_replace($dir . '/', '', $entry));
        }
        if ($changed > 0) {
            ShimLog::add('package', $label . ': ' . $changed . ' file(s) downgraded (' . $files . ' scanned)');
        }

        return ['files' => $files, 'changed' => $changed, 'entries' => self::$log];
    }

    public static function transpile(string $code, string $path = ''): string
    {
        $prev = null;
        // Fixpoint: nested Class::{...::{...}} needs a second pass to reach
        // the inner expression the outer rewrite just spliced in.
        while ($prev !== $code) {
            $prev = $code;
            $code = self::transpilePass($code, $path);
        }
        return $code;
    }

    private static function transpilePass(string $code, string $path): string
    {
        $tokens = token_get_all($code);
        $count = count($tokens);
        $out = '';
        $i = 0;

        // Context stack: 'class' when inside class-like body, 'fn' inside
        // function/method body, 'block' otherwise. Only `const` directly in
        // 'class' context is a class constant candidate.
        $stack = [];
        $pending = null; // context to push on next '{'

        $prevMeaningful = static function (int $j) use ($tokens): array|string {
            for ($k = $j - 1; $k >= 0; $k--) {
                $t = $tokens[$k];
                if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                return $t;
            }
            return '';
        };

        while ($i < $count) {
            $t = $tokens[$i];

            if (!is_array($t)) {
                if ($t === '{') {
                    $stack[] = $pending ?? 'block';
                    $pending = null;
                } elseif ($t === '}') {
                    array_pop($stack);
                } elseif ($t === ';') {
                    $pending = null;
                }
                $out .= $t;
                $i++;
                continue;
            }

            $id = $t[0];
            $text = $t[1];

            // --- context tracking -------------------------------------------
            if (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT], true) || (defined('T_ENUM') && $id === T_ENUM)) {
                $prev = $prevMeaningful($i);
                // `Foo::class` / `?->` are not declarations; `new class` IS one.
                if (!(is_array($prev) && $prev[0] === T_DOUBLE_COLON)
                    && !(is_array($prev) && $prev[0] === T_OBJECT_OPERATOR)) {
                    $pending = 'class';
                }
                $out .= $text;
                $i++;
                continue;
            }

            if (in_array($id, [T_FUNCTION, T_FN], true)) {
                $pending = 'fn';
                $out .= $text;
                $i++;
                continue;
            }

            // `new readonly class` — named readonly classes are legal on 8.2,
            // only the anonymous form is 8.3. Strip readonly after `new`.
            if ($id === T_READONLY && defined('T_READONLY')) {
                $prev = $prevMeaningful($i);
                if (is_array($prev) && $prev[0] === T_NEW) {
                    $j = $i + 1;
                    $ws = '';
                    // whitespace AND comments may sit between `readonly`/`class`
                    while ($j < $count && is_array($tokens[$j])
                        && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        $ws .= $tokens[$j][1];
                        $j++;
                    }
                    if ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_CLASS) {
                        self::$log[] = "$path: readonly anonymous class demoted";
                        $i = $j; // skip `readonly` + whitespace, keep `class`
                        continue;
                    }
                }
                $out .= $text;
                $i++;
                continue;
            }

            // --- typed class constants ---------------------------------------
            if ($id === T_CONST && end($stack) === 'class') {
                // Collect segment between `const` and the first `=` / `,` / `;`.
                $j = $i + 1;
                $head = [];
                while ($j < $count) {
                    $t2 = $tokens[$j];
                    if (!is_array($t2) && in_array($t2, ['=', ',', ';'], true)) {
                        break;
                    }
                    $head[] = $t2;
                    $j++;
                }
                // The last T_STRING in the head is the constant name; anything
                // before it is the type (int|string, ?float, \Foo\Bar, ...).
                $nameIdx = null;
                foreach (array_keys($head) as $k => $kk) {
                    $h = $head[$kk];
                    if (is_array($h) && $h[0] === T_STRING) {
                        $nameIdx = $kk;
                    }
                }
                if ($nameIdx !== null) {
                    $hasType = false;
                    foreach ($head as $kk => $h) {
                        if ($kk >= $nameIdx) {
                            break;
                        }
                        if (is_array($h) && !in_array($h[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                            $hasType = true;
                            break;
                        }
                        if (!is_array($h)) {
                            $hasType = true;
                            break;
                        }
                    }
                    if ($hasType) {
                        self::$log[] = "$path: typed class const -> untyped (" . trim($head[$nameIdx][1]) . ')';
                        $out .= $text;               // `const`
                        // keep whitespace up to the name, drop the type tokens
                        $k = 0;
                        while ($k < $nameIdx && is_array($head[$k]) && $head[$k][0] === T_WHITESPACE) {
                            $out .= $head[$k][1];
                            $k++;
                        }
                        $out .= $head[$nameIdx][1];  // const name
                        $i += $nameIdx + 2;
                        continue;
                    }
                }
                $out .= $text;
                $i++;
                continue;
            }

            // --- dynamic class constant fetch  Name::{$expr} -----------------
            if ($id === T_DOUBLE_COLON) {
                // look ahead: whitespace AND comments may sit before '{'
                $j = $i + 1;
                while ($j < $count && is_array($tokens[$j])
                    && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $j++;
                }
                if ($j < $count && $tokens[$j] === '{') {
                    // find matching '}'
                    $depth = 0;
                    $k = $j;
                    $expr = '';
                    while ($k < $count) {
                        $tk = $tokens[$k];
                        $c = is_array($tk) ? $tk[1] : $tk;
                        if ($c === '{') {
                            $depth++;
                            if ($depth > 1) {
                                $expr .= $c;
                            }
                        } elseif ($c === '}') {
                            $depth--;
                            if ($depth === 0) {
                                break;
                            }
                            $expr .= $c;
                        } else {
                            $expr .= $c;
                        }
                        $k++;
                    }
                    if ($depth === 0) {
                        // `X::{expr}(` is a dynamic METHOD call — legal since
                        // forever, only constant fetches need the downgrade.
                        // Skip whitespace AND comments before the `(`.
                        $n = $k + 1;
                        while ($n < $count && is_array($tokens[$n])
                            && in_array($tokens[$n][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                            $n++;
                        }
                        if ($n < $count && $tokens[$n] === '(') {
                            $out .= $text;
                            $i++;
                            continue;
                        }
                        // LHS is whatever expression sits before `::` — walk
                        // the token stream backwards so comments and exotic
                        // LHSes (`($a . $b)::{$c}`, `$x->{y}::{$z}`) resolve
                        // correctly instead of being regex-approximated.
                        $lhsStart = self::lhsStart($tokens, $i);
                        if ($lhsStart !== null) {
                            $lhs = '';
                            $nameTok = null;
                            $meaningful = 0;
                            for ($e = $lhsStart; $e < $i; $e++) {
                                $te = $tokens[$e];
                                $lhs .= is_array($te) ? $te[1] : $te;
                                if (is_array($te)
                                    && !in_array($te[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                                    $nameTok = $te;
                                    $meaningful++;
                                } elseif (!is_array($te)) {
                                    $meaningful++;
                                }
                            }
                            $lhsTrim = trim($lhs);
                            // Bare names (incl. self/parent/static) resolve via
                            // ::class. Anything else ($var, Foo::BAR, $a[0],
                            // (expr)) is a runtime class-or-object value →
                            // the is_object pick.
                            $isBare = $meaningful === 1 && is_array($nameTok)
                                && in_array($nameTok[0], [T_STRING, T_NAME_QUALIFIED,
                                    T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE, T_STATIC], true);
                            $lhsExpr = $isBare
                                ? $lhsTrim . '::class'
                                // Evaluate the LHS ONCE into a temp var —
                                // `foo()::{...}` must not run foo() thrice.
                                : '(is_object($__php82v = ' . $lhsTrim . ') ? get_class($__php82v) : $__php82v)';
                            $emitLen = strlen($lhs);
                            $out = $emitLen > 0 ? substr($out, 0, -$emitLen) : $out;
                            $out .= '\\constant(' . $lhsExpr . ' . \'::\' . (' . $expr . '))';
                            self::$log[] = "$path: dynamic const fetch {$lhsTrim}::{...} -> constant()";
                            $i = $k + 1;
                            continue;
                        }
                    }
                }
                $out .= $text;
                $i++;
                continue;
            }

            $out .= $text;
            $i++;
        }

        return $out;
    }

    /**
     * Find the start index of the expression immediately before `::` at $i —
     * a backward token walk handling `$a[0]`, `Foo::BAR`, `($a . $b)`,
     * `foo()` and trailing whitespace/comments. Returns null when no LHS
     * expression is detectable.
     */
    private static function lhsStart(array $tokens, int $i): ?int
    {
        static $nameIds = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED,
            T_NAME_RELATIVE, T_STATIC];
        $depth = 0;
        $j = $i - 1;
        for (; $j >= 0; $j--) {
            $t = $tokens[$j];
            $c = is_array($t) ? null : $t;
            if ($depth > 0) {
                if ($c === ')' || $c === ']' || $c === '}') {
                    $depth++;
                } elseif ($c === '(' || $c === '[' || $c === '{') {
                    $depth--;
                }
                continue; // inside a balanced region — belongs to the expr
            }
            if ($c === ')' || $c === ']' || $c === '}') {
                $depth++;
                continue;
            }
            if (!is_array($t)) {
                break; // `(`, `;`, `,`, `=`, `?`… — the expression ends here
            }
            if (in_array($t[0], $nameIds, true)
                || in_array($t[0], [T_VARIABLE, T_OBJECT_OPERATOR, T_DOUBLE_COLON,
                    T_NULLSAFE_OBJECT_OPERATOR, T_NEW, T_CLONE,
                    T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            break; // any other token (keyword, operator) — expression ends
        }
        $start = $j + 1;
        // Leading whitespace/comments are NOT part of the expression — leave
        // them in the emitted output (`$v = Foo::{}` keeps `= ` spacing).
        while ($start < $i && is_array($tokens[$start])
            && in_array($tokens[$start][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $start++;
        }
        return $start < $i ? $start : null;
    }

    /**
     * Scan code for PHP 8.3+ surface we cannot lower/polyfill. Pure analysis —
     * used by process() and selftest.
     *
     * Hits inside a construct that cannot execute on PHP 8.2 — `if
     * (PHP_VERSION_ID >= 80300) {…}`, `if (function_exists('x')) {…}` where x
     * is a known 8.3-only symbol — are reported as GUARDED, not UNSHIMMABLE:
     * they're dead code on the real runtime and must not trip
     * block_unshimmable.
     *
     * @return list<string> log entries ("<path>: UNSHIMMABLE|GUARDED …")
     */
    public static function scan(string $code, string $path): array
    {
        // Scan a comment-free copy — `// $r->getFloat()` must not false-flag.
        // String literals are kept: cheap pass, and a needle inside a string
        // is rare enough to prefer flagging over silently missing real calls.
        $hay = self::stripComments($code);

        // Cheap first pass — collect raw [desc, offset, kind] hits. The
        // guard/string tokenizer runs ONLY when something was found (vast
        // majority of files have zero hits — don't burn memory tokenizing
        // them twice).
        $raw = [];
        foreach (self::$unshimmable as $needle => $desc) {
            $pos = 0;
            while (($p = strpos($hay, $needle, $pos)) !== false) {
                $raw[] = [$desc, $p, 'fn'];
                $pos = $p + strlen($needle);
            }
        }
        foreach (self::$absentClasses as $needle => $desc) {
            $pos = 0;
            while (($p = strpos($hay, $needle, $pos)) !== false) {
                $raw[] = [$desc, $p, 'class'];
                $pos = $p + strlen($needle);
            }
        }
        foreach (self::$unsupported as $re => $desc) {
            if (preg_match($re, $hay, $m, PREG_OFFSET_CAPTURE)) {
                $raw[] = [$desc, $m[0][1], 'fn'];
            }
        }
        // ReflectionClassConstant::{hasType,getType} (8.3) are instance calls —
        // the needle spelling `ReflectionClassConstant::getType` misses the
        // normal `$rc->getType()`. Flag them when the class is referenced AND
        // a matching instance call appears (best-effort; receiver types are
        // unknowable without real type inference).
        if (strpos($hay, 'ReflectionClassConstant') !== false
            && preg_match('/->\s*(?:hasType|getType)\s*\(/', $hay, $mm, PREG_OFFSET_CAPTURE)) {
            $raw[] = ['ReflectionClassConstant->hasType/getType (8.3)', $mm[0][1], 'fn'];
        }

        // Token-level static-init analysis only when the cheap strpos gate
        // passes — tokenizing megabyte-size data files on every scan is what
        // ate the shared-host memory limit.
        $staticAt = (stripos($hay, 'static') !== false && strpos($hay, '$') !== false)
            ? self::hasStaticNonConstInit($hay) : null;
        if ($staticAt !== null) {
            $raw[] = ['static variable with non-constant initializer `static $x = <expr>` (8.3 syntax)', $staticAt, 'fn'];
        }
        if (!$raw) {
            return [];
        }

        // Second pass — tokenize ONCE now that we know there is something
        // to classify: literal-string spans (fixture needles like our own
        // scan table must not self-flag) and `new`-detection for class refs.
        try {
            $tokens = token_get_all($hay);
        } catch (\Throwable) {
            $tokens = [];
        }
        $tokOff = [];
        $p = 0;
        foreach ($tokens as $t) {
            $tokOff[] = $p;
            $p += strlen(is_array($t) ? $t[1] : $t);
        }
        $strSpans = [];
        foreach ($tokens as $i => $t) {
            if (is_array($t) && in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $strSpans[] = [$tokOff[$i], $tokOff[$i] + strlen($t[1])];
            }
        }
        // Previous meaningful token fully before a byte offset — for `new`
        // checks. Skips the token CONTAINING the offset (e.g. a qualified
        // `\Dom\X` name is one token and the hit offset sits inside it).
        $prevTok = static function (int $offset) use ($tokens, $tokOff): ?array {
            $prev = null;
            foreach ($tokens as $i => $t) {
                $len = strlen(is_array($t) ? $t[1] : $t);
                if ($tokOff[$i] + $len > $offset) {
                    break;
                }
                if (!(is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))) {
                    $prev = $t;
                }
            }
            return is_array($prev) ? $prev : ($prev !== null ? [null, $prev] : null);
        };

        $dead = self::deadSpans82($hay);
        $hits = [];
        foreach ($raw as [$desc, $offset, $kind]) {
            // Needle inside a string literal — a doc/test fixture, not code.
            $inStr = false;
            foreach ($strSpans as [$ss, $se]) {
                if ($offset >= $ss && $offset < $se) {
                    $inStr = true;
                    break;
                }
            }
            if ($inStr) {
                continue;
            }

            if ($kind === 'class') {
                $prev = $prevTok($offset);
                if (!($prev && $prev[0] === T_NEW)) {
                    // Inert reference — the class can't exist on 8.2, so any
                    // code path needing it is already unreachable. Not a
                    // failure, but worth seeing in the log.
                    $hits[] = "$path: INERT $desc — class absent on 8.2, reference cannot execute";
                    continue;
                }
            }

            $guarded = false;
            foreach ($dead as [$ds, $de, $dsym]) {
                if ($offset >= $ds && $offset < $de
                    && ($dsym === null || str_contains($desc, $dsym))) {
                    $guarded = true;
                    break;
                }
            }
            $hits[] = $guarded
                ? "$path: GUARDED $desc — behind a PHP 8.3 guard, unreachable on 8.2"
                : "$path: UNSHIMMABLE $desc — will fatal if reached on 8.2";
        }
        return $hits;
    }

    /**
     * Regions of the file that cannot execute on PHP 8.2 — bodies of
     * `if (PHP_VERSION_ID >= 80300)` and positive `function_exists()`/
     * `method_exists()`/`class_exists()` guards for 8.3-only symbols.
     *
     * @return list<array{0:int,1:int,2:?string}> [startOffset, endOffset,
     *         symbol-filter (null = applies to every hit inside)]
     */
    private static function deadSpans82(string $code): array
    {
        try {
            $tokens = token_get_all($code);
        } catch (\Throwable) {
            return [];
        }
        $count = count($tokens);
        // Byte offsets per token.
        $off = [];
        $p = 0;
        foreach ($tokens as $t) {
            $off[] = $p;
            $p += strlen(is_array($t) ? $t[1] : $t);
        }
        $end = $p;

        $skip = static function (int $j) use ($tokens, $count): ?int {
            for (; $j < $count; $j++) {
                if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                return $j;
            }
            return null;
        };
        // Span of the statement/block following position $j (at its first
        // meaningful token): braced block, `:`-alternative block, or a single
        // statement up to `;`.
        $blockSpan = static function (int $j) use ($tokens, $count, $off): ?array {
            if ($j >= $count) {
                return null;
            }
            if ($tokens[$j] === '{') {
                $d = 0;
                for ($k = $j; $k < $count; $k++) {
                    $c = is_array($tokens[$k]) ? null : $tokens[$k];
                    if ($c === '{') {
                        $d++;
                    } elseif ($c === '}') {
                        if (--$d === 0) {
                            return [$off[$j], $off[$k] + 1];
                        }
                    }
                }
                return null;
            }
            // Single statement — ends at depth-0 `;`.
            $d = 0;
            for ($k = $j; $k < $count; $k++) {
                $c = is_array($tokens[$k]) ? null : $tokens[$k];
                if ($c === '(' || $c === '[' || $c === '{') {
                    $d++;
                } elseif ($c === ')' || $c === ']' || $c === '}') {
                    if ($d === 0) {
                        break;
                    }
                    $d--;
                } elseif ($c === ';' && $d === 0) {
                    return [$off[$j], $off[$k] + 1];
                }
            }
            return null;
        };

        $spans = [];
        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            if (!is_array($t) || ($t[0] !== T_IF && $t[0] !== T_ELSEIF)) {
                continue;
            }
            $c = $skip($i + 1);
            if ($c === null || $tokens[$c] !== '(') {
                continue;
            }
            // condition tokens inside the parens
            $d = 0;
            $cend = null;
            for ($k = $c; $k < $count; $k++) {
                $cc = is_array($tokens[$k]) ? null : $tokens[$k];
                if ($cc === '(') {
                    $d++;
                } elseif ($cc === ')') {
                    if (--$d === 0) {
                        $cend = $k;
                        break;
                    }
                }
            }
            if ($cend === null) {
                continue;
            }
            [$verdict, $sym] = self::classifyGuard(array_slice($tokens, $c + 1, $cend - $c - 1));
            $b = $skip($cend + 1);
            if ($b === null) {
                break;
            }
            $span = $blockSpan($b);
            if ($span === null) {
                continue;
            }
            // FALSE on 8.2 → this block never runs.
            if ($verdict === 'false82') {
                $spans[] = [$span[0], $span[1], $sym];
            }
            // Walk the else/elseif chain: a guard that is always TRUE on 8.2
            // kills every remaining branch; `elseif` gets its own verdict.
            $tail = $skip(self::spanEndToken($tokens, $span, $off));
            if ($verdict === 'true82') {
                while ($tail !== null && is_array($tokens[$tail]) && $tokens[$tail][0] === T_ELSE) {
                    $e = $skip($tail + 1);
                    if ($e === null) {
                        break;
                    }
                    if (is_array($tokens[$e]) && $tokens[$e][0] === T_IF) {
                        break; // `else if` — handled as an elseif by main loop? no: mark dead anyway
                    }
                    $espan = $blockSpan($e);
                    if ($espan === null) {
                        break;
                    }
                    $spans[] = [$espan[0], $espan[1], null];
                    $tail = $skip(self::spanEndToken($tokens, $espan, $off));
                }
            }
        }
        return $spans;
    }

    /** Token index right after a [start,end] byte span. */
    private static function spanEndToken(array $tokens, array $span, array $off): int
    {
        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            if ($off[$i] >= $span[1]) {
                return $i;
            }
        }
        return count($tokens);
    }

    /**
     * Classify an `if` condition for the PHP 8.2 runtime.
     *
     * @return array{0:string,1:?string} verdict ∈ true82|false82|unknown and
     *         an optional symbol the verdict is limited to (null = all).
     */
    private static function classifyGuard(array $cond): array
    {
        // Meaningful token texts only.
        $m = [];
        foreach ($cond as $t) {
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $m[] = $t;
        }
        $n = count($m);
        if ($n === 0) {
            return ['unknown', null];
        }
        $text = static fn($t) => is_array($t) ? $t[1] : $t;
        $negated = false;
        if ($text($m[0]) === '!') {
            $negated = true;
            array_shift($m);
            $n--;
        }

        // PHP_VERSION_ID <cmp> <int>  (or reversed)
        if ($n === 3) {
            $a = $text($m[0]);
            $op = $text($m[1]);
            $bTok = $m[2];
            $flip = false;
            if (is_array($m[0]) && $m[0][0] === T_LNUMBER) {
                // <int> <op> PHP_VERSION_ID — flip the operator
                $flip = true;
                [$a, $op, $bTok] = [is_array($bTok) ? $bTok[1] : $bTok,
                    strtr($op, ['<' => '>', '>' => '<', '=' => '=']), $m[0]];
            }
            if (ltrim($a, '\\') === 'PHP_VERSION_ID' && is_array($bTok) && $bTok[0] === T_LNUMBER
                && in_array($op, ['<', '<=', '>', '>=', '==', '===', '!=', '!=='], true)) {
                $v = (int)$bTok[1];
                // True/false must hold for the whole 8.2.x range — check the
                // extreme bound that is most likely to flip the verdict.
                $lo = eval("return 80200 $op $v;");
                $hi = eval("return 80299 $op $v;");
                if ($lo === $hi) {
                    return [$lo ? 'true82' : 'false82', null];
                }
                return ['unknown', null];
            }
        }

        // function_exists('x') / method_exists('C','m') / class_exists('X') —
        // positive form ⇒ block dead on 8.2 only when the checked symbol is a
        // known 8.3-only addition (polyfilled or native-8.2 functions DO run).
        if ($n >= 4 && is_array($m[0]) && $m[0][0] === T_STRING
            && in_array($m[0][1], ['function_exists', 'method_exists', 'class_exists',
                'interface_exists', 'enum_exists'], true)
            && $text($m[1]) === '(') {
            $sym = null;
            for ($k = 2; $k < $n; $k++) {
                if (is_array($m[$k]) && $m[$k][0] === T_CONSTANT_ENCAPSED_STRING) {
                    $sym = trim($m[$k][1], '\'"');
                }
            }
            if ($sym !== null && self::isKnown83Symbol($sym)) {
                return [$negated ? 'true82' : 'false82', $sym];
            }
        }
        return ['unknown', null];
    }

    /**
     * Is $sym one of the PHP 8.3 additions we track? Guards for symbols we
     * don't know about stay UNKNOWN — guessing "doesn't exist on 8.2" would
     * risk marking live code dead.
     */
    private static function isKnown83Symbol(string $sym): bool
    {
        $sym = ltrim($sym, '\\');
        foreach (array_keys(self::$unshimmable) as $needle) {
            $bare = preg_replace('/^(?:->|::)/', '', rtrim($needle, '('));
            if ($bare === $sym || substr($sym, -strlen($bare) - 1) === '\\' . $bare) {
                return true;
            }
        }
        return false;
    }

    /**
     * `static $x = <non-constant-expression>` is legal only on 8.3+. Token-level
     * check (not regex) so that `static $data = array(...)`, scalar/constant
     * initializers, `Foo::BAR`, and *static properties* (which have always
     * accepted these forms) do not false-flag.
     *
     * @return int|null byte offset of the offending `static` (for guard-span
     *                  checks), or null when nothing 8.3-only is found
     */
    private static function hasStaticNonConstInit(string $code): ?int
    {
        try {
            $tokens = token_get_all($code);
        } catch (\Throwable) {
            return null;
        }
        $count = count($tokens);
        $off = [];
        $p = 0;
        foreach ($tokens as $tk) {
            $off[] = $p;
            $p += strlen(is_array($tk) ? $tk[1] : $tk);
        }
        $skip = static function (int $j) use ($tokens, $count): ?int {
            for ($k = $j; $k < $count; $k++) {
                if (is_array($tokens[$k]) && in_array($tokens[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                return $k;
            }
            return null;
        };
        $prev = static function (int $j) use ($tokens): array|string|null {
            for ($k = $j - 1; $k >= 0; $k--) {
                if (is_array($tokens[$k]) && in_array($tokens[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                return $tokens[$k];
            }
            return null;
        };

        // Visibility/readonly/var before `static` means a property, which has
        // always accepted array/constant initializers — not our concern.
        $propKw = [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_VAR, T_READONLY, T_ABSTRACT];

        // Tokens that can never appear inside a static-var initializer on
        // 8.2 — the 8.3 RFC relaxed them to arbitrary expressions. Note `new`
        // is NOT listed: "new in initializers" (8.1) already covers
        // `static $x = new Foo`, proven live on this engine.
        $alwaysNonConst = [T_VARIABLE, T_FUNCTION, T_FN, T_EVAL,
            T_CLONE, T_PRINT, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE,
            T_MATCH, T_THROW, T_YIELD, T_YIELD_FROM];
        // `new` is legal in 8.2 static initializers, but the constructor's
        // `()` after the class name looks identical to a function call — the
        // name check must skip names directly preceded by T_NEW.
        $names = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE];

        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            if (!is_array($t) || $t[0] !== T_STATIC) {
                continue;
            }
            $p = $prev($i);
            if (is_array($p) && in_array($p[0], $propKw, true)) {
                continue;
            }
            $v = $skip($i + 1);
            if ($v === null || !is_array($tokens[$v]) || $tokens[$v][0] !== T_VARIABLE) {
                continue;
            }
            $e = $skip($v + 1);
            if ($e === null || $tokens[$e] !== '=') {
                continue;
            }
            $hit = $off[$i];
            // Scan every initializer of the comma-chained declaration
            // (`static $a = 1, $b = f();`) until ';' at depth 0.
            $j = $e + 1;
            $depth = 0;
            while ($j < $count) {
                $s = $skip($j);
                if ($s === null) {
                    break;
                }
                $j = $s;
                $t = $tokens[$j];
                if (!is_array($t)) {
                    if ($t === '(' || $t === '[' || $t === '{') {
                        $depth++;
                    } elseif ($t === ')' || $t === ']' || $t === '}') {
                        $depth--;
                    } elseif ($depth === 0 && $t === ';') {
                        break;
                    } elseif ($depth === 0 && $t === ',') {
                        // Next comma-chained declarator: expect $var = …
                        $v = $skip($j + 1);
                        if ($v === null || !is_array($tokens[$v]) || $tokens[$v][0] !== T_VARIABLE) {
                            break;
                        }
                        $e = $skip($v + 1);
                        if ($e === null || $tokens[$e] !== '=') {
                            break;
                        }
                        $j = $e;
                    }
                } elseif (in_array($t[0], $alwaysNonConst, true)) {
                    return $hit;
                } elseif (in_array($t[0], $names, true)) {
                    $pp = $prev($j);
                    if (is_array($pp) && $pp[0] === T_NEW) {
                        $j++;
                        continue; // constructor call — legal on 8.2
                    }
                    $a = $skip($j + 1);
                    if ($a === null) {
                        break;
                    }
                    $n = $tokens[$a];
                    if ($n === '(') {
                        return $hit; // foo( / \Vendor\helper(
                    }
                    if (is_array($n) && $n[0] === T_DOUBLE_COLON) {
                        $b = $skip($a + 1);
                        if ($b === null) {
                            break;
                        }
                        $m = $tokens[$b];
                        if (is_array($m) && $m[0] === T_VARIABLE) {
                            return $hit; // Foo::$prop fetch — not constant
                        }
                        if (!is_array($m) || $m[0] !== T_CLASS) {
                            // ::class is constant; anything else may be
                            // a method call — look one token further.
                            $c = $skip($b + 1);
                            if ($c !== null && $tokens[$c] === '(') {
                                return $hit;
                            }
                        }
                        $j = $b;
                    }
                }
                $j++;
            }
        }
        return null;
    }

    /** Remove comments without touching anything else. */
    private static function stripComments(string $code): string
    {
        try {
            $tokens = token_get_all($code);
        } catch (\Throwable) {
            return $code;
        }
        $out = '';
        foreach ($tokens as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($t) ? $t[1] : $t;
        }
        return $out;
    }

    /**
     * Transpile a single file in place (used by early.php after a live heal).
     */
    public static function processFile(string $path): bool
    {
        if (class_exists(Lock::class)) {
            return (bool)Lock::run('live-core', fn() => self::processFileInner($path));
        }
        return self::processFileInner($path);
    }

    /**
     * Single-file transpile with NO internal locking — for callers that
     * already hold the live-core lock (early.php runs heal+transpile as one
     * transaction so no worker can see patched-but-untranspiled core).
     */
    public static function processFileUnlocked(string $path): bool
    {
        return self::processFileInner($path);
    }

    private static function processFileInner(string $path): bool
    {
        $short = defined('GRAV_ROOT') ? str_replace(rtrim(GRAV_ROOT, '/') . '/', '', $path) : $path;
        $code = (string)file_get_contents($path);
        $out = preg_match('/\bconst\b|\breadonly\b|::\s*\{|::\s*\/[*\/]/', $code)
            ? self::transpile($code, $path)
            : $code;
        if (!self::validPhp82($out)) {
            ShimLog::add('transpile-fail', "$short: does not parse on PHP 8.2 — will fatal when loaded");
            return false;
        }
        if ($out === $code) {
            return false;
        }
        if (!self::writeAtomic($path, $out)) {
            ShimLog::add('transpile-fail', "$short: write failed");
            return false;
        }
        ShimLog::add('transpile', "$short: downgraded (self-heal)");
        return true;
    }

    /**
     * Is this process actually PHP 8.2? TOKEN_PARSE only proves "parses on
     * the current engine" — under CLI PHP 8.3/8.4 (common on shared hosts
     * where composer runs a different binary than the web SAPI) it would
     * accept 8.3 syntax that the 8.2 target cannot parse.
     */
    private static function isTarget82(): bool
    {
        return PHP_VERSION_ID >= 80200 && PHP_VERSION_ID < 80300;
    }

    /**
     * Parse-check the emitted code. Under real PHP 8.2 TOKEN_PARSE is
     * authoritative for the target; on any other engine it still catches
     * broken rewrites but cannot prove 8.2 compatibility — processInner
     * logs that distinction explicitly instead of silently claiming it.
     */
    private static function validPhp82(string $code): bool
    {
        try {
            token_get_all($code, TOKEN_PARSE);
        } catch (\ParseError $e) {
            return false;
        }
        if (self::isTarget82()) {
            return true;
        }
        // Non-8.2 engine: TOKEN_PARSE passing is necessary but NOT
        // sufficient — reject anything that still carries syntax markers we
        // were supposed to lower (typed consts / readonly-anon / ::{}).
        return !preg_match(
            '/::(?:\s|\/\*.*?\*\/)*\{|new\s+readonly\s+class\b|const\s+[?\w\\\\|&\x80-\xff]+[\w\x80-\xff|&\\\\]*\s+\$?[A-Za-z_]/us',
            $code
        );
    }

    /**
     * Temp file + atomic rename — a concurrent worker must never see a
     * half-written PHP file (truncate-then-write is a transient parse error).
     */
    private static function writeAtomic(string $path, string $contents): bool
    {
        $tmp = @tempnam(dirname($path), '.php82shim-');
        if ($tmp === false || @file_put_contents($tmp, $contents) === false) {
            if ($tmp !== false) {
                @unlink($tmp);
            }
            return false; // never truncate-and-write a live PHP file
        }
        @chmod($tmp, (fileperms($path) ?: 0644) & 0777);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
        return true;
    }

    private static function stubPlatformCheck(string $path): void
    {
        $code = (string)file_get_contents($path);
        // Only touch Composer's generated gate — it always checks PHP_VERSION_ID.
        if (strpos($code, '[php82shim]') !== false || strpos($code, 'PHP_VERSION_ID') === false) {
            return;
        }
        if (self::writeAtomic(
            $path,
            "<?php\n\n// [php82shim] platform check disabled — this site runs Grav on PHP 8.2\n"
        )) {
            self::$log[] = "$path: platform_check stubbed";
        }
    }
}
