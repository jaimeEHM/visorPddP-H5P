<?php

namespace Cfrd\Lti\Jwt;

use Cfrd\Lti\LtiClaim;
use DomainException;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\SignatureInvalidException;
use stdClass;

/**
 * Valida un id_token OIDC del LMS usando el JWKS de la plataforma (RS256).
 */
final class IdTokenValidator
{
    public function __construct(
        private int $leewaySeconds = 120,
    ) {
        JWT::$leeway = $this->leewaySeconds;
    }

    /**
     * @param  array<string, mixed>  $platformJwks  Documento JWKS (clave "keys")
     *
     * @throws DomainException
     */
    public function validate(
        string $jwt,
        array $platformJwks,
        string $expectedIssuer,
        string $expectedAudience,
        ?string $expectedNonce = null,
    ): stdClass {
        try {
            // Canvas (y otros LMS) pueden omitir "alg" en sus JWKs; Firebase\JWT\JWK exige ese campo.
            // En LTI 1.3 el id_token es típicamente RS256, así que lo normalizamos si falta.
            if (isset($platformJwks['keys']) && is_array($platformJwks['keys'])) {
                foreach ($platformJwks['keys'] as $i => $jwk) {
                    if (! is_array($jwk)) {
                        continue;
                    }
                    if (! isset($jwk['alg']) || ! is_string($jwk['alg']) || trim($jwk['alg']) === '') {
                        $platformJwks['keys'][$i]['alg'] = 'RS256';
                    }
                }
            }

            $keys = JWK::parseKeySet($platformJwks);
            $payload = JWT::decode($jwt, $keys);
        } catch (ExpiredException|SignatureInvalidException|BeforeValidException|\UnexpectedValueException $e) {
            throw new DomainException('id_token inválido: '.$e->getMessage(), 0, $e);
        }

        if (($payload->iss ?? null) !== $expectedIssuer) {
            throw new DomainException('id_token: iss no coincide.');
        }

        $aud = $payload->aud ?? null;
        $audOk = is_string($aud)
            ? $aud === $expectedAudience
            : (is_array($aud) && in_array($expectedAudience, $aud, true));
        if (! $audOk) {
            throw new DomainException('id_token: aud no contiene el client_id esperado.');
        }

        if ($expectedNonce !== null && (($payload->nonce ?? null) !== $expectedNonce)) {
            throw new DomainException('id_token: nonce no coincide.');
        }

        $version = $payload->{LtiClaim::VERSION} ?? null;
        if ($version !== LtiClaim::LTI_VERSION) {
            throw new DomainException('id_token: versión LTI no es 1.3.0.');
        }

        return $payload;
    }
}
