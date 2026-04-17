<?php

namespace Cfrd\Lti\Support;

use InvalidArgumentException;

/**
 * Exporta una clave pública RSA PEM a un objeto JWK (RSA, uso firma).
 */
final class JwkExporter
{
    /**
     * @return array{kty: string, use: string, alg: string, kid: string, n: string, e: string}
     */
    public static function fromRsaPem(string $publicKeyPem, string $kid): array
    {
        $res = openssl_pkey_get_public($publicKeyPem);
        if ($res === false) {
            throw new InvalidArgumentException('Clave pública RSA inválida.');
        }

        $details = openssl_pkey_get_details($res);
        if ($details === false || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            throw new InvalidArgumentException('Se esperaba RSA.');
        }

        $n = self::encodeBase64Url($details['rsa']['n']);
        $e = self::encodeBase64Url($details['rsa']['e']);

        return [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => $kid,
            'n' => $n,
            'e' => $e,
        ];
    }

    private static function encodeBase64Url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
