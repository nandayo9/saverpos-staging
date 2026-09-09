<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('recommerce_catalogue_models', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->unsignedInteger('business_id');
            $t->string('model_id', 150);
            $t->string('slug', 160);
            $t->string('identity_key', 64);
            $t->string('category', 32);
            $t->string('brand', 120);
            $t->string('family', 120)->nullable();
            $t->string('name', 160);
            $t->string('generation', 120)->nullable();
            $t->string('publication_state', 24)->default('DRAFT');
            $t->text('public_content_json')->nullable();
            $t->boolean('synthetic')->default(true);
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
            $t->unique(['business_id', 'model_id'], 'rcm_model_unique');
            $t->unique(['business_id', 'slug'], 'rcm_slug_unique');
            $t->unique(['business_id', 'identity_key'], 'rcm_identity_unique');
        });
        Schema::create('recommerce_catalogue_mappings', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->unsignedInteger('business_id');
            $t->string('source', 32);
            $t->string('source_id', 160);
            $t->string('model_id', 150);
            $t->string('variant_id', 150)->nullable();
            $t->string('specification_key', 64)->nullable();
            $t->text('specification_json')->nullable();
            $t->unsignedInteger('native_variation_id')->nullable();
            $t->string('provenance', 500);
            $t->unsignedInteger('actor_id');
            $t->timestamps();
            $t->unique(['business_id', 'source', 'source_id'], 'rci_source_unique');
            $t->unique(['business_id', 'model_id', 'specification_key'], 'rci_spec_unique');
            $t->index(['business_id', 'native_variation_id'], 'rci_native_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommerce_catalogue_mappings');
        Schema::dropIfExists('recommerce_catalogue_models');
    }
};
