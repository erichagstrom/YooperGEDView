<?php

declare(strict_types=1);

namespace YooperGEDView\Upgrade;

/**
 * Reads PhpGedView's pgv_changes.php, the pending edits written as PHP, without running it. The file is taken
 * apart into PHP's own tokens and must consist of nothing but the few statements PhpGedView wrote there:
 *
 *     $pgv_changes = array();
 *     $pgv_changes["I1_tree.ged"] = array();
 *     $change = array();
 *     $change["gid"] = 'I1';
 *     $pgv_changes["I1_tree.ged"][] = $change;
 *
 * Anything else, such as a function call, means the file is not what PhpGedView wrote, and it is refused: an
 * editor of the old site could put code into this file through their user name.
 */
final class PendingChangesFile
{
    private const array IGNORED = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG];

    /**
     * @return array<string, list<array<string, string>>> the changes, by record and tree, as the program keeps them
     *
     * @throws UnreadableFile
     */
    public function read(string $php): array
    {
        $changes = [];
        $current = null;
        foreach ($this->statements($php) as [$line, $statement]) {
            $text = implode(' ', array_map(static fn(array $token): string => $token[0], $statement));
            if ($text === 'var:pgv_changes = array ( )' && $changes === []) {
                continue;
            }
            if (preg_match('/^var:pgv_changes \[ text \] = array \( \)$/', $text) === 1) {
                $changes[$this->text($statement[2], $line)] ??= [];
            } elseif ($text === 'var:change = array ( )') {
                $current = [];
            } elseif ($text === 'var:change [ text ] = text' && $current !== null) {
                $current[$this->text($statement[2], $line)] = $this->text($statement[5], $line);
            } elseif ($text === 'var:pgv_changes [ text ] [ ] = var:change' && $current !== null) {
                $changes[$this->text($statement[2], $line)][] = $current;
                $current = null;
            } else {
                throw new UnreadableFile('Line ' . $line . ' is not something PhpGedView writes in this file.');
            }
        }

        return array_filter($changes, static fn(array $list): bool => $list !== []);
    }

    /**
     * The file's statements, each a list of [kind, source text] pairs, with the line it starts on.
     *
     * @return list<array{int, list<array{string, string}>}>
     */
    private function statements(string $php): array
    {
        $statements = [];
        $statement = [];
        $line = 1;
        $startLine = 1;
        foreach (token_get_all($php) as $token) {
            if (is_array($token)) {
                [$id, $source, $line] = $token;
                if (in_array($id, self::IGNORED, true)) {
                    continue;
                }
                $kind = match ($id) {
                    T_VARIABLE => 'var:' . substr($source, 1),
                    T_CONSTANT_ENCAPSED_STRING => 'text',
                    T_ARRAY => 'array',
                    default => throw new UnreadableFile('Line ' . $line . ' holds "' . trim($source) . '", which PhpGedView does not write in this file.'),
                };
            } else {
                $source = $token;
                $kind = $token;
            }
            if ($kind === ';') {
                if ($statement !== []) {
                    $statements[] = [$startLine, $statement];
                }
                $statement = [];

                continue;
            }
            if ($statement === []) {
                $startLine = $line;
            }
            $statement[] = [$kind, $source];
        }
        if ($statement !== []) {
            throw new UnreadableFile('The file ends in the middle of a statement.');
        }

        return $statements;
    }

    /**
     * What a quoted text in the file stands for.
     *
     * @param array{string, string} $token
     */
    private function text(array $token, int $line): string
    {
        $source = $token[1];
        $inner = substr($source, 1, -1);
        if ($source[0] === "'") {
            return str_replace(['\\\\', "\\'"], ['\\', "'"], $inner);
        }
        // Between double quotes PHP would act on "$" and "\": PhpGedView writes only plain names there.
        if (strpbrk($inner, '$\\') !== false) {
            throw new UnreadableFile('Line ' . $line . ' has a text that PHP would work on, not a plain one.');
        }

        return $inner;
    }
}
