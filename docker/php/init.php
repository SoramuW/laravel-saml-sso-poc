<?php

// Local-only provisioning: keep generated private keys out of source control.
$root = dirname(__DIR__, 2);
if (! is_file($root.'/.env')) {
    copy($root.'/.env.example', $root.'/.env');
}
foreach (['storage/saml', 'storage/logs', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'bootstrap/cache'] as $dir) {
    if (! is_dir($root.'/'.$dir)) {
        mkdir($root.'/'.$dir, 0775, true);
    }
}
$keyPath = $root.'/storage/saml/sp.key';
$certPath = $root.'/storage/saml/sp.pem';
if (! is_file($keyPath) && ! is_file($certPath)) {
    $key = openssl_pkey_new(['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'Laravel SAML local SP'], $key, ['digest_alg' => 'sha256']);
    $cert = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
    openssl_pkey_export($key, $private);
    openssl_x509_export($cert, $public);
    file_put_contents($keyPath, $private);
    chmod($keyPath, 0600);
    file_put_contents($certPath, $public);
}
if (! is_file($keyPath) || ! is_file($certPath)) {
    throw new RuntimeException('SP key/certificate pair is incomplete.');
}
$realm = json_decode(file_get_contents($root.'/docker/keycloak/realm-export.json'), true, 512, JSON_THROW_ON_ERROR);
$client = &$realm['clients'][0];
$client['clientId'] = getenv('SAML_SP_ENTITY_ID') ?: 'laravel-saml';
$client['redirectUris'] = [getenv('SAML_SP_ACS_URL') ?: 'http://localhost:8000/saml/acs'];
$client['attributes']['saml_assertion_consumer_url_post'] = $client['redirectUris'][0];
$client['attributes']['saml_single_logout_service_url_redirect'] = getenv('SAML_SP_SLS_URL') ?: 'http://localhost:8000/saml/sls';
$client['attributes']['saml.signing.certificate'] = preg_replace('/-----[^-]+-----|\s/', '', file_get_contents($certPath));
file_put_contents('/realm-import/realm-export.json', json_encode($realm, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "Local SP certificate and Keycloak import prepared.\n";
