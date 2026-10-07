<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SamlUserResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class SamlUserResolverTest extends TestCase
{
    use RefreshDatabase;

    private function attributes(): array
    {
        return ['email' => ['test@example.com'], 'username' => ['testuser'], 'firstName' => ['Test'], 'lastName' => ['User']];
    }

    public function test_creates_and_reuses_a_user_for_the_same_issuer_and_name_id(): void
    {
        $resolver = app(SamlUserResolver::class);
        $user = $resolver->resolve('testuser', $this->attributes());
        $this->assertSame('Test User', $user->name);
        $this->assertSame($user->id, $resolver->resolve('testuser', $this->attributes())->id);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_refuses_new_user_when_auto_creation_is_disabled(): void
    {
        config(['saml.auto_create_user' => false]);
        $this->expectException(RuntimeException::class);
        app(SamlUserResolver::class)->resolve('testuser', $this->attributes());
    }

    public function test_does_not_implicitly_link_an_existing_email(): void
    {
        User::factory()->create(['email' => 'test@example.com']);
        $this->expectException(RuntimeException::class);
        app(SamlUserResolver::class)->resolve('testuser', $this->attributes());
    }

    public function test_explicit_email_linking_is_limited_to_unbound_users(): void
    {
        config(['saml.allow_email_linking' => true]);
        $user = User::factory()->create(['email' => 'test@example.com']);
        $this->assertSame($user->id, app(SamlUserResolver::class)->resolve('testuser', $this->attributes())->id);
        $this->expectException(RuntimeException::class);
        app(SamlUserResolver::class)->resolve('other-name-id', $this->attributes());
    }

    public function test_same_name_id_from_another_issuer_cannot_take_over_an_existing_user(): void
    {
        $resolver = app(SamlUserResolver::class);
        $resolver->resolve('testuser', $this->attributes());
        config(['saml.idp.entity_id' => 'https://other-idp.example']);
        $this->expectException(RuntimeException::class);
        $resolver->resolve('testuser', $this->attributes());
    }

    public function test_attribute_mapping_is_configurable(): void
    {
        config(['saml.attributes.email' => 'mail']);
        $attributes = $this->attributes();
        $attributes['mail'] = $attributes['email'];
        unset($attributes['email']);
        $this->assertSame('test@example.com', app(SamlUserResolver::class)->resolve('testuser', $attributes)->email);
    }

    public function test_unsolicited_acs_and_logout_response_are_rejected(): void
    {
        $this->post('/saml/acs', ['SAMLResponse' => 'invalid'])->assertForbidden();
        $this->get('/saml/sls?SAMLResponse=invalid')->assertForbidden();
    }
}
