<?php

namespace App\Services;

use OneLogin\Saml2\Auth;
use OneLogin\Saml2\Constants;
use OneLogin\Saml2\Settings;
use OneLogin\Saml2\Utils;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use RuntimeException;

class SamlService
{
    public function auth(): Auth
    {
        return new Auth($this->settings());
    }

    public function validateResponse(string $requestId): Auth
    {
        $auth = $this->auth();
        $auth->processResponse($requestId);
        if ($auth->getErrors() !== [] || ! $auth->isAuthenticated()) {
            throw new RuntimeException('SAML response validation failed: '.implode(', ', $auth->getErrors()).': '.$auth->getLastErrorReason());
        }
        // Enforce mandatory profile fields beyond the toolkit's optional-field checks.
        $xml = new \DOMDocument;
        if (! $xml->loadXML($auth->getLastResponseXML(), LIBXML_NONET)) {
            throw new RuntimeException('Invalid SAML XML.');
        }
        $xpath = new \DOMXPath($xml);
        $xpath->registerNamespace('p', Constants::NS_SAMLP);
        $xpath->registerNamespace('a', Constants::NS_SAML);
        if ($xpath->evaluate('string(/p:Response/@Destination)') !== config('saml.sp.acs_url') ||
            $xpath->evaluate('string(/p:Response/a:Assertion/a:Conditions/a:AudienceRestriction/a:Audience)') !== config('saml.sp.entity_id')) {
            throw new RuntimeException('Required Destination or Audience is missing or mismatched.');
        }
        $conditions = $xpath->query('/p:Response/a:Assertion/a:Conditions')->item(0);
        if (! $conditions || ! $conditions->hasAttribute('NotBefore') || ! $conditions->hasAttribute('NotOnOrAfter')) {
            throw new RuntimeException('Required assertion time bounds are missing.');
        }
        $expiry = Utils::parseSAML2Time($conditions->getAttribute('NotOnOrAfter'));
        if ($expiry > time() + 3600) {
            throw new RuntimeException('Assertion lifetime exceeds the supported one-hour window.');
        }

        return $auth;
    }

    public function validateLogoutProfile(Auth $auth, ?string $requestId, ?array $identity): void
    {
        $doc = new \DOMDocument;
        $xml = $requestId !== null ? $auth->getLastResponseXML() : $auth->getLastRequestXML();
        if (! $doc->loadXML($xml, LIBXML_NONET)) {
            throw new RuntimeException('Invalid logout XML.');
        }
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('a', Constants::NS_SAML);
        $xpath->registerNamespace('p', Constants::NS_SAMLP);
        if ($doc->documentElement->getAttribute('Destination') !== config('saml.sp.sls_url') ||
            $xpath->evaluate('string(/*/a:Issuer)') !== config('saml.idp.entity_id')) {
            throw new RuntimeException('Required logout Destination or Issuer is missing or mismatched.');
        }
        if ($requestId !== null) {
            if ($doc->documentElement->getAttribute('InResponseTo') !== $requestId) {
                throw new RuntimeException('LogoutResponse must match the pending LogoutRequest.');
            }
        } else {
            if (! $identity || $xpath->evaluate('string(/p:LogoutRequest/a:NameID)') !== $identity['name_id']) {
                throw new RuntimeException('LogoutRequest does not identify the current SAML session.');
            }
            $indices = $xpath->query('/p:LogoutRequest/p:SessionIndex');
            if ($indices->length > 0) {
                $matched = false;
                foreach ($indices as $index) {
                    $matched = $matched || $index->textContent === $identity['session_index'];
                }
                if (! $matched) {
                    throw new RuntimeException('LogoutRequest SessionIndex does not match.');
                }
            }
        }
    }

    public function metadata(): string
    {
        $settings = new Settings($this->settings(), true);
        $xml = $settings->getSPMetadata();
        if ($settings->validateMetadata($xml) !== []) {
            throw new RuntimeException('SP Metadata validation failed.');
        }

        return $xml;
    }

    public function settings(): array
    {
        return [
            'baseurl' => config('app.url'),
            'strict' => true,
            'debug' => false,
            'sp' => [
                'entityId' => config('saml.sp.entity_id'),
                'assertionConsumerService' => ['url' => config('saml.sp.acs_url'), 'binding' => Constants::BINDING_HTTP_POST],
                'singleLogoutService' => ['url' => config('saml.sp.sls_url'), 'binding' => Constants::BINDING_HTTP_REDIRECT],
                'NameIDFormat' => config('saml.sp.name_id_format'),
                'x509cert' => $this->read(config('saml.sp.cert_path')),
                'privateKey' => $this->read(config('saml.sp.key_path')),
            ],
            'idp' => [
                'entityId' => config('saml.idp.entity_id'),
                'singleSignOnService' => ['url' => config('saml.idp.sso_url'), 'binding' => Constants::BINDING_HTTP_REDIRECT],
                'singleLogoutService' => ['url' => config('saml.idp.slo_url'), 'binding' => Constants::BINDING_HTTP_REDIRECT],
                'x509cert' => $this->read(config('saml.idp.cert_path')),
            ],
            'security' => [
                'authnRequestsSigned' => (bool) config('saml.security.authn_requests_signed'),
                'logoutRequestSigned' => (bool) config('saml.security.logout_requests_signed'),
                'logoutResponseSigned' => (bool) config('saml.security.logout_responses_signed'),
                'wantMessagesSigned' => true,
                'wantAssertionsSigned' => (bool) config('saml.security.want_assertions_signed'),
                'wantXMLValidation' => true,
                'wantNameId' => true,
                'rejectUnsolicitedResponsesWithInResponseTo' => true,
                'destinationStrictlyMatches' => true,
                'relaxDestinationValidation' => false,
                'signatureAlgorithm' => XMLSecurityKey::RSA_SHA256,
                'digestAlgorithm' => XMLSecurityDSig::SHA256,
            ],
        ];
    }

    private function read(string $path): string
    {
        $resolved = str_starts_with($path, '/') ? $path : base_path($path);
        if (! is_readable($resolved)) {
            throw new RuntimeException('SAML certificate/key file is missing. Check configured paths.');
        }

        return file_get_contents($resolved);
    }
}
