<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Forum tahap 1: diskusi, komentar (bersarang), rating.
 *
 * Penulis diskusi/komentar = member (website) ATAU admin CMS (users): salah satu kolom terisi.
 * Angka di daftar diskusi (komentar, dilihat, rating) disimpan sebagai counter supaya daftar tidak perlu
 * menghitung ulang ribuan baris tiap dibuka.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forum_categories', function (Blueprint $t) {
            $t->id();
            $t->string('name', 60);
            $t->string('slug', 80)->unique();
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('forum_discussions', function (Blueprint $t) {
            $t->id();
            $t->string('slug', 190)->unique();   // judul + 6 karakter acak: tidak bisa ditebak berurutan
            $t->foreignId('category_id')->constrained('forum_categories')->restrictOnDelete();
            $t->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('title', 150);
            $t->text('body');
            $t->string('image')->nullable();
            $t->string('status', 20)->default('published');   // published | hidden (disembunyikan admin)
            $t->boolean('is_pinned')->default(false);
            $t->boolean('is_locked')->default(false);         // komentar ditutup
            $t->unsignedInteger('comments_count')->default(0);
            $t->unsignedInteger('views_count')->default(0);
            $t->unsignedInteger('rating_sum')->default(0);
            $t->unsignedInteger('rating_count')->default(0);
            $t->timestamp('last_activity_at')->nullable();
            $t->softDeletes();
            $t->timestamps();

            $t->index(['status', 'is_pinned', 'last_activity_at']);
            $t->index(['category_id', 'status', 'created_at']);
            $t->index(['member_id', 'created_at']);
        });

        Schema::create('forum_comments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('discussion_id')->constrained('forum_discussions')->cascadeOnDelete();
            $t->foreignId('parent_id')->nullable()->constrained('forum_comments')->cascadeOnDelete();
            $t->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->text('body');
            $t->unsignedTinyInteger('depth')->default(0);     // 0 = komentar utama, maks 2 (3 tingkat)
            $t->string('status', 20)->default('published');   // published | hidden
            $t->softDeletes();
            $t->timestamps();

            $t->index(['discussion_id', 'parent_id', 'created_at']);
            $t->index(['member_id', 'created_at']);
        });

        Schema::create('forum_ratings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('discussion_id')->constrained('forum_discussions')->cascadeOnDelete();
            $t->foreignId('member_id')->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('rating');   // 1–5
            $t->timestamps();

            $t->unique(['discussion_id', 'member_id']);   // satu member satu rating (bisa diubah)
        });

        // Kategori awal (sama dengan dummy FE); dikelola admin di CMS
        $now = now();
        DB::table('forum_categories')->insert(collect(['Kendaraan', 'Infrastruktur', 'Teknologi', 'Bisnis', 'OOT'])
            ->map(fn (string $name, int $i) => [
                'name' => $name, 'slug' => strtolower($name), 'sort_order' => $i,
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ])->all());
    }

    public function down(): void
    {
        Schema::dropIfExists('forum_ratings');
        Schema::dropIfExists('forum_comments');
        Schema::dropIfExists('forum_discussions');
        Schema::dropIfExists('forum_categories');
    }
};
