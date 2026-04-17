<?php

namespace Cfrd\Lti\Nrps;

/**
 * Extrae la URL siguiente de la cabecera Link de NRPS (RFC 5988).
 */
final class NrpsLinkParser
{
    public static function nextUrl(?string $linkHeader): ?string
    {
        if ($linkHeader === null || $linkHeader === '') {
            return null;
        }

        foreach (explode(',', $linkHeader) as $part) {
            $part = trim($part);
            if (preg_match('/<([^>]+)>\s*;\s*rel="next"/i', $part, $m)) {
                return $m[1];
            }
        }

        return null;
    }
}
