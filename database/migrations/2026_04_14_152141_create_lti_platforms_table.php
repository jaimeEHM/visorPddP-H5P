<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('lti_platforms', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('issuer')->unique();
            $table->string('client_id')->nullable();
            $table->json('jwks_json')->nullable();
            $table->string('jwks_url')->nullable();
            $table->string('authorization_endpoint')->nullable();
            $table->string('token_endpoint')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lti_platforms');
    }
};
