<?php

declare(strict_types=1);

namespace YooperGEDView\Security;

/**
 * The one rule for a new password, wherever it is set: on the site's pages and on the command line.
 * Length is what counts; nothing is asked about the kinds of characters.
 */
final class PasswordPolicy
{
    public const int MIN_LENGTH = 12;

    /** Long enough for any passphrase, short enough that hashing it cannot be used to tie the server up. */
    public const int MAX_LENGTH = 1000;

    /**
     * Why the password may not be used, in a sentence for the person choosing it; null when it may.
     */
    public function refusal(#[\SensitiveParameter] string $password): ?string
    {
        $length = mb_strlen($password);
        if ($length < self::MIN_LENGTH) {
            return 'A password must be at least ' . self::MIN_LENGTH . ' characters long.';
        }
        if ($length > self::MAX_LENGTH) {
            return 'A password can be at most ' . self::MAX_LENGTH . ' characters long.';
        }

        return null;
    }
}
