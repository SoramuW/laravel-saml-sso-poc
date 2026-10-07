<?php

namespace Tests\Support;

use OneLogin\Saml2\Utils;

class SignedSamlResponse
{
    public static function make(string $key, string $cert, array $overrides = [], bool $sign = true): string
    {
        $data = array_merge([
            'issuer' => 'http://localhost:8081/realms/saml-demo',
            'audience' => 'laravel-saml',
            'destination' => 'http://localhost:18000/saml/acs',
            'recipient' => 'http://localhost:18000/saml/acs',
            'request' => '_test-request',
            'not_before' => gmdate('Y-m-d\TH:i:s\Z', time() - 60),
            'not_after' => gmdate('Y-m-d\TH:i:s\Z', time() + 300),
        ], $overrides);
        $data = array_map(fn ($v) => htmlspecialchars($v, ENT_XML1), $data);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $id = '_'.bin2hex(random_bytes(20));
        $assertion = <<<XML
<a:Assertion xmlns:a="urn:oasis:names:tc:SAML:2.0:assertion" ID="{$id}" Version="2.0" IssueInstant="{$now}">
<a:Issuer>{$data['issuer']}</a:Issuer>
<a:Subject><a:NameID Format="urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified">testuser</a:NameID>
<a:SubjectConfirmation Method="urn:oasis:names:tc:SAML:2.0:cm:bearer"><a:SubjectConfirmationData InResponseTo="{$data['request']}" Recipient="{$data['recipient']}" NotOnOrAfter="{$data['not_after']}"/></a:SubjectConfirmation></a:Subject>
<a:Conditions NotBefore="{$data['not_before']}" NotOnOrAfter="{$data['not_after']}"><a:AudienceRestriction><a:Audience>{$data['audience']}</a:Audience></a:AudienceRestriction></a:Conditions>
<a:AuthnStatement AuthnInstant="{$now}" SessionIndex="_session"><a:AuthnContext><a:AuthnContextClassRef>urn:oasis:names:tc:SAML:2.0:ac:classes:PasswordProtectedTransport</a:AuthnContextClassRef></a:AuthnContext></a:AuthnStatement>
<a:AttributeStatement><a:Attribute Name="email"><a:AttributeValue>test@example.com</a:AttributeValue></a:Attribute></a:AttributeStatement>
</a:Assertion>
XML;
        if ($sign) {
            $doc = new \DOMDocument;
            $doc->loadXML(Utils::addSign($assertion, $key, $cert));
            $signature = $doc->getElementsByTagNameNS('http://www.w3.org/2000/09/xmldsig#', 'Signature')->item(0);
            $issuer = $doc->getElementsByTagNameNS('urn:oasis:names:tc:SAML:2.0:assertion', 'Issuer')->item(0);
            $doc->documentElement->insertBefore($signature, $issuer->nextSibling);
            $assertion = $doc->saveXML();
        }
        $assertion = preg_replace('/<\?xml[^?]*\?>/', '', $assertion);
        $responseId = '_'.bin2hex(random_bytes(20));
        $response = <<<XML
<p:Response xmlns:p="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:a="urn:oasis:names:tc:SAML:2.0:assertion" ID="{$responseId}" Version="2.0" IssueInstant="{$now}" Destination="{$data['destination']}" InResponseTo="{$data['request']}">
<a:Issuer>{$data['issuer']}</a:Issuer>
<p:Status><p:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/></p:Status>
{$assertion}
</p:Response>
XML;

        return $sign ? Utils::addSign($response, $key, $cert) : $response;
    }
}
