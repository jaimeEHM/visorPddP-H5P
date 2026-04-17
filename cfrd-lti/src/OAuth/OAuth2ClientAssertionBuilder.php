<?php

namespace Cfrd\Lti\OAuth;

use Firebase\JWT\JWT;
use InvalidArgumentException;

/**
 * JWT client assertion para OAuth 2.0 (IMS Security Framework / RFC 7523).
 *
 * @see https://www.imsglobal.org/spec/security/v1p0/
 */
final class OAuth2ClientAssertionBuilder
{
    public function __construct(
        private int $ttlSeconds = 60,
    ) {}

    /**
     * @return string JWT firmado RS256
     */
    public function build(
        string $toolClientId,
        string $tokenEndpointUrl,
        string $privateKeyPem,
        string $kid,
    ): string {
        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false) {
            throw new InvalidArgumentException('Clave privada RSA inválida para client assertion.');
        }

        $now = time();
        $payload = [
            'iss' => $toolClientId,
            'sub' => $toolClientId,
            'aud' => $tokenEndpointUrl,
            'iat' => $now,
            'exp' => $now + $this->ttlSeconds,
            'jti' => bin2hex(random_bytes(16)),
        ];

        return JWT::encode($payload, $key, 'RS256', $kid);
    }
}
