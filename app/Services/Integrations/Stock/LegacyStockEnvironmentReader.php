<?php

namespace App\Services\Integrations\Stock;

use RuntimeException;

/** Reads literal legacy configuration without loading or executing its PHP. */
class LegacyStockEnvironmentReader
{
    private array $tokens = [];

    private int $position = 0;

    private int $depth = 0;

    public function read(string $path): array
    {
        if (! is_file($path) || ! is_readable($path) || filesize($path) > 2 * 1024 * 1024) {
            throw new RuntimeException('Staru konfiguraciju nije moguće sigurno pročitati.');
        }
        $source = @file_get_contents($path);
        if ($source === false || strlen($source) > 2 * 1024 * 1024) {
            throw new RuntimeException('Staru konfiguraciju nije moguće sigurno pročitati.');
        }
        $this->tokens = array_values(array_filter(token_get_all($source), fn ($token) => ! is_array($token)
            || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG], true)));
        try {
            foreach ($this->tokens as $index => $token) {
                if (! is_array($token) || ! in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)
                    || strtolower(ltrim($token[1], '\\')) !== 'define'
                    || $this->text($index + 1) !== '(') {
                    continue;
                }
                $previous = $this->tokens[$index - 1] ?? null;
                if (is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
                    continue;
                }
                $name = $this->tokens[$index + 2] ?? null;
                if (! is_array($name) || $name[0] !== T_CONSTANT_ENCAPSED_STRING
                    || $this->stringLiteral($name[1]) !== 'OC_ENV' || $this->text($index + 3) !== ',') {
                    continue;
                }
                $this->position = $index + 4;
                $configuration = $this->value();
                $api = is_array($configuration) ? ($configuration['import']['api'] ?? null) : null;
                if (! is_array($api)) {
                    return [];
                }
                $connection = [];
                foreach (['url', 'username', 'password', 'token'] as $key) {
                    if (! is_string($api[$key] ?? null) || $api[$key] === '') {
                        return [];
                    }
                    $connection[$key] = $api[$key];
                }
                if (is_string($api['url_image_suffix'] ?? null) && $api['url_image_suffix'] !== '') {
                    $connection['url_image_suffix'] = $api['url_image_suffix'];
                }

                return $connection;
            }

            return [];
        } finally {
            // Avoid retaining parsed credentials in a long-lived service instance.
            $this->tokens = [];
            $this->position = 0;
            $this->depth = 0;
        }
    }

    private function value(): mixed
    {
        $token = $this->tokens[$this->position] ?? null;
        if ($token === '[') {
            $value = $this->arrayLiteral(']');
        } elseif (is_array($token) && $token[0] === T_ARRAY && $this->text($this->position + 1) === '(') {
            $this->position++;
            $value = $this->arrayLiteral(')');
        } elseif (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
            $value = $this->stringLiteral($token[1]);
            $this->position++;
        } elseif (is_array($token) && in_array($token[0], [T_LNUMBER, T_DNUMBER], true)) {
            // Non-string settings need not be interpreted to recover API strings.
            $value = null;
            $this->position++;
        } elseif (is_array($token) && $token[0] === T_STRING && in_array(strtolower($token[1]), ['true', 'false', 'null'], true)) {
            $value = null;
            $this->position++;
        } else {
            $this->skipExpression();

            return null;
        }
        if (! in_array($this->text($this->position), [',', ']', ')', '=>'], true)) {
            // Concatenation, function calls and interpolation are never evaluated.
            $this->skipExpression();

            return null;
        }

        return $value;
    }

    private function arrayLiteral(string $closing): array
    {
        if (++$this->depth > 64) {
            throw new RuntimeException('Stara konfiguracija nema podržanu strukturu.');
        }
        $this->position++;
        $result = [];
        while ($this->position < count($this->tokens)) {
            if ($this->text($this->position) === $closing) {
                $this->position++;
                $this->depth--;

                return $result;
            }
            if ($this->text($this->position) === '...') {
                // An unpacked runtime array could replace a previously literal
                // import/api branch, so its configuration cannot be recovered.
                throw new RuntimeException('Stara konfiguracija nema podržanu strukturu.');
            }
            $first = $this->value();
            if ($this->text($this->position) === '=>') {
                $this->position++;
                $value = $this->value();
                if (is_string($first)) {
                    $result[$first] = $value;
                }
            } else {
                $result[] = $first;
            }
            if ($this->text($this->position) === ',') {
                $this->position++;
            } elseif ($this->text($this->position) !== $closing) {
                throw new RuntimeException('Stara konfiguracija nema podržanu strukturu.');
            }
        }

        throw new RuntimeException('Stara konfiguracija nema podržanu strukturu.');
    }

    private function skipExpression(): void
    {
        $depth = 0;
        while ($this->position < count($this->tokens)) {
            $text = $this->text($this->position);
            if ($depth === 0 && in_array($text, [',', ']', ')', '=>'], true)) {
                return;
            }
            if (in_array($text, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                if ($depth === 0) {
                    return;
                }
                $depth--;
            }
            $this->position++;
        }
    }

    private function text(int $position): string
    {
        $token = $this->tokens[$position] ?? '';

        return is_array($token) ? $token[1] : $token;
    }

    private function stringLiteral(string $literal): string
    {
        $quote = $literal[0];
        $source = substr($literal, 1, -1);
        $result = '';
        $length = strlen($source);
        for ($index = 0; $index < $length; $index++) {
            if ($source[$index] !== '\\' || $index + 1 >= $length) {
                $result .= $source[$index];

                continue;
            }
            $next = $source[$index + 1];
            if ($quote === "'") {
                if (in_array($next, ["'", '\\'], true)) {
                    $result .= $next;
                    $index++;
                } else {
                    $result .= '\\';
                }

                continue;
            }
            $escapes = ['n' => "\n", 'r' => "\r", 't' => "\t", 'v' => "\v", 'e' => "\x1B", 'f' => "\f", '\\' => '\\', '$' => '$', '"' => '"'];
            if (array_key_exists($next, $escapes)) {
                $result .= $escapes[$next];
                $index++;
            } elseif ($next === 'x' && preg_match('/^[0-9a-fA-F]{1,2}/', substr($source, $index + 2), $hex)) {
                $result .= chr(hexdec($hex[0]));
                $index += 1 + strlen($hex[0]);
            } elseif (preg_match('/^[0-7]{1,3}/', substr($source, $index + 1), $octal)) {
                $result .= chr(octdec($octal[0]) % 256);
                $index += strlen($octal[0]);
            } elseif ($next === 'u' && preg_match('/^u\{([0-9a-fA-F]+)\}/', substr($source, $index + 1), $unicode)) {
                $codepoint = hexdec($unicode[1]);
                if ($codepoint > 0x10FFFF || ($codepoint >= 0xD800 && $codepoint <= 0xDFFF)) {
                    throw new RuntimeException('Stara konfiguracija sadrži nepodržan tekstualni zapis.');
                }
                $result .= mb_chr((int) $codepoint, 'UTF-8');
                $index += strlen($unicode[0]);
            } else {
                $result .= '\\';
            }
        }

        return $result;
    }
}
