<?php

declare(strict_types=1);

namespace YooperGEDView\Security;

use InvalidArgumentException;
use RuntimeException;

/**
 * Protection against forged requests: each session holds a random token that every request changing something
 * must send back. Another site can make a visitor's browser send a request, but cannot read the token.
 */
final class CsrfProtection
{
    /** The name of the form field that carries the token. */
    public const string FIELD = 'csrf';

    private const string TOKEN_PATTERN = '/^[0-9a-f]{64}$/';

    /** An opening form tag, with attribute values that may themselves hold a ">". */
    private const string FORM_TAG_PATTERN = '/<form\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/i';

    private const string POST_METHOD_PATTERN = '/\bmethod\s*=\s*["\']?post\b/i';

    public function newToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * True only when the request sent the session's own token.
     */
    public function isValid(string $sessionToken, string $sentToken): bool
    {
        return preg_match(self::TOKEN_PATTERN, $sessionToken) === 1 && hash_equals($sessionToken, $sentToken);
    }

    /**
     * The hidden form field that sends the token back.
     *
     * This class writes HTML because the inherited pages have no template layer yet to do it for them. The
     * token is checked to be plain hexadecimal, so nothing in it needs escaping.
     */
    public function field(string $token): string
    {
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            throw new InvalidArgumentException('Not a token made by newToken().');
        }

        return '<input type="hidden" name="' . self::FIELD . '" value="' . $token . '">';
    }

    /**
     * Adds the token field to every form in a page that is sent by POST, so that no page can leave it out.
     */
    public function protectForms(string $html, string $token): string
    {
        $field = $this->field($token);
        $protected = preg_replace_callback(
            self::FORM_TAG_PATTERN,
            static fn(array $match): string => preg_match(self::POST_METHOD_PATTERN, $match[0]) === 1
                ? $match[0] . $field
                : $match[0],
            $html,
        );
        if ($protected === null) {
            throw new RuntimeException('The page could not be searched for forms: ' . preg_last_error_msg());
        }

        return $protected;
    }
}
