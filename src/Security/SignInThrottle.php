<?php

declare(strict_types=1);

namespace YooperGEDView\Security;

use InvalidArgumentException;

/**
 * Limits guessing of passwords. After too many failed sign-ins for one user name, or from one address, within
 * the window, further attempts are refused without looking at the password until old failures leave the window.
 * Refused attempts are not counted, so guessing on cannot keep an account locked for longer than the window.
 */
final readonly class SignInThrottle
{
    /** How long the record of attempts is kept as an audit log. */
    private const int KEEP_SECONDS = 90 * 24 * 3600;

    public function __construct(
        private SignInAttemptRepository $attempts,
        private int $maxFailuresPerUser = 10,
        private int $maxFailuresPerAddress = 50,
        private int $windowSeconds = 900,
    ) {
        if ($maxFailuresPerUser < 1 || $maxFailuresPerAddress < 1 || $windowSeconds < 1) {
            throw new InvalidArgumentException('The limits and the window must be at least one.');
        }
    }

    public function isBlocked(string $userName, string $address, int $now): bool
    {
        $since = $now - $this->windowSeconds;

        return $this->attempts->countFailuresForUser($this->normalize($userName), $since) >= $this->maxFailuresPerUser
            || $this->attempts->countFailuresFromAddress($address, $since) >= $this->maxFailuresPerAddress;
    }

    public function recordFailure(string $userName, string $address, int $now): void
    {
        $this->attempts->add($this->normalize($userName), $address, false, $now);
    }

    public function recordSuccess(string $userName, string $address, int $now): void
    {
        $this->attempts->add($this->normalize($userName), $address, true, $now);
        $this->attempts->deleteBefore($now - self::KEEP_SECONDS);
    }

    /**
     * User names differing only in case or surrounding spaces count as one, and fit the column.
     */
    private function normalize(string $userName): string
    {
        return mb_substr(mb_strtolower(trim($userName)), 0, 64);
    }
}
