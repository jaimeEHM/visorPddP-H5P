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
        Schema::create('lrs_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lti_platform_id')->nullable()->constrained('lti_platforms')->nullOnDelete();
            $table->string('name');
            $table->string('endpoint_url');
            $table->string('basic_username');
            $table->text('basic_password');
            $table->string('xapi_version')->default('1.0.3');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['active', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lrs_connections');
    }
};
