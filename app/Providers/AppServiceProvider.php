<?php

namespace App\Providers;

use App\Models\User;
use App\Payments\Contracts\PaymentGateway;
use App\Payments\Gateways\FakeGateway;
use App\Payments\Gateways\YoduGateway;
use App\Support\ContentImages;
use App\Support\RichTextSanitizer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Config sanitizer cukup dibangun sekali per request.
        // <img> di rich text hanya boleh dari folder gambar konten milik aplikasi.
        $this->app->singleton(RichTextSanitizer::class, fn () => new RichTextSanitizer(ContentImages::baseUrls()));

        // Gateway pembayaran iuran: PAYMENT_DRIVER=yodu (sandbox/production) | fake (simulasi lokal)
        $this->app->bind(PaymentGateway::class, function () {
            if (config('yodu.driver') === 'fake') {
                if ($this->app->isProduction()) {
                    throw new RuntimeException('PAYMENT_DRIVER=fake tidak boleh dipakai di production.');
                }

                return new FakeGateway(config('yodu'));
            }

            return new YoduGateway(config('yodu'));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Force HTTPS in production
        if (config('app.env') === 'production') {
            URL::forceScheme('https');
        }

        // super-admin boleh melakukan semua aksi tanpa perlu diberi permission satu per satu
        Gate::before(fn (User $user) => $user->hasRole('super-admin') ? true : null);

        // Ganti kata sandi: kunci terpisah dari throttle umum grup member (throttle:120,1) supaya tidak dihitung dobel.
        // Salah "kata sandi saat ini" dibatasi lagi di UpdateMemberPasswordRequest (5x / 15 menit).
        RateLimiter::for('member-password', fn (Request $request) => Limit::perMinute(10)
            ->by('member-password:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Pembayaran iuran — kunci sendiri (tidak dobel dengan throttle umum grup member).
        // Polling FE tiap 4 detik = 15/menit; 60 memberi ruang untuk beberapa tab.
        RateLimiter::for('member-payment-create', fn (Request $request) => Limit::perMinute(10)
            ->by('member-payment-create:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('member-payment-status', fn (Request $request) => Limit::perMinute(60)
            ->by('member-payment-status:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        // Pendaftaran member (publik, per IP pengunjung yang diteruskan FE). Batas harian menahan spam pendaftaran palsu.
        RateLimiter::for('member-registration', fn (Request $request) => [
            Limit::perMinute(5)->by('member-registration:m:'.$request->ip()),
            Limit::perDay(20)->by('member-registration:d:'.$request->ip()),
        ]);
        RateLimiter::for('member-registration-resend', fn (Request $request) => Limit::perHour(10)
            ->by('member-registration-resend:'.$request->ip()));
        // Callback gateway (publik): per IP pengirim
        RateLimiter::for('payment-callback', fn (Request $request) => Limit::perMinute(120)->by('payment-callback:'.$request->ip()));
    }
}
