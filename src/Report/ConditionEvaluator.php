<?php

declare(strict_types=1);

namespace YooperGEDView\Report;

use Closure;

/**
 * Works out whether a condition in a report file holds, such as
 *
 *     ($notes==1) and (@NOTE!='')        @BIRT:DATE!='' or @BIRT:PLAC!=''        stristr(@NAME,$name)!==false
 *
 * "$name" is a report variable and "@TAG" a value from the record being printed. The condition is read as a
 * small language of its own: values are looked up while it is worked out and are never made part of its
 * text. The inherited code built a line of PHP from the condition and the record's values and ran it, so a
 * value in a family tree could be written to run as code.
 *
 * What is understood: text in single or double quotes, numbers, true and false, $variables, @TAGS, the
 * comparisons == != === !== < > <= >= (LT and GT as words too), ! && || and or, brackets, and the one
 * function the bundled reports use, stristr(). Comparisons follow PHP's own rules for == and <.
 */
final class ConditionEvaluator
{
    private const string TOKEN_PATTERN = '/\G(?:'
        . '(?<space>\s+)'
        . '|(?<variable>\$\w+)'
        . '|(?<tag>@[\w:.]+)'
        . '|(?<text>\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")'
        . '|(?<number>\d+(?:\.\d+)?)'
        . '|(?<operator>===|!==|==|!=|<=|>=|&&|\|\||[<>!(),])'
        . '|(?<word>[A-Za-z_]\w*)'
        . ')/A';

    private const array COMPARISONS = ['==', '!=', '===', '!==', '<', '>', '<=', '>='];

    /** @var list<array{string, string}> each token as [kind, text] */
    private array $tokens = [];

    private int $position = 0;

    /** @var Closure(string): (string|int|float|bool|null) */
    private Closure $variable;

    /** @var Closure(string): string */
    private Closure $tag;

    /**
     * @param Closure(string): (string|int|float|bool|null) $variable the value of a report variable, by name
     * @param Closure(string): string $tag the value of a tag in the current record, by name such as BIRT:DATE
     *
     * @throws InvalidCondition when the condition cannot be read
     */
    public function holds(string $condition, Closure $variable, Closure $tag): bool
    {
        $this->tokens = $this->tokenize($condition);
        $this->position = 0;
        $this->variable = $variable;
        $this->tag = $tag;

        $value = $this->wordOr();
        if ($this->position < count($this->tokens)) {
            throw new InvalidCondition('Unexpected "' . $this->tokens[$this->position][1] . '" in: ' . $condition);
        }

        return (bool) $value;
    }

    /**
     * @return list<array{string, string}>
     */
    private function tokenize(string $condition): array
    {
        $tokens = [];
        $offset = 0;
        $length = strlen($condition);
        while ($offset < $length) {
            if (preg_match(self::TOKEN_PATTERN, $condition, $match, 0, $offset) !== 1) {
                throw new InvalidCondition('Cannot read "' . substr($condition, $offset, 20) . '" in: ' . $condition);
            }
            $offset += strlen($match[0]);
            foreach (['variable', 'tag', 'text', 'number', 'operator', 'word'] as $kind) {
                if (isset($match[$kind]) && $match[$kind] !== '') {
                    $tokens[] = [$kind, $match[$kind]];
                }
            }
        }

        return $tokens;
    }

    /**
     * The next token's text when it is an operator or word with one of the given spellings, taking it.
     *
     * @param list<string> $spellings
     */
    private function take(array $spellings): ?string
    {
        if (!isset($this->tokens[$this->position])) {
            return null;
        }
        [$kind, $text] = $this->tokens[$this->position];
        if ($kind === 'operator' && in_array($text, $spellings, true)) {
            $this->position++;

            return $text;
        }
        if ($kind === 'word' && in_array(strtolower($text), $spellings, true)) {
            $this->position++;

            return strtolower($text);
        }

        return null;
    }

    // Loosest first, as in PHP: or, and, ||, &&, comparison, !.

    private function wordOr(): string|int|float|bool|null
    {
        $value = $this->wordAnd();
        while ($this->take(['or']) !== null) {
            $right = $this->wordAnd();
            $value = (bool) $value || (bool) $right;
        }

        return $value;
    }

    private function wordAnd(): string|int|float|bool|null
    {
        $value = $this->symbolOr();
        while ($this->take(['and']) !== null) {
            $right = $this->symbolOr();
            $value = (bool) $value && (bool) $right;
        }

        return $value;
    }

    private function symbolOr(): string|int|float|bool|null
    {
        $value = $this->symbolAnd();
        while ($this->take(['||']) !== null) {
            $right = $this->symbolAnd();
            $value = (bool) $value || (bool) $right;
        }

        return $value;
    }

    private function symbolAnd(): string|int|float|bool|null
    {
        $value = $this->comparison();
        while ($this->take(['&&']) !== null) {
            $right = $this->comparison();
            $value = (bool) $value && (bool) $right;
        }

        return $value;
    }

    private function comparison(): string|int|float|bool|null
    {
        $left = $this->negation();
        $operator = $this->take([...self::COMPARISONS, 'lt', 'gt']);
        if ($operator === null) {
            return $left;
        }
        $right = $this->negation();

        return match ($operator) {
            '==' => $this->looselyEqual($left, $right),
            '!=' => !$this->looselyEqual($left, $right),
            '===' => $left === $right,
            '!==' => $left !== $right,
            '<', 'lt' => $left < $right,
            '>', 'gt' => $left > $right,
            '<=' => $left <= $right,
            default => $left >= $right,
        };
    }

    /**
     * Equality as PHP 8's "==" has it, which is what report files were written for: "1" equals 1, "" equals a
     * missing value, and text that is not a number never equals a number.
     */
    private function looselyEqual(string|int|float|bool|null $left, string|int|float|bool|null $right): bool
    {
        if (is_string($left) && $right === null) {
            return $left === '';
        }
        if ($left === null && is_string($right)) {
            return $right === '';
        }
        if ($left === null || $right === null || is_bool($left) || is_bool($right)) {
            return (bool) $left === (bool) $right;
        }
        if (is_numeric($left) && is_numeric($right)) {
            return (float) $left === (float) $right;
        }

        return (string) $left === (string) $right;
    }

    private function negation(): string|int|float|bool|null
    {
        if ($this->take(['!']) !== null) {
            return !(bool) $this->negation();
        }

        return $this->operand();
    }

    private function operand(): string|int|float|bool|null
    {
        if (!isset($this->tokens[$this->position])) {
            throw new InvalidCondition('The condition ends too soon.');
        }
        [$kind, $text] = $this->tokens[$this->position];
        $this->position++;

        if ($kind === 'variable') {
            return ($this->variable)(substr($text, 1));
        }
        if ($kind === 'tag') {
            return ($this->tag)(substr($text, 1));
        }
        if ($kind === 'text') {
            return stripslashes(substr($text, 1, -1));
        }
        if ($kind === 'number') {
            return str_contains($text, '.') ? (float) $text : (int) $text;
        }
        if ($kind === 'operator' && $text === '(') {
            $value = $this->wordOr();
            $this->expect(')');

            return $value;
        }
        if ($kind === 'word') {
            return match (strtolower($text)) {
                'true' => true,
                'false' => false,
                'stristr' => $this->stristr(),
                default => throw new InvalidCondition('Unknown word "' . $text . '".'),
            };
        }

        throw new InvalidCondition('Unexpected "' . $text . '".');
    }

    private function stristr(): string|false
    {
        $this->expect('(');
        $haystack = $this->wordOr();
        $this->expect(',');
        $needle = $this->wordOr();
        $this->expect(')');

        return stristr($this->asText($haystack), $this->asText($needle));
    }

    private function expect(string $operator): void
    {
        if ($this->take([$operator]) === null) {
            throw new InvalidCondition('Expected "' . $operator . '".');
        }
    }

    private function asText(string|int|float|bool|null $value): string
    {
        return match (true) {
            $value === null, $value === false => '',
            $value === true => '1',
            default => (string) $value,
        };
    }
}
