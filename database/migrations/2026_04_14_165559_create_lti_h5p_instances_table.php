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
        Schema::create('lti_h5p_instances', function (Blueprint $table) {
            $table->id();
            $table->string('issuer');
            $table->string('deployment_id')->nullable();
            $table->string('context_id')->nullable();
            $table->string('resource_link_id');
            $table->string('preview_id');
            $table->string('preview_token');
            $table->timestamps();

            $table->unique(['issuer', 'deployment_id', 'context_id', 'resource_link_id'], 'lti_h5p_instances_scope_unique');
            $table->index(['issuer', 'resource_link_id'], 'lti_h5p_instances_lookup_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lti_h5p_instances');
    }
};
