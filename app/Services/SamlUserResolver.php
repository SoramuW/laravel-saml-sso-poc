<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class SamlUserResolver
{
    public function resolve(string $nameId, array $attributes): User
    {
        $email = $this->attribute($attributes, 'email');
        if ($nameId === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('NameID and a valid mapped email are required.');
        }
        $issuer = config('saml.idp.entity_id');

        return DB::transaction(function () use ($nameId, $email, $issuer, $attributes) {
            $user = User::where('saml_idp_entity_id', $issuer)->where('saml_name_id', $nameId)->lockForUpdate()->first();
            if ($user) {
                return $user;
            }
            $user = User::where('email', $email)->lockForUpdate()->first();
            if ($user) {
                // Email linking requires an explicit trust decision for the IdP.
                if (! config('saml.allow_email_linking') || $user->saml_name_id !== null) {
                    throw new RuntimeException('Email belongs to an account that cannot be linked automatically.');
                }
                $user->forceFill(['saml_name_id' => $nameId, 'saml_idp_entity_id' => $issuer])->save();

                return $user;
            }
            if (! config('saml.auto_create_user')) {
                throw new RuntimeException('User is not registered; automatic creation is disabled.');
            }
            $name = trim($this->attribute($attributes, 'first_name').' '.$this->attribute($attributes, 'last_name'));

            return User::create([
                'name' => $name ?: ($this->attribute($attributes, 'username') ?: $nameId),
                'email' => $email,
                'password' => Str::random(64),
                'saml_name_id' => $nameId,
                'saml_idp_entity_id' => $issuer,
            ]);
        });
    }

    public function attribute(array $attributes, string $key): string
    {
        return (string) ($attributes[config('saml.attributes.'.$key)][0] ?? '');
    }
}
