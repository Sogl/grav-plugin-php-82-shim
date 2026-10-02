<?php

// [php82shim] regression corpus — run after any Transpiler/PatchManager edit:
//   php user/plugins/php-82-shim/tests/selftest.php
// Each case: input code + expected transpile output (or 'same' + flags).

$base = dirname(__DIR__) . '/classes/Php82Shim';
require_once $base . '/ShimLog.php';
require_once $base . '/Transpiler.php';

use Grav\Plugin\Php82Shim\Transpiler;

$cases = [
    // --- typed class constants -------------------------------------------------
    'typed const stripped' => [
        '<?php class A { private const int LIMIT = 42; }',
        '<?php class A { private const LIMIT = 42; }',
        'flag' => 'typed class const -> untyped (LIMIT)',
    ],
    'typed const multiline + comment' => [
        "<?php class A {\n    public const\n        /* why */ string FOO = 'x';\n}",
        "<?php class A {\n    public const\n        FOO = 'x';\n}",
    ],
    'function-level const untouched' => [
        '<?php function f() { const X = 1; }',
        'same', // `const` outside class body — never had types, never touched
    ],

    // --- readonly anonymous class ----------------------------------------------
    'new readonly class demoted' => [
        '<?php $a = new readonly class { public int $x = 1; };',
        '<?php $a = new class { public int $x = 1; };',
    ],
    'named readonly class kept' => [
        '<?php readonly class B {}',
        'same', // legal since 8.2
    ],
    'new readonly + comment demoted' => [
        '<?php $a = new readonly /* why */ class {};',
        '<?php $a = new class {};',
    ],

    // --- dynamic class constant fetch ------------------------------------------
    'dynamic const fetch' => [
        '<?php $v = Foo::{$name};',
        "<?php \$v = \\constant(Foo::class . '::' . (\$name));",
    ],
    'dynamic const fetch namespaced' => [
        '<?php $v = \Vendor\Pkg\C::{"K" . $x};',
        "<?php \$v = \\constant(\\Vendor\\Pkg\\C::class . '::' . (\"K\" . \$x));",
    ],
    'dynamic const via $object' => [
        '<?php $v = $obj::{$key};',
        "<?php \$v = \\constant((is_object(\$__php82v = \$obj) ? get_class(\$__php82v) : \$__php82v) . '::' . (\$key));",
    ],
    'dynamic METHOD call untouched' => [
        '<?php $o::{$f}(...$params);',
        'same',
    ],
    'parent dynamic method untouched' => [
        '<?php class B extends A { function f() { return parent::{$this->m}(); } }',
        'same',
    ],
    'method call w/ comment before paren untouched' => [
        "<?php \$o::{\$f} /* note */ ();",
        'same',
    ],
    'nested dynamic const' => [
        '<?php $v = A::{B::{$x}};',
        "<?php \$v = \\constant(A::class . '::' . (\\constant(B::class . '::' . (\$x))));",
    ],
    'dynamic const on Foo::BAR' => [
        '<?php $v = Foo::BAR::{$n};',
        "<?php \$v = \\constant((is_object(\$__php82v = Foo::BAR) ? get_class(\$__php82v) : \$__php82v) . '::' . (\$n));",
    ],
    'dynamic const on array elem' => [
        '<?php $v = $a[0]::{$n};',
        "<?php \$v = \\constant((is_object(\$__php82v = \$a[0]) ? get_class(\$__php82v) : \$__php82v) . '::' . (\$n));",
    ],

    // --- unshimmable / unsupported ---------------------------------------------
    'trait as final flagged' => [
        '<?php class C { use SomeTrait { foo as final; } }',
        'same',
        'flag' => 'UNSHIMMABLE',
    ],
    'static non-const init flagged' => [
        '<?php function f() { static $x = compute(); return $x; }',
        'same',
        'flag' => 'UNSHIMMABLE',
    ],
    'static $var init flagged' => [
        '<?php function f($p) { static $x = $p; return $x; }',
        'same',
        'flag' => 'UNSHIMMABLE',
    ],
    'static paren expr flagged' => [
        '<?php function f() { static $x = (int)$_ENV["N"]; return $x; }',
        'same',
        'flag' => 'UNSHIMMABLE',
    ],
    'static const init NOT flagged' => [
        '<?php function f() { static $x = SOME_CONST; return $x; }',
        'same',
    ],
    // real-world false positives from the vendor scan — these are legal on
    // every PHP version and must stay quiet
    'file-scope static array NOT flagged' => [
        '<?php static $data = array (0 => 1, "a" => "b");',
        'same',
    ],
    'static property array NOT flagged' => [
        '<?php class C { protected static $opts = array("a" => 1); }',
        'same',
    ],
    'static scalar init NOT flagged' => [
        '<?php function f() { static $n = 42, $s = "x", $b = true; }',
        'same',
    ],
    'static ::class init NOT flagged' => [
        '<?php function f() { static $c = self::class; }',
        'same',
    ],
    'static const cast NOT flagged' => [
        '<?php function f() { static $x = (int) PHP_VERSION_ID; }',
        'same',
    ],
    'static comma-chain call flagged' => [
        '<?php function f() { static $a = 1, $b = compute(); }',
        'same',
        'flag' => 'UNSHIMMABLE',
    ],
    'static ternary call flagged' => [
        '<?php function f() { static $x = SOME_CONST ?: compute(); }',
        'same',
        'flag' => 'UNSHIMMABLE',
    ],
    'static method call flagged' => [
        '<?php function f() { static $x = Foo::make(); }',
        'same',
        'flag' => 'UNSHIMMABLE',
    ],
    'static ::CONST init NOT flagged' => [
        '<?php function f() { static $x = Foo::BAR; }',
        'same',
    ],
    // `new` in static initializers is legal since PHP 8.1 — must NOT flag
    'static new init NOT flagged' => [
        '<?php function f() { static $d = new \stdClass(); return $d; }',
        'same',
    ],
    'commented needle NOT flagged' => [
        '<?php // $r->getFloat(0, 1) — not a real call',
        'same', // comments are stripped before scanning
    ],

    // --- dynamic const fetch — edge LHSes (oracle regressions) ------------------
    'comment between :: and {' => [
        '<?php $v = Foo:: /* note */ {$name};',
        "<?php \$v = \\constant(Foo::class . '::' . (\$name));",
    ],
    'parenthesized LHS' => [
        '<?php $v = ($a . $b)::{$n};',
        "<?php \$v = \\constant((is_object(\$__php82v = (\$a . \$b)) ? get_class(\$__php82v) : \$__php82v) . '::' . (\$n));",
    ],
    'call-result LHS' => [
        '<?php $v = foo()::{$n};',
        "<?php \$v = \\constant((is_object(\$__php82v = foo()) ? get_class(\$__php82v) : \$__php82v) . '::' . (\$n));",
    ],
    'new-expr LHS' => [
        '<?php $v = (new X())::{$n};',
        "<?php \$v = \\constant((is_object(\$__php82v = (new X())) ? get_class(\$__php82v) : \$__php82v) . '::' . (\$n));",
    ],
    'nullsafe LHS' => [
        '<?php $v = $o?->cls::{$n};',
        "<?php \$v = \\constant((is_object(\$__php82v = \$o?->cls) ? get_class(\$__php82v) : \$__php82v) . '::' . (\$n));",
    ],

    // --- guarded-call analysis ---------------------------------------------------
    'version-guarded needle NOT unshimmable' => [
        "<?php if (PHP_VERSION_ID >= 80300) { \$r->getBytesFromString('x'); }",
        'same',
        'flag' => 'GUARDED',
    ],
    'reversed version guard NOT unshimmable' => [
        "<?php if (80300 <= PHP_VERSION_ID) { \$r->getBytesFromString('x'); }",
        'same',
        'flag' => 'GUARDED',
    ],
    'else of true-on-8.2 guard IS dead too' => [
        "<?php if (PHP_VERSION_ID < 80300) { echo 'ok'; } else { \$r->getBytesFromString('x'); }",
        'same',
        'flag' => 'GUARDED',
    ],
    'else of false-on-8.2 guard stays UNSHIMMABLE' => [
        "<?php if (PHP_VERSION_ID >= 80300) { echo 'new'; } else { \$r->getBytesFromString('x'); }",
        'same',
        'flag' => 'UNSHIMMABLE',
    ],
    'function_exists-guarded needle NOT unshimmable' => [
        "<?php if (function_exists('socket_atmark')) { socket_atmark(\$s); }",
        'same',
        'flag' => 'GUARDED',
    ],
    'unguarded needle stays UNSHIMMABLE' => [
        "<?php \$r->getBytesFromString('x');",
        'same',
        'flag' => 'UNSHIMMABLE',
    ],
    'unguarded posix_sysconf flagged' => [
        '<?php $n = posix_sysconf(1);',
        'same',
        'flag' => 'UNSHIMMABLE',
    ],
    'version-guarded static init NOT unshimmable' => [
        "<?php function f() { if (PHP_VERSION_ID >= 80300) { static \$x = compute(); return \$x; } }",
        'same',
        'flag' => 'GUARDED',
    ],
];

$pass = $fail = 0;
foreach ($cases as $name => $case) {
    $in = $case[0];
    $expected = $case[1];
    $wantFlag = $case['flag'] ?? null;

    $entries = (new ReflectionClass(Transpiler::class))->getProperty('log');
    $entries->setAccessible(true);
    $entries->setValue(null, []);
    $out = Transpiler::transpile($in, 'case.php');
    $gotLog = array_merge($entries->getValue(), Transpiler::scan($in, 'case.php'));

    $ok = true;
    if ($expected === 'same') {
        if ($out !== $in) { $ok = false; }
    } elseif ($out !== $expected) {
        $ok = false;
    }
    if ($wantFlag) {
        $hit = false;
        foreach ($gotLog as $line) {
            if (str_contains($line, $wantFlag)) { $hit = true; break; }
        }
        if (!$hit) { $ok = false; }
    }

    if ($ok) {
        echo "PASS  $name\n";
        $pass++;
    } else {
        $fail++;
        echo "FAIL  $name\n";
        echo "      in:  " . str_replace("\n", '\n', $in) . "\n";
        echo "      out: " . str_replace("\n", '\n', $out) . "\n";
        if ($expected !== 'same') {
            echo "      exp: " . str_replace("\n", '\n', $expected) . "\n";
        }
    }
    $entries->setValue(null, []);
}

echo "\n-- transpiler: $pass passed, $fail failed\n";

// ---- polyfill parity checks -------------------------------------------------

require_once dirname(__DIR__) . '/classes/Php82Shim/polyfill.php';

$check = function (string $name, callable $fn) use (&$pass, &$fail) {
    try {
        $ok = (bool)$fn();
    } catch (\Throwable $e) {
        $ok = false;
        echo "  (threw: {$e->getMessage()})\n";
    }
    if ($ok) { echo "PASS  $name\n"; $pass++; }
    else     { echo "FAIL  $name\n"; $fail++; }
};

// json_validate
$check('json_validate valid',      fn() => json_validate('{"a":1}') === true);
$check('json_validate invalid',    fn() => json_validate('{bad') === false);
$check('json_validate flags',      fn() => json_validate("\"\xC3\"", 512, JSON_INVALID_UTF8_IGNORE) === true);
$check('json_validate depth',      fn() => json_validate('[[[[]]]]', 2) === false && json_validate('[[[[]]]]', 512) === true);

// str_increment / str_decrement — carry behaviour per PHP 8.3 spec
$check('str_increment carry',      fn() => str_increment('Zz9') === 'AAa0');
$check('str_increment simple',     fn() => str_increment('Az9') === 'Ba0');
$check('str_increment empty throws', function () {
    try { str_increment(''); return false; } catch (\ValueError) { return true; }
});
$check('str_increment non-alnum throws', function () {
    try { str_increment('=5'); return false; } catch (\ValueError) { return true; }
});
$check('str_decrement simple',     fn() => str_decrement('B') === 'A');
$check('str_decrement floor throws', function () {
    try { str_decrement('A'); return false; } catch (\ValueError) { return true; }
});

// mb_str_pad — multibyte-aware (str_pad counts bytes, 'хй' is 4 bytes/2 chars)
$check('mb_str_pad utf8',          fn() => mb_str_pad('хй', 5, '+') === 'хй+++');
$check('mb_str_pad left',          fn() => mb_str_pad('ab', 5, '-', STR_PAD_LEFT) === '---ab');
$check('mb_str_pad both',          fn() => mb_strlen(mb_str_pad('ab', 5, '-', STR_PAD_BOTH)) === 5);

// mb_ucfirst / mb_lcfirst (8.3+ surface)
$check('mb_ucfirst utf8',          fn() => mb_ucfirst('éllo') === 'Éllo');
$check('mb_lcfirst utf8',          fn() => mb_lcfirst('ÉLLO') === 'éLLO');

// stream_context_set_options — array form
$check('stream_context_set_options', function () {
    $ctx = stream_context_create();
    return stream_context_set_options($ctx, ['http' => ['timeout' => 5]]) === true;
});

// ---- platform_check detection regression (the -36 off-by-N stub miss) ------

$check('plugin vendor platform_check stubbed', function () {
    $tmp = sys_get_temp_dir() . '/php82shim-pc-' . getmypid();
    @mkdir($tmp . '/vendor/composer', 0777, true);
    @mkdir($tmp . '/src', 0777, true);
    file_put_contents(
        $tmp . '/vendor/composer/platform_check.php',
        "<?php\nif (!(PHP_VERSION_ID >= 80300)) { http_response_code(500); exit(1); }\n"
    );
    // A same-named file outside vendor/composer must be left alone.
    file_put_contents($tmp . '/src/platform_check.php', "<?php echo 'mine';\n");
    Transpiler::process($tmp);
    $stubbed = str_contains(
        (string)file_get_contents($tmp . '/vendor/composer/platform_check.php'),
        'php82shim'
    );
    $untouched = str_contains(
        (string)file_get_contents($tmp . '/src/platform_check.php'),
        "echo 'mine'"
    );
    foreach ([$tmp . '/vendor/composer/platform_check.php', $tmp . '/src/platform_check.php'] as $f) {
        @unlink($f);
    }
    @rmdir($tmp . '/vendor/composer');
    @rmdir($tmp . '/vendor');
    @rmdir($tmp . '/src');
    @rmdir($tmp);
    return $stubbed && $untouched;
});

// class stubs exist for catch/instanceof parity
$check('#[\\Override] stub',       fn() => class_exists(\Override::class) || class_exists('Override'));
$check('Date exception stubs',     fn() => class_exists('DateRangeError') && class_exists('DateInvalidOperationException'));

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
