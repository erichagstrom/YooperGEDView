<?php

declare(strict_types=1);

namespace YooperGEDView\Upgrade;

/**
 * Looks through a settings file from the old site for anything beyond settings. PhpGedView kept each tree's
 * settings as PHP that assigns values to variables, and this program still loads those files, so whatever
 * else they hold would run. The file is not run here: it is taken apart into PHP's tokens and searched for
 * the things a settings file has no use for.
 */
final class PhpFileInspector
{
    /**
     * The functions that PhpGedView's own settings files call: those in the two templates of 4.3.0, and those in
     * the guard that earlier versions put at the top of the file, which answers a request for the file itself:
     *
     *     if (stristr($_SERVER["SCRIPT_NAME"], basename(__FILE__))!==false) { print "..."; exit; }
     *
     * A site that began on an earlier version still has that guard in its files.
     */
    private const array EXPECTED_CALLS = ['defined', 'header', 'array', 'substr', 'set_time_limit', 'stristr', 'basename', 'preg_match'];

    /** @var array<int, string> */
    private const array UNEXPECTED_TOKENS = [
        T_EVAL => 'runs text as code (eval)',
        T_INCLUDE => 'loads another file (include)',
        T_INCLUDE_ONCE => 'loads another file (include_once)',
        T_REQUIRE => 'loads another file (require)',
        T_REQUIRE_ONCE => 'loads another file (require_once)',
        T_NEW => 'creates an object (new)',
        T_FUNCTION => 'defines a function',
        T_FN => 'defines a function',
        T_CLASS => 'defines a class',
        T_ECHO => 'prints something (echo)',
        T_PRINT => 'prints something (print)',
        T_INLINE_HTML => 'has text outside the PHP tags',
        T_OBJECT_OPERATOR => 'uses an object (->)',
        T_DOUBLE_COLON => 'uses a class (::)',
        T_START_HEREDOC => 'has a block of text that PHP works on (heredoc)',
        T_GOTO => 'jumps (goto)',
        T_HALT_COMPILER => 'cuts the file short (__halt_compiler)',
    ];

    /**
     * What was found that a settings file should not hold, each with its line; empty when nothing was.
     *
     * @return list<string>
     */
    public function findings(string $php): array
    {
        $tokens = array_values(array_filter(
            token_get_all($php),
            static fn(array|string $token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));
        $findings = [];
        $line = 1;
        foreach ($tokens as $index => $token) {
            $next = $tokens[$index + 1] ?? null;
            if (!is_array($token)) {
                // A single character carries no line of its own: what follows it is on the same one.
                $here = is_array($next) ? $next[2] : $line;
                if ($token === '`') {
                    $findings[] = 'line ' . $here . ': runs a command (backticks)';
                }
                if ($token === '$') {
                    $findings[] = 'line ' . $here . ': names a variable by the content of another ($$)';
                }

                continue;
            }
            [$id, $source, $line] = $token;
            // Printing one fixed text, as the old guard does, cannot do anything but show that text.
            $printsFixedText = in_array($id, [T_PRINT, T_ECHO], true)
                && is_array($next) && $next[0] === T_CONSTANT_ENCAPSED_STRING && ($tokens[$index + 2] ?? null) === ';';
            if ($printsFixedText) {
                continue;
            }
            if (isset(self::UNEXPECTED_TOKENS[$id])) {
                $findings[] = 'line ' . $line . ': ' . self::UNEXPECTED_TOKENS[$id];
            } elseif ($id === T_STRING && $next === '(' && !in_array(strtolower($source), self::EXPECTED_CALLS, true)) {
                $findings[] = 'line ' . $line . ': calls ' . $source . '()';
            } elseif ($id === T_VARIABLE && $next === '(') {
                $findings[] = 'line ' . $line . ': calls a function named by a variable';
            }
        }

        return array_values(array_unique($findings));
    }
}
