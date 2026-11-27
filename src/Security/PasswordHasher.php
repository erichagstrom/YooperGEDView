<?php

declare(strict_types=1);

namespace YooperGEDView\Security;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * Stores and checks passwords as Argon2id hashes. Hashes in any other format are refused, not converted:
 * PhpGedView's MD5-crypt hashes are too weak to keep, so every old account sets a new password.
 */
final class PasswordHasher
{
    private const array OPTIONS = ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1];

    public function hash(#[SensitiveParameter] string $password): string
    {
        if ($password === '') {
            throw new InvalidArgumentException('A password cannot be empty.');
        }

        return password_hash($password, PASSWORD_ARGON2ID, self::OPTIONS);
    }

    /**
     * Takes as long for a missing or old-format hash as for a real one, so the answer time does not tell a
     * caller whether an account exists.
     */
    public function verify(#[SensitiveParameter] string $password, string $hash): bool
    {
        if (!$this->isArgon2id($hash)) {
            password_hash($password, PASSWORD_ARGON2ID, self::OPTIONS);

            return false;
        }

        return password_verify($password, $hash);
    }

    /**
     * True when a hash that verifies was made with weaker settings than today's and should be replaced.
     */
    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, self::OPTIONS);
    }

    private function isArgon2id(string $hash): bool
    {
        return password_get_info($hash)['algo'] === PASSWORD_ARGON2ID;
    }
}
