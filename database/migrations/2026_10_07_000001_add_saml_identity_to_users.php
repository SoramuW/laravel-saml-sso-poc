<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('saml_name_id')->nullable();
            $table->string('saml_idp_entity_id')->nullable();
            $table->unique(['saml_idp_entity_id', 'saml_name_id']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['saml_idp_entity_id', 'saml_name_id']);
            $table->dropColumn(['saml_name_id', 'saml_idp_entity_id']);
        });
    }
};
