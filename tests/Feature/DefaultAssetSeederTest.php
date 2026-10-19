<?php

use App\Models\Member;
use Database\Seeders\DefaultAssetSeeder;
use Illuminate\Support\Facades\Storage;

test('default member photo is uploaded to the photo disk when missing', function () {
    Storage::fake(Member::PHOTO_DISK);

    $this->seed(DefaultAssetSeeder::class);

    Storage::disk(Member::PHOTO_DISK)->assertExists(Member::DEFAULT_PHOTO);
});

test('existing default photo is not overwritten', function () {
    Storage::fake(Member::PHOTO_DISK);
    Storage::disk(Member::PHOTO_DISK)->put(Member::DEFAULT_PHOTO, 'custom');

    $this->seed(DefaultAssetSeeder::class);

    expect(Storage::disk(Member::PHOTO_DISK)->get(Member::DEFAULT_PHOTO))->toBe('custom');
});
