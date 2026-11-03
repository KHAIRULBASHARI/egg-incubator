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
        Schema::table('capture_jobs', function (Blueprint $table) {
            $table->string('prediction_label')->nullable()->after('image_path');
            $table->float('confidence')->nullable()->after('prediction_label');
            $table->json('prediction_result')->nullable()->after('confidence');
            $table->text('llm_analysis')->nullable()->after('prediction_result');
            $table->mediumText('image_base64')->nullable()->after('llm_analysis');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('capture_jobs', function (Blueprint $table) {
            $table->dropColumn(['prediction_label', 'confidence', 'prediction_result', 'llm_analysis', 'image_base64']);
        });
    }
};
