<?php

namespace Tests\Feature;

use App\Services\SamlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use RuntimeException;
use Tests\Support\SignedSamlResponse;
use Tests\TestCase;

class SamlValidationTest extends TestCase
{
    use RefreshDatabase;

    private string $key = '';

    private string $cert = '';

    private string $directory;

    private array $oldServer;

    private array $oldPost;

    private array $oldGet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->oldServer = $_SERVER;
        $this->oldPost = $_POST;
        $this->oldGet = $_GET;
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => 'Test IdP'], $key);
        $cert = openssl_csr_sign($csr, null, $key, 1);
        openssl_pkey_export($key, $this->key);
        openssl_x509_export($cert, $this->cert);
        $this->directory = sys_get_temp_dir().'/saml-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        file_put_contents($this->directory.'/cert.pem', $this->cert);
        file_put_contents($this->directory.'/key.pem', $this->key);
        config([
            'app.url' => 'http://localhost:18000',
            'saml.sp.cert_path' => $this->directory.'/cert.pem',
            'saml.sp.key_path' => $this->directory.'/key.pem',
            'saml.idp.cert_path' => $this->directory.'/cert.pem',
            'saml.sp.entity_id' => 'laravel-saml',
            'saml.sp.acs_url' => 'http://localhost:18000/saml/acs',
            'saml.sp.sls_url' => 'http://localhost:18000/saml/sls',
            'saml.idp.entity_id' => 'http://localhost:18001/realms/saml-demo',
        ]);
        $_SERVER['HTTP_HOST'] = 'localhost:18000';
        $_SERVER['SERVER_PORT'] = '18000';
        $_SERVER['HTTPS'] = 'off';
        $_SERVER['REQUEST_URI'] = '/saml/acs';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->oldServer;
        $_POST = $this->oldPost;
        $_GET = $this->oldGet;
        unlink($this->directory.'/cert.pem');
        unlink($this->directory.'/key.pem');
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_accepts_a_valid_signed_response(): void
    {
        $_POST['SAMLResponse'] = base64_encode(SignedSamlResponse::make($this->key, $this->cert));
        $auth = app(SamlService::class)->validateResponse('_test-request');
        $this->assertSame('testuser', $auth->getNameId());
        $this->assertSame(['test@example.com'], $auth->getAttributes()['email']);
    }

    public static function invalidFields(): array
    {
        return [
            'issuer' => [['issuer' => 'https://wrong.example']],
            'audience' => [['audience' => 'other-sp']],
            'destination' => [['destination' => 'http://localhost:18000/other']],
            'empty destination' => [['destination' => '']],
            'empty audience' => [['audience' => '']],
            'recipient/ACS' => [['recipient' => 'http://localhost:18000/other']],
            'request correlation' => [['request' => '_other-request']],
            'not yet valid' => [['not_before' => gmdate('Y-m-d\TH:i:s\Z', time() + 600)]],
            'expired' => [['not_after' => gmdate('Y-m-d\TH:i:s\Z', time() - 600)]],
            'excessive lifetime' => [['not_after' => gmdate('Y-m-d\TH:i:s\Z', time() + 7200)]],
        ];
    }

    #[DataProvider('invalidFields')]
    public function test_rejects_signed_responses_with_invalid_conditions(array $fields): void
    {
        $_POST['SAMLResponse'] = base64_encode(SignedSamlResponse::make($this->key, $this->cert, $fields));
        $this->expectException(RuntimeException::class);
        app(SamlService::class)->validateResponse('_test-request');
    }

    public function test_rejects_unsigned_responses(): void
    {
        $_POST['SAMLResponse'] = base64_encode(SignedSamlResponse::make($this->key, $this->cert, [], false));
        $this->expectException(RuntimeException::class);
        app(SamlService::class)->validateResponse('_test-request');
    }

    public function test_rejects_tampered_signature(): void
    {
        $xml = SignedSamlResponse::make($this->key, $this->cert);
        $_POST['SAMLResponse'] = base64_encode(str_replace('test@example.com', 'attacker@example.com', $xml));
        $this->expectException(RuntimeException::class);
        app(SamlService::class)->validateResponse('_test-request');
    }

    public function test_rejects_wrong_trusted_certificate(): void
    {
        $_POST['SAMLResponse'] = base64_encode(SignedSamlResponse::make($this->key, $this->cert));
        // A different signing key produces a valid but untrusted certificate.
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => 'Untrusted'], $key);
        openssl_x509_export(openssl_csr_sign($csr, null, $key, 1), $cert);
        file_put_contents($this->directory.'/cert.pem', $cert);
        $this->expectException(RuntimeException::class);
        app(SamlService::class)->validateResponse('_test-request');
    }

    private function logoutResponse(bool $correlated = true): array
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $correlation = $correlated ? 'InResponseTo="_logout-request"' : '';
        $xml = '<p:LogoutResponse xmlns:p="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:a="urn:oasis:names:tc:SAML:2.0:assertion" ID="_logout-response" Version="2.0" IssueInstant="'.$now.'" Destination="http://localhost:18000/saml/sls" '.$correlation.'><a:Issuer>http://localhost:18001/realms/saml-demo</a:Issuer><p:Status><p:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/></p:Status></p:LogoutResponse>';
        $encoded = base64_encode(gzdeflate($xml));
        $auth = app(SamlService::class)->auth();

        return [
            'SAMLResponse' => $encoded,
            'SigAlg' => XMLSecurityKey::RSA_SHA256,
            'Signature' => $auth->buildResponseSignature($encoded, null),
        ];
    }

    public function test_accepts_signed_correlated_logout_response(): void
    {
        $_GET = $this->logoutResponse();
        $_SERVER['REQUEST_URI'] = '/saml/sls';
        $auth = app(SamlService::class)->auth();
        $auth->processSLO(true, '_logout-request', false, null, true);
        $this->assertSame([], $auth->getErrors());
        app(SamlService::class)->validateLogoutProfile($auth, '_logout-request', null);
    }

    public function test_rejects_signed_logout_response_without_correlation(): void
    {
        $_GET = $this->logoutResponse(false);
        $_SERVER['REQUEST_URI'] = '/saml/sls';
        $auth = app(SamlService::class)->auth();
        $auth->processSLO(true, '_logout-request', false, null, true);
        $this->assertSame([], $auth->getErrors());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('LogoutResponse must match');
        app(SamlService::class)->validateLogoutProfile($auth, '_logout-request', null);
    }

    public function test_rejects_unsigned_logout_response(): void
    {
        $_GET = $this->logoutResponse();
        unset($_GET['Signature']);
        $_SERVER['REQUEST_URI'] = '/saml/sls';
        $auth = app(SamlService::class)->auth();
        $auth->processSLO(true, '_logout-request', false, null, true);
        $this->assertNotSame([], $auth->getErrors());
    }

    public function test_rejects_tampered_logout_signature(): void
    {
        $_GET = $this->logoutResponse();
        $_GET['Signature'] = base64_encode(str_repeat('x', 256));
        $_SERVER['REQUEST_URI'] = '/saml/sls';
        $auth = app(SamlService::class)->auth();
        $auth->processSLO(true, '_logout-request', false, null, true);
        $this->assertNotSame([], $auth->getErrors());
    }

    public function test_acs_logs_in_once_and_rejects_a_replayed_response(): void
    {
        $_POST['SAMLResponse'] = base64_encode(SignedSamlResponse::make($this->key, $this->cert));
        $pending = ['id' => '_test-request', 'at' => time()];
        $this->withSession(['saml.login_request' => $pending])->post('http://localhost:18000/saml/acs', $_POST)->assertRedirect('/');
        $this->assertAuthenticated();
        $this->assertDatabaseCount('users', 1);
        // Even with the same pending ID restored, the consumed Response/Assertion is refused.
        $this->withSession(['saml.login_request' => $pending])->post('http://localhost:18000/saml/acs', $_POST)->assertForbidden();
    }
}
