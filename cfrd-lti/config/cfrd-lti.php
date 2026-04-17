<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Identidad de la herramienta (LTI Tool)
    |--------------------------------------------------------------------------
    */

    'issuer' => env('LTI_TOOL_ISSUER', env('APP_URL', 'http://localhost')),

    'client_id' => env('LTI_TOOL_CLIENT_ID', 'cfrd-lti-tool'),

    /*
    |--------------------------------------------------------------------------
    | Claves RSA (PEM) para firma de mensajes de la tool y JWKS público
    |--------------------------------------------------------------------------
    */

    'private_key_path' => env('LTI_TOOL_PRIVATE_KEY_PATH', storage_path('app/lti/tool_private.pem')),

    'public_key_path' => env('LTI_TOOL_PUBLIC_KEY_PATH', storage_path('app/lti/tool_public.pem')),

    'key_id' => env('LTI_TOOL_KID', 'cfrd-lti-1'),

    /*
    |--------------------------------------------------------------------------
    | OIDC — tercera parte (login initiation hacia la plataforma)
    |--------------------------------------------------------------------------
    */

    'oidc' => [
        /*
         * URIs de target_link_uri permitidas en login initiation (coincidencia exacta).
         * Por defecto se incluye siempre {APP_URL}/lti/launch (agnóstico al dominio: solo APP_URL).
         * LTI_OIDC_ALLOWED_TARGET_URIS: lista opcional separada por comas (aliases, deep linking, etc.).
         */
        'allowed_target_link_uri_patterns' => (static function (): array {
            $base = rtrim((string) env('APP_URL', ''), '/');
            // Algunos LMS (p. ej. Moodle) envían `target_link_uri` como la raíz del sitio de la tool.
            // Permitimos {APP_URL}/ y {APP_URL}/lti/launch por defecto.
            $defaults = $base !== '' ? [$base.'/', $base.'/lti/launch'] : [];
            $extra = array_filter(array_map('trim', explode(',', (string) env('LTI_OIDC_ALLOWED_TARGET_URIS', ''))));

            return array_values(array_unique(array_merge($defaults, $extra)));
        })(),
    ],

    /*
    |--------------------------------------------------------------------------
    | Plataforma LMS (validación de id_token entrante)
    |--------------------------------------------------------------------------
    */

    'platform' => [
        'issuer' => env('LTI_PLATFORM_ISSUER', ''),
        'client_id' => env('LTI_PLATFORM_CLIENT_ID', ''),
        'jwks' => json_decode((string) env('LTI_PLATFORM_JWKS_JSON', '{"keys":[]}'), true) ?: ['keys' => []],
        'token_endpoint' => env('LTI_PLATFORM_TOKEN_ENDPOINT', ''),
        'authorization_endpoint' => env('LTI_PLATFORM_AUTHORIZATION_ENDPOINT', ''),
    ],

];
