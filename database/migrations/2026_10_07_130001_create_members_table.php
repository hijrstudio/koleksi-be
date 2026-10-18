<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('members', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('no_lambung')->unique();
            $t->string('nama_lengkap')->index();
            $t->string('nama_panggilan')->nullable();
            $t->string('email')->nullable()->unique();
            $t->string('no_hp', 20)->nullable()->unique();
            $t->date('tgl_lahir')->nullable();
            $t->enum('jenis_kelamin', ['L', 'P'])->nullable();
            $t->text('alamat')->nullable();
            $t->char('city_code', 4)->nullable();
            $t->char('district_code', 7)->nullable();
            $t->char('village_code', 10)->nullable();
            $t->date('tgl_bergabung')->nullable();
            $t->boolean('has_vehicle')->default(false);
            $t->text('notes')->nullable();                     // catatan admin (mis. nama asli di Excel)

            // Status keanggotaan
            //   status_mode = auto   → dihitung dari iuran (MembershipService::refreshStatus)
            //   status_mode = manual → ditetapkan admin CMS, tidak ditimpa perhitungan otomatis
            $t->enum('status', ['active', 'inactive'])->default('inactive')->index();
            $t->enum('status_mode', ['auto', 'manual'])->default('auto');
            $t->date('active_until')->nullable()->index();

            $t->string('password');
            $t->boolean('must_change_password')->default(true);
            $t->timestamp('profile_completed_at')->nullable();
            $t->timestamp('email_verified_at')->nullable();
            $t->timestamp('last_login_at')->nullable();
            $t->rememberToken();
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};
