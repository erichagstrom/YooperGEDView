<?php

declare(strict_types=1);

namespace YooperGEDView\Security;

/**
 * The response headers that tell a browser what a page from this site may do. They are sent by the program,
 * so they apply whichever web server is in front of it.
 */
final class SecurityHeaders
{
    /**
     * What a page may load and where it may send data. The inherited pages are full of scripts and styles
     * written into the HTML, and of code run from text, so those cannot be forbidden yet ('unsafe-inline',
     * 'unsafe-eval'). What the policy does stop: scripts, styles, frames and plug-ins from other sites, forms
     * that post to other sites, a changed base address, and other sites showing these pages in a frame.
     */
    private const array CONTENT_SECURITY_POLICY = [
        "default-src 'self'",
        "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
        "style-src 'self' 'unsafe-inline'",
        "img-src 'self' data:",
        "object-src 'self'",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'self'",
    ];

    /**
     * @return array<string, string> header name => value
     */
    public function forPage(bool $https): array
    {
        $headers = [
            'Content-Security-Policy' => implode('; ', self::CONTENT_SECURITY_POLICY),
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'same-origin',
        ];
        if ($https) {
            // One year. Not "includeSubDomains": this site cannot speak for its siblings.
            $headers['Strict-Transport-Security'] = 'max-age=31536000';
        }

        return $headers;
    }
}
