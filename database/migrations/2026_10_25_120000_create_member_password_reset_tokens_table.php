<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Token "lupa kata sandi" member (password broker `members`, config/auth.php). Terpisah dari
 * password_reset_tokens milik user CMS: kuncinya e-mail, jadi admin & member dengan e-mail sama
 * tidak saling menimpa token. Token disimpan dalam bentuk hash.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_password_reset_tokens');
    }
};
