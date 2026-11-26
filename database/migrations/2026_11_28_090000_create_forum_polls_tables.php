<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Forum tahap 2: polling & voting (dibuat admin CMS, member hanya memilih).
 *
 *   voting  : hasil disembunyikan sampai selesai, satu suara & tidak bisa diubah, wajib ada ends_at.
 *   polling : hasil terlihat langsung, pilihan boleh diganti sampai ditutup, ends_at opsional.
 *
 * Jumlah suara disimpan sebagai counter (poll & opsi) supaya halaman tidak menghitung ulang ribuan baris.
 * Satu member satu suara per polling dijamin oleh unique index (poll_id, member_id).
 * Nama index ditulis eksplisit: nama otomatis bisa melewati batas 64 karakter MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forum_polls', function (Blueprint $t) {
            $t->id();
            $t->string('type', 10);                          // voting | polling
            $t->string('title', 190);
            $t->string('status', 20)->default('published');  // published | hidden
            $t->timestamp('starts_at');                      // boleh dijadwalkan tampil nanti
            $t->timestamp('ends_at')->nullable();            // wajib untuk voting
            $t->timestamp('closed_at')->nullable();          // ditutup admin lebih awal
            $t->unsignedInteger('votes_count')->default(0);
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();   // admin pembuat
            $t->softDeletes();
            $t->timestamps();

            $t->index(['status', 'type', 'starts_at'], 'forum_polls_status_type_starts_index');
            $t->index(['status', 'created_at'], 'forum_polls_status_created_index');
        });

        Schema::create('forum_poll_options', function (Blueprint $t) {
            $t->id();
            $t->foreignId('poll_id')->constrained('forum_polls')->cascadeOnDelete();
            $t->string('label', 120);
            $t->unsignedTinyInteger('position')->default(0);
            $t->unsignedInteger('votes_count')->default(0);
            $t->timestamps();

            $t->index(['poll_id', 'position'], 'forum_poll_options_poll_position_index');
        });

        Schema::create('forum_poll_votes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('poll_id')->constrained('forum_polls')->cascadeOnDelete();
            $t->foreignId('option_id')->constrained('forum_poll_options')->cascadeOnDelete();
            $t->foreignId('member_id')->constrained()->cascadeOnDelete();
            $t->timestamps();

            $t->unique(['poll_id', 'member_id'], 'forum_poll_votes_poll_member_unique');
            $t->index(['member_id', 'created_at'], 'forum_poll_votes_member_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forum_poll_votes');
        Schema::dropIfExists('forum_poll_options');
        Schema::dropIfExists('forum_polls');
    }
};
