<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Cms\AboutUsController;
use App\Http\Controllers\Cms\EventCategoryController;
use App\Http\Controllers\Cms\EventController;
use App\Http\Controllers\Cms\EventVideoController;
use App\Http\Controllers\Cms\FaqController;
use App\Http\Controllers\Cms\GalleryAlbumController;
use App\Http\Controllers\Cms\GuideArticleController;
use App\Http\Controllers\Cms\GuideCategoryController;
use App\Http\Controllers\Cms\GuideInfographicController;
use App\Http\Controllers\Cms\GuidePlaybookController;
use App\Http\Controllers\Cms\GuideRegulationController;
use App\Http\Controllers\Cms\GuideVideoController;
use App\Http\Controllers\Cms\HeroBannerController;
use App\Http\Controllers\Cms\ManagementPeriodController;
use App\Http\Controllers\Cms\MemberController;
use App\Http\Controllers\Cms\NewsController;
use App\Http\Controllers\Cms\NotificationController;
use App\Http\Controllers\Cms\PartnerController;
use App\Http\Controllers\Cms\SpkluOperatorController;
use App\Http\Controllers\Cms\SponsorController;
use App\Http\Controllers\Cms\VehicleBrandController;
use App\Http\Controllers\Cms\VehicleModelController;
use App\Http\Controllers\LocaleController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

// Locale Switch Route
Route::get('/locale/{locale}', [LocaleController::class, 'switch'])->name('locale.switch');

// authentication pages
Route::middleware('guest')->group(function () {
    Route::get('/signin', [LoginController::class, 'create'])->name('signin');
    Route::post('/signin', [LoginController::class, 'store'])->name('signin.store');
});

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

// CMS pages (hanya user yang sudah login & punya role CMS)
Route::middleware(['auth', 'role:'.implode('|', User::CMS_ROLES)])->group(function () {
    // dashboard pages
    Route::get('/', function () {
        return view('pages.dashboard.ecommerce', ['title' => 'Dashboard']);
    })->name('dashboard');

    // content website
    Route::resource('hero-banners', HeroBannerController::class)->except('show');
    Route::get('about-us', [AboutUsController::class, 'edit'])->name('about-us.edit'); // single row: langsung ke form
    Route::put('about-us', [AboutUsController::class, 'update'])->name('about-us.update');
    Route::resource('management-periods', ManagementPeriodController::class)->except('show');
    Route::resource('faqs', FaqController::class)->except('show');

    // panduan praktis
    Route::resource('guide-categories', GuideCategoryController::class)->except('show');
    Route::get('guide-articles/member-options', [GuideArticleController::class, 'memberOptions'])->name('guide-articles.member-options');
    Route::post('guide-articles/content-images', [GuideArticleController::class, 'uploadContentImage'])
        ->middleware('throttle:60,1')
        ->name('guide-articles.content-images.store');
    Route::resource('guide-articles', GuideArticleController::class)->except('show');
    Route::resource('guide-videos', GuideVideoController::class)->except('show');
    Route::resource('guide-infographics', GuideInfographicController::class)->except('show');
    Route::get('guide-playbooks/{guide_playbook}/pdf', [GuidePlaybookController::class, 'pdf'])->name('guide-playbooks.pdf');
    Route::resource('guide-playbooks', GuidePlaybookController::class)->except('show');
    Route::get('guide-regulations/{guide_regulation}/pdf', [GuideRegulationController::class, 'pdf'])->name('guide-regulations.pdf');
    Route::resource('guide-regulations', GuideRegulationController::class)->except('show');

    // berita
    Route::get('news/member-options', [NewsController::class, 'memberOptions'])->name('news.member-options');
    Route::post('news/content-images', [NewsController::class, 'uploadContentImage'])
        ->middleware('throttle:60,1')
        ->name('news.content-images.store');
    Route::resource('news', NewsController::class)->except('show');

    // kegiatan
    Route::resource('event-categories', EventCategoryController::class)->except('show');
    Route::resource('events', EventController::class)->except('show');
    Route::resource('event-videos', EventVideoController::class)->except('show');

    // partner
    Route::resource('partners', PartnerController::class)->except('show');

    // sponsor (skema & alur sama dengan partner)
    Route::resource('sponsors', SponsorController::class)->except('show');

    // spklu (operator / brand + aplikasi)
    Route::resource('spklu-operators', SpkluOperatorController::class)->except('show');

    // ev garage (master merek & tipe kendaraan + spesifikasi)
    Route::resource('vehicle-brands', VehicleBrandController::class)->except('show');
    Route::resource('vehicle-models', VehicleModelController::class)->except('show');

    // galeri foto
    Route::post('gallery-albums/photo-uploads', [GalleryAlbumController::class, 'uploadPhoto'])
        ->middleware('throttle:120,1')
        ->name('gallery-albums.photo-uploads.store');
    Route::resource('gallery-albums', GalleryAlbumController::class)->except('show');

    // members
    Route::get('members', [MemberController::class, 'index'])->name('members.index');
    Route::get('members/data', [MemberController::class, 'data'])->name('members.data'); // DataTables server-side
    Route::put('members/no-lambung-start', [MemberController::class, 'updateNoLambungStart'])->name('members.no-lambung-start.update');
    Route::get('members/{member}', [MemberController::class, 'show'])->name('members.show');
    Route::get('members/{member}/edit', [MemberController::class, 'edit'])->name('members.edit');
    Route::put('members/{member}', [MemberController::class, 'update'])->name('members.update');
    Route::delete('members/{member}', [MemberController::class, 'destroy'])->name('members.destroy');
    Route::put('members/{member}/status', [MemberController::class, 'updateStatus'])->name('members.status.update');
    Route::get('member-documents/{document}', [MemberController::class, 'document'])->name('member-documents.show');

    // lonceng notifikasi
    Route::get('notifications/{id}/open', [NotificationController::class, 'open'])->whereUuid('id')->name('notifications.open');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');

    // calender pages
    Route::get('/calendar', function () {
        return view('pages.calender', ['title' => 'Calendar']);
    })->name('calendar');

    // profile pages
    Route::get('/profile', function () {
        return view('pages.profile', ['title' => 'Profile']);
    })->name('profile');

    // form pages
    Route::get('/form-elements', function () {
        return view('pages.form.form-elements', ['title' => 'Form Elements']);
    })->name('form-elements');

    // tables pages
    Route::get('/basic-tables', function () {
        return view('pages.tables.basic-tables', ['title' => 'Basic Tables']);
    })->name('basic-tables');

    // pages
    Route::get('/blank', function () {
        return view('pages.blank', ['title' => 'Blank']);
    })->name('blank');

    // error pages
    Route::get('/error-404', function () {
        return view('pages.errors.error-404', ['title' => 'Error 404']);
    })->name('error-404');

    // chart pages
    Route::get('/line-chart', function () {
        return view('pages.chart.line-chart', ['title' => 'Line Chart']);
    })->name('line-chart');

    Route::get('/bar-chart', function () {
        return view('pages.chart.bar-chart', ['title' => 'Bar Chart']);
    })->name('bar-chart');

    // ui elements pages
    Route::get('/alerts', function () {
        return view('pages.ui-elements.alerts', ['title' => 'Alerts']);
    })->name('alerts');

    Route::get('/avatars', function () {
        return view('pages.ui-elements.avatars', ['title' => 'Avatars']);
    })->name('avatars');

    Route::get('/badge', function () {
        return view('pages.ui-elements.badges', ['title' => 'Badges']);
    })->name('badges');

    Route::get('/buttons', function () {
        return view('pages.ui-elements.buttons', ['title' => 'Buttons']);
    })->name('buttons');

    Route::get('/image', function () {
        return view('pages.ui-elements.images', ['title' => 'Images']);
    })->name('images');

    Route::get('/videos', function () {
        return view('pages.ui-elements.videos', ['title' => 'Videos']);
    })->name('videos');
});
