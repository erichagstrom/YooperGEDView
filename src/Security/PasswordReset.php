<?php

declare(strict_types=1);

namespace YooperGEDView\Security;

/**
 * Lets a user who has lost their password choose a new one through a link sent to their mail address. The
 * link holds a random token that works once, for an hour. Nothing about the account changes until the link is
 * used, so asking for a reset in someone else's name does them no harm beyond one message.
 */
final readonly class PasswordReset
{
    /** How long a link works. */
    public const int VALID_SECONDS = 3600;

    /** How long a user must wait before another link is sent: asking again and again sends one message. */
    public const int WAIT_SECONDS = 300;

    public function __construct(private PasswordResetRepository $requests) {}

    /**
     * Makes a token for the user and forgets their earlier ones. Null when one was made too recently.
     */
    public function request(int $userId, int $now): ?string
    {
        if ($now - $this->requests->newestTimeForUser($userId) < self::WAIT_SECONDS) {
            return null;
        }
        $this->requests->deleteBefore($now - self::VALID_SECONDS);
        $this->requests->deleteForUser($userId);
        $token = bin2hex(random_bytes(32));
        $this->requests->add($userId, $this->hash($token), $now);

        return $token;
    }

    /**
     * The user a token was made for, while it is still good; null otherwise. The token stays usable.
     */
    public function userFor(string $token, int $now): ?int
    {
        if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            return null;
        }
        $request = $this->requests->find($this->hash($token));
        if ($request === null || $now - $request['createdTime'] > self::VALID_SECONDS) {
            return null;
        }

        return $request['userId'];
    }

    /**
     * Uses a token up: returns its user and forgets every token of theirs. Null when the token is not good.
     */
    public function complete(string $token, int $now): ?int
    {
        $userId = $this->userFor($token, $now);
        if ($userId !== null) {
            $this->requests->deleteForUser($userId);
        }

        return $userId;
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
