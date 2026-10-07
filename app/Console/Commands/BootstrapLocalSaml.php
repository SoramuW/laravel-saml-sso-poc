<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use OneLogin\Saml2\IdPMetadataParser;

class BootstrapLocalSaml extends Command
{
    protected $signature = 'saml:bootstrap-local';

    protected $description = 'Pin the local Keycloak signing certificate on first startup (local environment only)';

    public function handle(): int
    {
        if (! app()->environment('local') || config('saml.provider') !== 'keycloak') {
            $this->info('Automatic certificate provisioning skipped; supply your trusted IdP certificate.');

            return self::SUCCESS;
        }
        $path = config('saml.idp.cert_path');
        $path = str_starts_with($path, '/') ? $path : base_path($path);
        if (is_file($path)) {
            $this->info('Existing IdP certificate retained (no automatic rotation).');

            return self::SUCCESS;
        }
        $url = config('saml.local_metadata_url');
        // Only the local Compose Keycloak endpoint may be trusted automatically.
        if ($url !== 'http://keycloak:8080/realms/saml-demo/protocol/saml/descriptor') {
            $this->error('Automatic local bootstrap requires the fixed Compose metadata endpoint.');

            return self::FAILURE;
        }
        for ($attempt = 0; $attempt < 90; $attempt++) {
            try {
                $response = Http::timeout(3)->get($url)->throw();
                $metadata = IdPMetadataParser::parseXML($response->body());
                if (($metadata['idp']['entityId'] ?? null) !== config('saml.idp.entity_id')) {
                    throw new \RuntimeException('Metadata Entity ID does not match configured IdP.');
                }
                $cert = $metadata['idp']['x509cert'] ?? ($metadata['idp']['x509certMulti']['signing'][0] ?? null);
                if (! $cert) {
                    throw new \RuntimeException('IdP signing certificate missing.');
                }
                $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split(preg_replace('/\s/', '', $cert), 64, "\n")."-----END CERTIFICATE-----\n";
                if (! openssl_x509_read($pem)) {
                    throw new \RuntimeException('Invalid X.509 certificate.');
                }
                if (! is_dir(dirname($path))) {
                    mkdir(dirname($path), 0775, true);
                }
                file_put_contents($path, $pem);
                $this->info('Local IdP certificate pinned: '.hash('sha256', $pem));

                return self::SUCCESS;
            } catch (\Throwable $e) {
                if ($attempt === 89) {
                    $this->error('Local IdP bootstrap failed: '.$e->getMessage());

                    return self::FAILURE;
                }
                sleep(2);
            }
        }

        return self::FAILURE;
    }
}
