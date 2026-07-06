<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            // Cached roll-ups the journeys keep honest:
            $table->integer('won_amount')->default(0);          // sum of closed-won amounts
            $table->integer('largest_open_deal')->default(0);   // max open amount, 0 when none
            $table->integer('health_score')->default(0);        // last computed open-opportunity count
            $table->boolean('health_fresh')->default(false);    // is health_score believed current?
            $table->timestamps();
        });

        Schema::create('crm_opportunities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained('crm_accounts');
            $table->string('name');
            $table->string('stage')->default('prospecting');
            $table->integer('amount')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_opportunities');
        Schema::dropIfExists('crm_accounts');
    }
};
