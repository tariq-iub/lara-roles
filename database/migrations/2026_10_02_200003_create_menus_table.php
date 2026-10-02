<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('menus')
                ->nullOnDelete(); // deleting a parent re-parents children, never cascades silently

            $table->string('name');
            $table->string('slug', 150)->unique();

            // Named route this menu authorizes. May be a wildcard pattern: "admin.users.*"
            // A parent heading menu may have no route at all (nullable).
            $table->string('route_name', 191)->nullable();
            // JSON object of static route parameters, e.g. {"type":"payment"}
            $table->json('route_parameters')->nullable();

            $table->string('icon', 100)->nullable();
            $table->unsignedInteger('sort_order')->default(0);

            // is_active = false  -> authorization disabled (route not accessible via this menu)
            // is_visible = false -> hidden from sidebar but STILL authorizing when active
            $table->boolean('is_active')->default(true);
            $table->boolean('is_visible')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['parent_id', 'sort_order']);
            $table->index(['is_active', 'is_visible']);
            $table->index('route_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menus');
    }
};
