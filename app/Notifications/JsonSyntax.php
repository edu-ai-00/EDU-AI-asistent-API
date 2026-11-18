<?php

namespace App\Notifications;

/**
 * Finds where a JSON document breaks (spec §3.2: invalid rule JSON is
 * rejected with the line and column of the error).
 *
 * json_decode() only says *that* the input is invalid, not where, so this is
 * a small strict RFC 8259 scanner run only after json_decode() has already
 * failed. It validates syntax and nothing else; the first error throws
 * JsonSyntaxError with a byte offset and a Czech description.
 */
final class JsonSyntax
{
    private int $pos = 0;

    private int $length;

    private function __construct(private readonly string $json)
    {
        $this->length = strlen($json);
    }

    /** @throws JsonSyntaxError */
    public static function check(string $json): void
    {
        $scanner = new self($json);
        $scanner->skipWhitespace();

        if ($scanner->pos >= $scanner->length) {
            throw new JsonSyntaxError('dokument je prázdný.', 0);
        }

        $scanner->value(0);
        $scanner->skipWhitespace();

        if ($scanner->pos < $scanner->length) {
            $scanner->fail('za koncem dokumentu následuje další text');
        }
    }

    /**
     * 1-based line and column (in characters) of a byte offset.
     *
     * @return array{0: int, 1: int}
     */
    public static function lineAndColumn(string $json, int $offset): array
    {
        $before = substr($json, 0, max(0, $offset));
        $line = substr_count($before, "\n") + 1;
        $lineStart = strrpos($before, "\n");
        $current = $lineStart === false ? $before : substr($before, $lineStart + 1);

        return [$line, mb_strlen($current, 'UTF-8') + 1];
    }

    private function value(int $depth): void
    {
        if ($depth > 512) {
            $this->fail('příliš hluboké zanoření');
        }

        $this->skipWhitespace();
        $char = $this->json[$this->pos] ?? null;

        match (true) {
            $char === null => $this->fail('neočekávaný konec, chybí hodnota'),
            $char === '{' => $this->object($depth),
            $char === '[' => $this->array($depth),
            $char === '"' => $this->string(),
            $char === 't' => $this->literal('true'),
            $char === 'f' => $this->literal('false'),
            $char === 'n' => $this->literal('null'),
            $char === '-' || ctype_digit($char) => $this->number(),
            default => $this->fail(sprintf('neočekávaný znak „%s“', $this->charAt())),
        };
    }

    private function object(int $depth): void
    {
        $this->pos++; // {
        $this->skipWhitespace();

        if (($this->json[$this->pos] ?? null) === '}') {
            $this->pos++;

            return;
        }

        while (true) {
            $this->skipWhitespace();

            if (($this->json[$this->pos] ?? null) !== '"') {
                $this->fail('očekáván název klíče v uvozovkách');
            }

            $this->string();
            $this->skipWhitespace();

            if (($this->json[$this->pos] ?? null) !== ':') {
                $this->fail('za názvem klíče chybí dvojtečka');
            }

            $this->pos++;
            $this->value($depth + 1);
            $this->skipWhitespace();

            $char = $this->json[$this->pos] ?? null;
            if ($char === ',') {
                $this->pos++;

                continue;
            }

            if ($char === '}') {
                $this->pos++;

                return;
            }

            $this->fail('očekávána čárka nebo „}“');
        }
    }

    private function array(int $depth): void
    {
        $this->pos++; // [
        $this->skipWhitespace();

        if (($this->json[$this->pos] ?? null) === ']') {
            $this->pos++;

            return;
        }

        while (true) {
            $this->value($depth + 1);
            $this->skipWhitespace();

            $char = $this->json[$this->pos] ?? null;
            if ($char === ',') {
                $this->pos++;

                continue;
            }

            if ($char === ']') {
                $this->pos++;

                return;
            }

            $this->fail('očekávána čárka nebo „]“');
        }
    }

    private function string(): void
    {
        $start = $this->pos;
        $this->pos++; // opening quote

        while ($this->pos < $this->length) {
            $char = $this->json[$this->pos];

            if ($char === '"') {
                $this->pos++;

                return;
            }

            if ($char === '\\') {
                $next = $this->json[$this->pos + 1] ?? null;

                if ($next === 'u') {
                    if (!preg_match('/\G[0-9a-fA-F]{4}/', $this->json, $m, 0, $this->pos + 2)) {
                        $this->pos++;
                        $this->fail('neplatná escape sekvence \\u');
                    }
                    $this->pos += 6;

                    continue;
                }

                if ($next === null || !str_contains('"\\/bfnrt', $next)) {
                    $this->pos++;
                    $this->fail('neplatná escape sekvence');
                }

                $this->pos += 2;

                continue;
            }

            if (ord($char) < 0x20) {
                $this->fail('v řetězci je nepovolený řídicí znak (např. odřádkování)');
            }

            $this->pos++;
        }

        $this->pos = $start;
        $this->fail('neukončený řetězec, chybí uzavírací uvozovky');
    }

    private function number(): void
    {
        if (!preg_match('/\G-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?/', $this->json, $m, 0, $this->pos)) {
            $this->fail('neplatné číslo');
        }

        $this->pos += strlen($m[0]);
    }

    private function literal(string $word): void
    {
        if (substr($this->json, $this->pos, strlen($word)) !== $word) {
            $this->fail(sprintf('neočekávaný znak „%s“', $this->charAt()));
        }

        $this->pos += strlen($word);
    }

    private function skipWhitespace(): void
    {
        while ($this->pos < $this->length && str_contains(" \t\n\r", $this->json[$this->pos])) {
            $this->pos++;
        }
    }

    /** The (possibly multibyte) character at the cursor. */
    private function charAt(): string
    {
        return mb_substr(substr($this->json, $this->pos, 4), 0, 1, 'UTF-8');
    }

    /** @throws JsonSyntaxError */
    private function fail(string $message): never
    {
        throw new JsonSyntaxError($message . '.', $this->pos);
    }
}
