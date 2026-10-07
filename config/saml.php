<?php

return [
    'provider' => env('SAML_PROVIDER', 'keycloak'),
    'auto_create_user' => env('SAML_AUTO_CREATE_USER', true),
    'allow_email_linking' => env('SAML_ALLOW_EMAIL_LINKING', false),
    'sp' => [
        'entity_id' => env('SAML_SP_ENTITY_ID', 'laravel-saml'),
        'acs_url' => env('SAML_SP_ACS_URL', 'http://localhost:8000/saml/acs'),
        'sls_url' => env('SAML_SP_SLS_URL', 'http://localhost:8000/saml/sls'),
        'cert_path' => env('SAML_SP_CERT_PATH', 'storage/saml/sp.pem'),
        'key_path' => env('SAML_SP_KEY_PATH', 'storage/saml/sp.key'),
        'name_id_format' => env('SAML_NAME_ID_FORMAT', 'urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified'),
    ],
    'idp' => [
        'entity_id' => env('SAML_IDP_ENTITY_ID', 'http://localhost:18001/realms/saml-demo'),
        'sso_url' => env('SAML_IDP_SSO_URL', 'http://localhost:18001/realms/saml-demo/protocol/saml'),
        'slo_url' => env('SAML_IDP_SLO_URL', 'http://localhost:18001/realms/saml-demo/protocol/saml'),
        'cert_path' => env('SAML_IDP_CERT_PATH', 'storage/saml/keycloak-idp.pem'),
    ],
    'attributes' => [
        'username' => env('SAML_ATTRIBUTE_USERNAME', 'username'),
        'email' => env('SAML_ATTRIBUTE_EMAIL', 'email'),
        'first_name' => env('SAML_ATTRIBUTE_FIRST_NAME', 'firstName'),
        'last_name' => env('SAML_ATTRIBUTE_LAST_NAME', 'lastName'),
    ],
    'security' => [
        'authn_requests_signed' => env('SAML_AUTHN_REQUESTS_SIGNED', true),
        'logout_requests_signed' => env('SAML_LOGOUT_REQUESTS_SIGNED', true),
        'logout_responses_signed' => env('SAML_LOGOUT_RESPONSES_SIGNED', true),
        'want_assertions_signed' => env('SAML_WANT_ASSERTIONS_SIGNED', true),
    ],
    // Explicit local provisioning only; never used by the SAML authentication service.
    'local_metadata_url' => env('SAML_LOCAL_METADATA_URL', 'http://keycloak:8080/realms/saml-demo/protocol/saml/descriptor'),
];
