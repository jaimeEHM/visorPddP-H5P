<?php

namespace Cfrd\Lti\DeepLinking;

use Cfrd\Lti\LtiClaim;
use Firebase\JWT\JWT;
use InvalidArgumentException;

/**
 * Construye un JWT de tipo LtiDeepLinkingResponse firmado por la herramienta (RS256).
 */
final class DeepLinkingResponseBuilder
{
    public function __construct(
        private string $issuer,
        private string $privateKeyPem,
        private string $keyId,
        private int $ttlSeconds = 600,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $contentItems
     */
    public function buildSignedJwt(
        string $audience,
        string $deploymentId,
        array $contentItems,
        ?string $subject = null,
    ): string {
        if ($contentItems === []) {
            throw new InvalidArgumentException('content_items no puede estar vacío.');
        }

        $now = time();
        $payload = [
            'iss' => $this->issuer,
            'aud' => $audience,
            'iat' => $now,
            'exp' => $now + $this->ttlSeconds,
            LtiClaim::VERSION => LtiClaim::LTI_VERSION,
            LtiClaim::MESSAGE_TYPE => LtiClaim::DEEP_LINKING_RESPONSE,
            LtiClaim::DEPLOYMENT_ID => $deploymentId,
            LtiClaim::CONTENT_ITEMS => $contentItems,
        ];

        if ($subject !== null && $subject !== '') {
            $payload['sub'] = $subject;
        }

        $key = openssl_pkey_get_private($this->privateKeyPem);
        if ($key === false) {
            throw new InvalidArgumentException('Clave privada RSA inválida.');
        }

        return JWT::encode($payload, $key, 'RS256', $this->keyId);
    }
}
