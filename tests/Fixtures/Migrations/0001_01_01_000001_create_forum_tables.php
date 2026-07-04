<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communities', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('community_id')->constrained();
            $table->string('title');
            $table->string('status')->default('draft');
            $table->integer('score')->default(0);
            $table->timestamps();
        });

        Schema::create('votes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('post_id')->constrained();
            $table->string('voter');
            $table->integer('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('votes');
        Schema::dropIfExists('posts');
        Schema::dropIfExists('communities');
    }
};
