<?php

namespace EduLazaro\Laratext;

/**
 * Finds text('key', 'Source') calls in JavaScript, TypeScript, Vue and Svelte files.
 *
 * Each call is matched on its own, from its name to its second argument. The
 * file is never followed as a whole: JSX text and Vue or Svelte markup carry
 * unescaped apostrophes ("l'Andorra") that would derail anything tracking
 * strings across the file.
 */
class ScriptTexts
{
    /**
     * Where a call may start: not inside a longer name, not a method of another
     * object (post.text), but this.$text from an Alpine component is a call.
     */
    protected const BOUNDARY = '(?:(?<=\bthis\.)|(?<![\w$.]))';

    /**
     * A one-line literal: single, double or back quotes.
     */
    protected const KEY = '(["\'`])((?:(?!\1)[^\\\\\r\n]|\\\\.)*)\1';

    /**
     * @param array<int, string> $functions Names to look for, such as text and $text
     */
    public function __construct(protected array $functions)
    {
    }

    /**
     * @param string $content
     * @return array{texts: array<string, string>, unreadable: array<int, array{line: int, call: string}>}
     */
    public function extract(string $content): array
    {
        $texts = [];
        $read = [];

        $names = implode('|', array_map(fn ($name) => preg_quote($name, '/'), $this->functions));
        $call = self::BOUNDARY.'(?:'.$names.')\s*\(\s*';
        $value = '(["\'`])((?:(?!\3)[^\\\\]|\\\\.)*)\3';

        // The second argument has to end where the call does or at the next
        // argument, which rules out text('key', 'a' + b).
        $twoArguments = '/'.$call.self::KEY.'\s*,\s*'.$value.'(?=\s*[,)])/su';
        $oneArgument = '/'.$call.self::KEY.'\s*\)/u';

        if (preg_match_all($twoArguments, $content, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === false) {
            return ['texts' => [], 'unreadable' => [['line' => 0, 'call' => 'Not valid UTF-8']]];
        }

        foreach ($matches as $match) {
            [$keyQuote, $key, $valueQuote, $text] = [$match[1][0], $match[2][0], $match[3][0], $match[4][0]];

            if ($this->isInterpolated($keyQuote, $key) || $this->isInterpolated($valueQuote, $text)) {
                continue;
            }

            $texts[$this->unescape($key)] = $this->unescape($text);
            $read[$match[0][1]] = true;
        }

        preg_match_all($oneArgument, $content, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        foreach ($matches as $match) {
            if ($this->isInterpolated($match[1][0], $match[2][0])) {
                continue;
            }

            $key = $this->unescape($match[2][0]);
            $texts[$key] ??= self::keyToText($key);
            $read[$match[0][1]] = true;
        }

        return ['texts' => $texts, 'unreadable' => $this->unreadable($content, $call, $read)];
    }

    /**
     * Every call the patterns above could not read: a variable, a concatenation
     * or an interpolated template literal. Reported so none is lost in silence.
     *
     * @param string $content
     * @param string $call
     * @param array<int, bool> $read Offsets of the calls that were read
     * @return array<int, array{line: int, call: string}>
     */
    protected function unreadable(string $content, string $call, array $read): array
    {
        preg_match_all('/'.$call.'/u', $content, $matches, PREG_OFFSET_CAPTURE);

        $unreadable = [];

        foreach ($matches[0] as [$found, $offset]) {
            if (isset($read[$offset])) {
                continue;
            }

            // A definition, function text(key, source), is not a call.
            if (preg_match('/\bfunction\s*$/', substr($content, max(0, $offset - 20), min(20, $offset)))) {
                continue;
            }

            $unreadable[] = [
                'line' => substr_count($content, "\n", 0, $offset) + 1,
                'call' => trim(strtok(substr($content, $offset, 80), "\n")),
            ];
        }

        return $unreadable;
    }

    /**
     * Only a template literal interpolates in JavaScript: "a$b" is a literal.
     */
    protected function isInterpolated(string $quote, string $string): bool
    {
        return $quote === '`' && str_contains($string, '${');
    }

    /**
     * Turns JavaScript escapes into the characters they stand for.
     */
    public function unescape(string $string): string
    {
        return preg_replace_callback(
            '/\\\\(?:u([dD][89abAB][0-9a-fA-F]{2})\\\\u([dD][c-fC-F][0-9a-fA-F]{2})|u\{([0-9a-fA-F]+)\}|u([0-9a-fA-F]{4})|x([0-9a-fA-F]{2})|(\r\n|[\s\S]))/u',
            function (array $m) {
                if (($m[1] ?? '') !== '') {
                    $high = hexdec($m[1]);
                    $low = hexdec($m[2]);

                    return mb_chr((($high - 0xD800) << 10) + ($low - 0xDC00) + 0x10000, 'UTF-8');
                }

                foreach ([3, 4, 5] as $group) {
                    if (($m[$group] ?? '') !== '') {
                        return mb_chr(hexdec($m[$group]), 'UTF-8');
                    }
                }

                return match ($m[6]) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    'b' => "\x08",
                    'f' => "\f",
                    'v' => "\v",
                    '0' => "\0",
                    "\n", "\r", "\r\n", "\u{2028}", "\u{2029}" => '',
                    default => $m[6],
                };
            },
            $string
        );
    }

    /**
     * The text a one-argument call gets: the last part of the key, words capitalised.
     */
    public static function keyToText(string $key): string
    {
        $parts = explode('.', $key);

        return ucwords(str_replace('_', ' ', end($parts)));
    }
}
