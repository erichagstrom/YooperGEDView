<?php

declare(strict_types=1);

namespace YooperGEDView\Security;

use InvalidArgumentException;

/**
 * The rules for the session cookie and for ending sessions that are no longer used.
 */
final readonly class SessionPolicy
{
    public function __construct(private int $idleSeconds)
    {
        if ($idleSeconds < 1) {
            throw new InvalidArgumentException('The idle time must be at least one second.');
        }
    }

    /**
     * Settings for session_set_cookie_params(). The cookie ends with the browser session, is not readable by
     * scripts, is not sent on requests made by other sites, and is sent over HTTPS only when the site uses it.
     *
     * @return array{lifetime: int, path: string, secure: bool, httponly: bool, samesite: 'Lax'}
     */
    public function cookieOptions(string $path, bool $https): array
    {
        return [
            'lifetime' => 0,
            'path' => $path === '' ? '/' : $path,
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    /**
     * True when the last request in a session is too long ago for the session to carry on.
     */
    public function isIdle(int $lastActivity, int $now): bool
    {
        return $now - $lastActivity > $this->idleSeconds;
    }
}
