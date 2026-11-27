<?php

declare(strict_types=1);

namespace YooperGEDView\User;

use InvalidArgumentException;

/**
 * The details of an administrator to be created, checked on the way in.
 */
final readonly class Administrator
{
    public function __construct(
        public string $userName,
        public string $email,
        public string $firstName,
        public string $lastName,
    ) {
        // The same characters the inherited pages accept in a user name, and the length of their column.
        if (preg_match('/^[^<>"%{};\x00-\x1F]{1,32}$/u', $userName) !== 1 || trim($userName) !== $userName) {
            throw new InvalidArgumentException(
                'A user name is 1 to 32 characters, without < > " % { } ; and without spaces at either end.',
            );
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('"' . $email . '" is not a mail address.');
        }
        foreach ([$firstName, $lastName] as $name) {
            if ($name === '' || mb_strlen($name) > 100 || preg_match('/[<>\x00-\x1F]/', $name) === 1) {
                throw new InvalidArgumentException('A name is 1 to 100 characters, without < or >.');
            }
        }
    }
}
