<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CON-02: versioned contract templates.
 *
 * A published row is immutable -- editing legal text people have already
 * signed would silently rewrite their agreement. A change means a NEW version
 * under the same key, which the unique(key, version) constraint enforces at
 * the database layer.
 *
 * `body` uses simple placeholder substitution, NOT Blade. Template bodies are
 * admin-editable, and compiling admin input as Blade would be arbitrary PHP
 * execution. See App\Services\Contract\TemplateRenderer.
 *
 * down(): drops this new table only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60);
            $table->unsignedInteger('version');
            $table->string('title');
            $table->longText('body');
            $table->json('variables')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['key', 'version']);
            $table->index(['key', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_templates');
    }
};
