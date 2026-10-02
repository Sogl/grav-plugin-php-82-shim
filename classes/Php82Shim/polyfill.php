<?php

/**
 * PHP 8.3/8.4 function/attribute shims. Loaded by the plugin before anything
 * else. Everything is guarded so a future move to real 8.3+ keeps this file
 * inert.
 *
 * The str_increment/decrement, mb_str_pad, json_validate implementations are
 * ported verbatim from symfony/polyfill-php83 (MIT, (c) Fabien Potencier) —
 * those are unit-tested against native behavior; hand-rolled versions had
 * subtle divergences.
 */

if (!function_exists('json_validate')) {
    function json_validate(string $json, int $depth = 512, int $flags = 0): bool
    {
        if (0 !== $flags && \defined('JSON_INVALID_UTF8_IGNORE') && \JSON_INVALID_UTF8_IGNORE !== $flags) {
            throw new \ValueError('json_validate(): Argument #3 ($flags) must be a valid flag (allowed flags: JSON_INVALID_UTF8_IGNORE)');
        }
        if ('' === $json) {
            json_decode('');
            return false;
        }
        if ($depth <= 0) {
            json_decode('0');
            throw new \ValueError('json_validate(): Argument #2 ($depth) must be greater than 0');
        }
        if ($depth > 0x7FFFFFFF) {
            json_decode('0');
            throw new \ValueError(sprintf('json_validate(): Argument #2 ($depth) must be less than %d', 0x7FFFFFFF));
        }
        json_decode($json, true, $depth, $flags);
        return \JSON_ERROR_NONE === json_last_error();
    }
}

if (!function_exists('str_increment')) {
    function str_increment(string $string): string
    {
        if ('' === $string) {
            throw new \ValueError('str_increment(): Argument #1 ($string) cannot be empty');
        }
        if (!preg_match('/^[a-zA-Z0-9]++$/D', $string)) {
            throw new \ValueError('str_increment(): Argument #1 ($string) must be composed only of alphanumeric ASCII characters');
        }
        for ($i = \strlen($string) - 1; $i >= 0; --$i) {
            $char = $string[$i];
            if ('z' === $char) {
                $string[$i] = 'a';
                continue;
            }
            if ('Z' === $char) {
                $string[$i] = 'A';
                continue;
            }
            if ('9' === $char) {
                $string[$i] = '0';
                continue;
            }
            $string[$i] = \chr(\ord($char) + 1);
            return $string;
        }
        switch ($string[0]) {
            case 'a': return 'a' . $string;
            case 'A': return 'A' . $string;
        }
        return '1' . $string;
    }
}

if (!function_exists('str_decrement')) {
    function str_decrement(string $string): string
    {
        if ('' === $string) {
            throw new \ValueError('str_decrement(): Argument #1 ($string) cannot be empty');
        }
        if (!preg_match('/^[a-zA-Z0-9]++$/D', $string)) {
            throw new \ValueError('str_decrement(): Argument #1 ($string) must be composed only of alphanumeric ASCII characters');
        }
        if ('0' === $string[0] || 'a' === $string || 'A' === $string) {
            throw new \ValueError(sprintf('str_decrement(): Argument #1 ($string) "%s" is out of decrement range', $string));
        }
        for ($i = \strlen($string) - 1; $i >= 0; --$i) {
            $char = $string[$i];
            if ('a' === $char) {
                $string[$i] = 'z';
                continue;
            }
            if ('A' === $char) {
                $string[$i] = 'Z';
                continue;
            }
            if ('0' === $char) {
                $string[$i] = '9';
                continue;
            }
            $string[$i] = \chr(\ord($char) - 1);
            return '0' === $string[0] && isset($string[1]) ? substr($string, 1) : $string;
        }
        return substr($string, 1);
    }
}

if (!function_exists('mb_str_pad')) {
    function mb_str_pad(string $string, int $length, string $pad_string = ' ', int $pad_type = STR_PAD_RIGHT, ?string $encoding = null): string
    {
        if (null === $encoding) {
            $encoding = mb_internal_encoding();
        }
        try {
            if (!@mb_check_encoding('', $encoding)) {
                throw new \ValueError(sprintf('mb_str_pad(): Argument #5 ($encoding) must be a valid encoding, "%s" given', $encoding));
            }
        } catch (\ValueError $e) {
            throw new \ValueError(sprintf('mb_str_pad(): Argument #5 ($encoding) must be a valid encoding, "%s" given', $encoding));
        }
        if (0 >= $padStringLength = mb_strlen($pad_string, $encoding)) {
            throw new \ValueError('mb_str_pad(): Argument #3 ($pad_string) must be a non-empty string');
        }
        if (!in_array($pad_type, [STR_PAD_RIGHT, STR_PAD_LEFT, STR_PAD_BOTH], true)) {
            throw new \ValueError('mb_str_pad(): Argument #4 ($pad_type) must be STR_PAD_LEFT, STR_PAD_RIGHT, or STR_PAD_BOTH');
        }
        $paddingRequired = $length - mb_strlen($string, $encoding);
        if ($paddingRequired < 1) {
            return $string;
        }
        switch ($pad_type) {
            case STR_PAD_LEFT:
                $leftPaddingLength = $paddingRequired;
                break;
            case STR_PAD_RIGHT:
                $leftPaddingLength = 0;
                break;
            default:
                $leftPaddingLength = intdiv($paddingRequired, 2);
        }
        $rightPaddingLength = $paddingRequired - $leftPaddingLength;

        return str_repeat($pad_string, intdiv($leftPaddingLength, $padStringLength)) . mb_substr($pad_string, 0, $leftPaddingLength % $padStringLength, $encoding)
            . $string
            . str_repeat($pad_string, intdiv($rightPaddingLength, $padStringLength)) . mb_substr($pad_string, 0, $rightPaddingLength % $padStringLength, $encoding);
    }
}

// mb_ucfirst/mb_lcfirst are actually PHP 8.4 additions — shim them anyway.
if (!function_exists('mb_ucfirst')) {
    function mb_ucfirst(string $string, ?string $encoding = null): string
    {
        $enc = $encoding ?? mb_internal_encoding();
        return mb_strtoupper(mb_substr($string, 0, 1, $enc), $enc) . mb_substr($string, 1, null, $enc);
    }
}

if (!function_exists('mb_lcfirst')) {
    function mb_lcfirst(string $string, ?string $encoding = null): string
    {
        $enc = $encoding ?? mb_internal_encoding();
        return mb_strtolower(mb_substr($string, 0, 1, $enc), $enc) . mb_substr($string, 1, null, $enc);
    }
}

if (!function_exists('stream_context_set_options')) {
    function stream_context_set_options($context, array $options): bool
    {
        // PHP 8.2's singular setter already accepts a nested options array.
        return stream_context_set_option($context, $options);
    }
}

// PHP 8.3 #[\Override] attribute stub. Covers class-existence only — the
// engine-level "method must override something" validation is 8.3-only and
// cannot be emulated; harmless on >=8.3 where the real one exists.
if (!class_exists('Override')) {
    #[\Attribute(\Attribute::TARGET_METHOD)]
    class Override
    {
    }
}

// PHP 8.3 date exception hierarchy — stubs so `catch`/`instanceof` sites in
// packages don't fatal on a missing class. NOTE: the 8.2 date extension will
// never *throw* these — they exist purely so catch blocks/type checks compile.
if (!class_exists('DateError')) {
    class DateError extends \Error
    {
    }
}
if (!class_exists('DateObjectError')) {
    class DateObjectError extends DateError
    {
    }
}
if (!class_exists('DateRangeError')) {
    class DateRangeError extends DateError
    {
    }
}
if (!class_exists('DateException')) {
    class DateException extends \Exception
    {
    }
}
if (!class_exists('DateInvalidOperationException')) {
    class DateInvalidOperationException extends DateException
    {
    }
}
if (!class_exists('DateInvalidTimeZoneException')) {
    class DateInvalidTimeZoneException extends DateException
    {
    }
}
if (!class_exists('DateMalformedIntervalStringException')) {
    class DateMalformedIntervalStringException extends DateException
    {
    }
}
if (!class_exists('DateMalformedPeriodStringException')) {
    class DateMalformedPeriodStringException extends DateException
    {
    }
}
if (!class_exists('DateMalformedStringException')) {
    class DateMalformedStringException extends DateException
    {
    }
}
