<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Konten yang disimpan member (tombol Simpan): diskusi forum, berita, artikel panduan.
 * bookmarkable_type = kunci pendek (discussion / news / article), bukan nama class, supaya aman diubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_bookmarks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('member_id')->constrained()->cascadeOnDelete();
            $t->string('bookmarkable_type', 20);
            $t->unsignedBigInteger('bookmarkable_id');
            $t->timestamp('created_at')->nullable();

            // Nama index ditulis sendiri: nama otomatis > 64 karakter (batas MySQL)
            $t->unique(['member_id', 'bookmarkable_type', 'bookmarkable_id'], 'member_bookmarks_member_content_unique');
            $t->index(['member_id', 'created_at'], 'member_bookmarks_member_created_index');
            $t->index(['bookmarkable_type', 'bookmarkable_id'], 'member_bookmarks_content_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_bookmarks');
    }
};
