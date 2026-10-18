<?php

use App\Http\Controllers\Api\AboutController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\EvGarageController;
use App\Http\Controllers\Api\GalleryAlbumController;
use App\Http\Controllers\Api\GuideArticleController;
use App\Http\Controllers\Api\GuideInfographicController;
use App\Http\Controllers\Api\GuideVideoController;
use App\Http\Controllers\Api\HeroBannerController;
use App\Http\Controllers\Api\LatestEventController;
use App\Http\Controllers\Api\LatestGuideController;
use App\Http\Controllers\Api\LatestNewsController;
use App\Http\Controllers\Api\Member\AuthController as MemberAuthController;
use App\Http\Controllers\Api\Member\IuranPaymentController;
use App\Http\Controllers\Api\Member\PasswordController as MemberPasswordController;
use App\Http\Controllers\Api\Member\ProfileController as MemberProfileController;
use App\Http\Controllers\Api\MemberRegistrationController;
use App\Http\Controllers\Api\NewsController;
use App\Http\Controllers\Api\PartnerController;
use App\Http\Controllers\Api\RegionController;
use App\Http\Controllers\Api\SpkluOperatorController;
use App\Http\Controllers\Api\VehicleBrandController;
use App\Http\Controllers\Api\Webhook\FakePaymentController;
use App\Http\Controllers\Api\Webhook\YoduCallbackController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->name('api.auth.login');

    Route::middleware(['auth:sanctum', 'role:'.implode('|', User::CMS_ROLES)])->group(function () {
        Route::get('/me', [AuthController::class, 'me'])->name('api.auth.me');
        Route::post('/logout', [AuthController::class, 'logout'])->name('api.auth.logout');
    });
});

/*
| API publik untuk frontend (Next.js) — wajib header `Authorization: Bearer {FRONTEND_API_TOKEN}`.
| Semua request datang dari server Next.js (satu IP), jadi batas throttle dibuat longgar:
| yang dibatasi adalah total trafik FE (termasuk hitung view per pengunjung), bukan per pengunjung.
*/
Route::prefix('v1')->middleware(['frontend.token', 'throttle:600,1'])->group(function () {
    Route::get('/hero-banners', [HeroBannerController::class, 'index'])->name('api.v1.hero-banners.index');
    Route::get('/guides/latest', LatestGuideController::class)->name('api.v1.guides.latest');
    Route::get('/news/latest', LatestNewsController::class)->name('api.v1.news.latest');
    Route::get('/events/latest', LatestEventController::class)->name('api.v1.events.latest');
    Route::get('/events', [EventController::class, 'index'])->name('api.v1.events.index');
    Route::get('/events/categories', [EventController::class, 'categories'])->name('api.v1.events.categories');
    // /events/latest & /events/categories didaftarkan lebih dulu, jadi tidak tertangkap sebagai {slug}
    Route::get('/events/{slug}', [EventController::class, 'show'])->where('slug', '[a-z0-9-]+')->name('api.v1.events.show');
    Route::get('/partners', [PartnerController::class, 'index'])->name('api.v1.partners.index');
    Route::get('/about', AboutController::class)->name('api.v1.about');

    Route::prefix('ev-garage')->name('api.v1.ev-garage.')->controller(EvGarageController::class)->group(function () {
        Route::get('/brands', 'brands')->name('brands');
        Route::get('/vehicles', 'vehicles')->name('vehicles');
    });

    Route::prefix('spklu-operators')->name('api.v1.spklu-operators.')->controller(SpkluOperatorController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/brands', 'brands')->name('brands');
    });

    // /news/latest (di atas) didaftarkan lebih dulu, jadi tidak tertangkap sebagai {slug}
    Route::get('/news', [NewsController::class, 'index'])->name('api.v1.news.index');
    Route::get('/news/{slug}', [NewsController::class, 'show'])->where('slug', '[a-z0-9-]+')->name('api.v1.news.show');

    Route::prefix('guides/articles')->name('api.v1.guides.articles.')->controller(GuideArticleController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/categories', 'categories')->name('categories');
        Route::get('/popular', 'popular')->name('popular');
        Route::get('/{slug}', 'show')->where('slug', '[a-z0-9-]+')->name('show');
        Route::put('/{slug}/view', 'view')->where('slug', '[a-z0-9-]+')->name('view');
    });

    Route::prefix('guides/videos')->name('api.v1.guides.videos.')->controller(GuideVideoController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/categories', 'categories')->name('categories');
    });

    Route::get('/gallery/albums', [GalleryAlbumController::class, 'index'])->name('api.v1.gallery.albums.index');

    // Master data untuk form profil / pendaftaran
    Route::prefix('regions')->name('api.v1.regions.')->controller(RegionController::class)->group(function () {
        Route::get('/regencies', 'regencies')->name('regencies');
        Route::get('/regencies/{regency}/districts', 'districts')->where('regency', '\d{2}\.\d{2}')->name('districts');
        Route::get('/districts/{district}/villages', 'villages')->where('district', '\d{2}\.\d{2}\.\d{2}')->name('villages');
    });
    Route::get('/vehicle-brands', VehicleBrandController::class)->name('api.v1.vehicle-brands');

    Route::prefix('guides/infographics')->name('api.v1.guides.infographics.')->controller(GuideInfographicController::class)->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/categories', 'categories')->name('categories');
    });
});

/*
| Pendaftaran member baru (form /daftar). Store & resend dipanggil server Next.js (FRONTEND_API_TOKEN) yang meneruskan
| IP pengunjung lewat X-Forwarded-For → throttle per pengunjung. Link verifikasi dibuka langsung dari e-mail (signed URL).
*/
Route::prefix('v1/registrations')->name('api.v1.registrations.')->controller(MemberRegistrationController::class)->group(function () {
    Route::post('/', 'store')->middleware(['frontend.token', 'throttle:member-registration'])->name('store');
    Route::post('/resend-verification', 'resendVerification')
        ->middleware(['frontend.token', 'throttle:member-registration-resend'])->name('resend');
    Route::get('/verify/{member}/{hash}', 'verify')
        ->where(['member' => '\d+', 'hash' => '[a-f0-9]{40}'])
        ->middleware('throttle:30,1')->name('verify');
});

/*
| Auth member (login dari FE). Login dipanggil server Next.js → wajib FRONTEND_API_TOKEN; Next.js meneruskan IP
| pengunjung lewat X-Forwarded-For supaya throttle & rate limit login per pengunjung, bukan per server FE.
| Setelah login, request memakai token member (Bearer) dan hanya token milik Member yang diterima.
*/
Route::prefix('v1/member')->name('api.v1.member.')->group(function () {
    Route::post('/login', [MemberAuthController::class, 'login'])
        ->middleware(['frontend.token', 'throttle:20,1'])
        ->name('login');

    Route::middleware(['auth:sanctum', 'member', 'throttle:120,1'])->group(function () {
        Route::get('/me', [MemberAuthController::class, 'me'])->name('me');
        Route::post('/logout', [MemberAuthController::class, 'logout'])->name('logout');

        Route::get('/profile', [MemberProfileController::class, 'show'])->name('profile.show');
        Route::post('/profile', [MemberProfileController::class, 'update'])->name('profile.update'); // multipart (dokumen)
        Route::put('/password', [MemberPasswordController::class, 'update'])->middleware('throttle:member-password')->name('password.update');

        // Bayar iuran (QRIS). Sengaja tanpa syarat "member aktif" — member nonaktif justru yang perlu bayar.
        Route::post('/payments/iuran', [IuranPaymentController::class, 'store'])
            ->middleware('throttle:member-payment-create')->name('payments.iuran');
        Route::get('/payments/{merchantRef}', [IuranPaymentController::class, 'show'])
            ->where('merchantRef', '[A-Za-z0-9\-]{1,64}')
            ->middleware('throttle:member-payment-status')->name('payments.show');
    });
});

/*
| Callback gateway pembayaran — publik (tanpa auth/CSRF). Tidak dipercaya begitu saja:
| diverifikasi awal di gateway (IP whitelist) + SUCCESS selalu dikonfirmasi ke API cek status.
*/
Route::post('/payments/yodu/callback', YoduCallbackController::class)
    ->middleware('throttle:payment-callback')
    ->name('api.payments.yodu.callback');

// Simulasi bayar untuk PAYMENT_DRIVER=fake — tidak terdaftar di production
if (! app()->isProduction()) {
    Route::post('/payments/fake/{merchantRef}/pay', FakePaymentController::class)
        ->where('merchantRef', '[A-Za-z0-9\-]{1,64}')
        ->name('api.payments.fake.pay');
}
