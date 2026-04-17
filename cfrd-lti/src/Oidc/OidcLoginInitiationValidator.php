<?php

namespace Cfrd\Lti\Oidc;

use DomainException;

/**
 * Valida parámetros del paso de inicio OIDC iniciado por la herramienta (third-party login).
 *
 * @see https://www.imsglobal.org/spec/security/v1p0/ — flujo LTI 1.3
 */
final class OidcLoginInitiationValidator
{
    /**
     * @param  array<string, string|null>  $query  Normalmente $request->query()
     * @return array{iss: string, login_hint: string, client_id: string, lti_message_hint: string, target_link_uri: string}
     */
    public function validate(array $query, array $allowedTargetLinkUris = []): array
    {
        $iss = $this->req($query, 'iss');
        $loginHint = $this->req($query, 'login_hint');
        $clientId = $this->req($query, 'client_id');
        $ltiMessageHint = $this->req($query, 'lti_message_hint');
        $targetLinkUri = $this->req($query, 'target_link_uri');

        if ($allowedTargetLinkUris !== [] && ! in_array($targetLinkUri, $allowedTargetLinkUris, true)) {
            throw new DomainException('target_link_uri no permitido para esta herramienta.');
        }

        return [
            'iss' => $iss,
            'login_hint' => $loginHint,
            'client_id' => $clientId,
            'lti_message_hint' => $ltiMessageHint,
            'target_link_uri' => $targetLinkUri,
        ];
    }

    /**
     * @param  array<string, string|null>  $query
     */
    private function req(array $query, string $key): string
    {
        $v = $query[$key] ?? null;
        if (! is_string($v) || $v === '') {
            throw new DomainException("Parámetro OIDC requerido ausente o vacío: {$key}");
        }

        return $v;
    }
}
