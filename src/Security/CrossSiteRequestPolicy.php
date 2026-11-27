<?php

declare(strict_types=1);

namespace YooperGEDView\Security;

/**
 * Tells whether a request was started by another web site. Many inherited pages change things on a plain link
 * (delete, accept, sign out), which the form token does not cover; a signed-in visitor sent to such a link by
 * another site must confirm first.
 *
 * Browsers say where a request comes from in the Sec-Fetch-Site header, and older ones in Referer. A request
 * with neither is taken as the visitor's own (an address typed in, a bookmark, a program that is not a browser).
 */
final class CrossSiteRequestPolicy
{
    /**
     * @param string $secFetchSite the Sec-Fetch-Site request header, or '' when not sent
     * @param string $referer the Referer request header, or '' when not sent
     * @param string $ownHost the host name this site is being reached by, with its port if it has one
     */
    public function isCrossSite(string $secFetchSite, string $referer, string $ownHost): bool
    {
        if ($secFetchSite !== '') {
            // "same-site" is a sibling such as other.example.org, which need not be under the same control.
            return !in_array(strtolower($secFetchSite), ['same-origin', 'none'], true);
        }
        if ($referer === '') {
            return false;
        }

        return $this->hostOf($referer) !== strtolower($ownHost);
    }

    /**
     * The host, and port if given, of an address; '' when it has none.
     */
    private function hostOf(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            return '';
        }

        return strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
