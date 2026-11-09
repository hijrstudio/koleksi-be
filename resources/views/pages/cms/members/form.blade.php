@extends('layouts.app')

@php
    use App\Http\Controllers\Cms\MemberController;
    use App\Http\Requests\Cms\MemberRequest;
    use App\Http\Requests\Member\UpdateMemberProfileRequest;

    $isEdit = $member->exists;
    $inputClass = 'shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 dark:focus:border-brand-800 h-11 w-full rounded-lg border bg-transparent px-4 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30';
    $selectClass = $inputClass.' select-chevron';
    $border = fn (string $field) => $errors->has($field) ? 'border-error-500' : 'border-gray-300 dark:border-gray-700';
    $labelClass = 'mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400';
    $sectionTitle = 'text-base font-semibold text-gray-800 dark:text-white/90';
    $hint = 'mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400';
    $required = '<span class="text-error-500">*</span>';

    $val = fn (string $field, $default = null) => old($field, $default);
    $date = fn ($value) => $value instanceof \Carbon\CarbonInterface ? $value->toDateString() : $value;
    $hasVehicle = (bool) old('has_vehicle', $member->has_vehicle ?? false);
    $firstDue = old('first_due', MemberRequest::FIRST_DUE_PAID);
    $lockJoinDate = $isEdit && $hasDues;
@endphp

@section('content')
    <x-common.page-breadcrumb :pageTitle="$title" />

    <div class="mb-4">
        <a href="{{ $isEdit ? route('members.show', $member) : route('members.index') }}"
            class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300">
            ← {{ $isEdit ? 'Kembali ke detail member' : 'Kembali ke daftar member' }}
        </a>
    </div>

    @if ($errors->any())
        <div class="border-error-500 bg-error-50 dark:border-error-500/30 dark:bg-error-500/15 mb-6 rounded-xl border p-4">
            <p class="text-sm text-gray-700 dark:text-gray-300">Periksa kembali isian yang ditandai merah.</p>
        </div>
    @endif

    <form method="POST" action="{{ $isEdit ? route('members.update', $member) : route('members.store') }}" novalidate
        x-data="memberForm({
            regionsUrl: @js(url('members/regions')),
            regencies: @js($regencies),
            districts: @js($districts),
            villages: @js($villages),
            brands: @js($brands),
            city: @js((string) $val('city_code', $member->city_code)),
            district: @js((string) $val('district_code', $member->district_code)),
            village: @js((string) $val('village_code', $member->village_code)),
            brand: @js((string) $val('vehicle_brand_id', $vehicle?->vehicle_brand_id)),
            model: @js((string) $val('vehicle_model_id', $vehicle?->vehicle_model_id)),
            hasVehicle: @js($hasVehicle),
            firstDue: @js($firstDue),
            passwordMode: @js(old('password_mode', $isEdit ? MemberRequest::PASSWORD_KEEP : MemberRequest::PASSWORD_EMAIL)),
            showPassword: false,
        })"
        class="space-y-6">
        @csrf
        @if ($isEdit)
            @method('PUT')
        @endif

        <!-- Keanggotaan -->
        <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="border-b border-gray-100 px-5 py-4 sm:px-6 dark:border-gray-800">
                <h3 class="{{ $sectionTitle }}">Keanggotaan</h3>
            </div>
            <div class="grid grid-cols-1 gap-6 px-5 py-6 sm:px-6 md:grid-cols-2">
                <div>
                    <label for="no_lambung" class="{{ $labelClass }}">No. Lambung{!! $isEdit ? $required : '' !!}</label>
                    <input type="number" id="no_lambung" name="no_lambung" min="1" max="{{ MemberController::MAX_NO_LAMBUNG }}"
                        @required($isEdit) value="{{ $val('no_lambung', $member->no_lambung) }}"
                        placeholder="{{ $isEdit ? '' : 'Otomatis: #'.str_pad((string) $nextNoLambung, 3, '0', STR_PAD_LEFT) }}"
                        class="{{ $inputClass }} {{ $border('no_lambung') }}" />
                    @error('no_lambung')
                        <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                    @enderror
                    <p class="{{ $hint }}">
                        Harus belum dipakai anggota lain (termasuk yang sudah dihapus).
                        {{ $isEdit ? 'Nomor invoice iuran ikut disesuaikan.' : 'Kosongkan untuk memakai nomor kosong berikutnya.' }}
                        Nomor kosong berikutnya: #{{ str_pad((string) $nextNoLambung, 3, '0', STR_PAD_LEFT) }}.
                    </p>
                </div>

                <div>
                    <label for="tgl_bergabung" class="{{ $labelClass }}">Tanggal Bergabung{!! $required !!}</label>
                    <input type="date" id="tgl_bergabung" name="tgl_bergabung" required max="{{ today()->toDateString() }}"
                        value="{{ $val('tgl_bergabung', $date($member->tgl_bergabung)) }}" @readonly($lockJoinDate)
                        class="{{ $inputClass }} {{ $border('tgl_bergabung') }} {{ $lockJoinDate ? 'cursor-not-allowed opacity-60' : '' }}" />
                    @error('tgl_bergabung')
                        <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                    @enderror
                    <p class="{{ $hint }}">
                        {{ $lockJoinDate
                            ? 'Tidak bisa diubah: member sudah punya tagihan iuran (periode iuran dihitung dari tanggal ini).'
                            : 'Patokan periode iuran tahunan member.' }}
                    </p>
                </div>

                @unless ($isEdit)
                    <div>
                        <label for="first_due" class="{{ $labelClass }}">Iuran Periode Pertama{!! $required !!}</label>
                        <select id="first_due" name="first_due" x-model="firstDue" class="{{ $selectClass }} {{ $border('first_due') }}">
                            @foreach (MemberRequest::FIRST_DUE_OPTIONS as $value => $label)
                                <option value="{{ $value }}" @selected($firstDue === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('first_due')
                            <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                        @enderror
                        <p class="{{ $hint }}">
                            Periode yang mencakup hari ini. Status member dihitung otomatis dari iuran:
                            lunas / dibebaskan = aktif, belum bayar = tidak aktif sampai dibayar.
                        </p>
                    </div>

                    <div x-show="firstDue === @js(MemberRequest::FIRST_DUE_PAID)" x-cloak>
                        <label for="payment_method" class="{{ $labelClass }}">Metode Pembayaran{!! $required !!}</label>
                        <select id="payment_method" name="payment_method" class="{{ $selectClass }} {{ $border('payment_method') }}">
                            @foreach (MemberRequest::PAYMENT_METHODS as $value => $label)
                                <option value="{{ $value }}" @selected(old('payment_method') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('payment_method')
                            <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                        @enderror
                    </div>
                @else
                    <div class="md:col-span-2">
                        <p class="text-theme-sm text-gray-500 dark:text-gray-400">
                            Status keanggotaan ({{ $member->isActive() ? 'Aktif' : 'Tidak Aktif' }}) diubah dari halaman detail member.
                        </p>
                    </div>
                @endunless
            </div>
        </div>

        <!-- Data pribadi -->
        <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="border-b border-gray-100 px-5 py-4 sm:px-6 dark:border-gray-800">
                <h3 class="{{ $sectionTitle }}">Data Pribadi</h3>
            </div>
            <div class="grid grid-cols-1 gap-6 px-5 py-6 sm:px-6 md:grid-cols-2">
                <div>
                    <label for="nama_lengkap" class="{{ $labelClass }}">Nama Lengkap{!! $required !!}</label>
                    <input type="text" id="nama_lengkap" name="nama_lengkap" required maxlength="255"
                        value="{{ $val('nama_lengkap', $member->nama_lengkap) }}" class="{{ $inputClass }} {{ $border('nama_lengkap') }}" />
                    @error('nama_lengkap')
                        <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label for="nama_panggilan" class="{{ $labelClass }}">Nama Panggilan</label>
                    <input type="text" id="nama_panggilan" name="nama_panggilan" maxlength="60"
                        value="{{ $val('nama_panggilan', $member->nama_panggilan) }}" class="{{ $inputClass }} {{ $border('nama_panggilan') }}" />
                    @error('nama_panggilan')
                        <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label for="tgl_lahir" class="{{ $labelClass }}">Tanggal Lahir</label>
                    <input type="date" id="tgl_lahir" name="tgl_lahir" max="{{ today()->subDay()->toDateString() }}"
                        value="{{ $val('tgl_lahir', $date($member->tgl_lahir)) }}" class="{{ $inputClass }} {{ $border('tgl_lahir') }}" />
                    @error('tgl_lahir')
                        <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label for="jenis_kelamin" class="{{ $labelClass }}">Jenis Kelamin</label>
                    <select id="jenis_kelamin" name="jenis_kelamin" class="{{ $selectClass }} {{ $border('jenis_kelamin') }}">
                        <option value="">-</option>
                        @foreach (['L' => 'Laki-laki', 'P' => 'Perempuan'] as $value => $label)
                            <option value="{{ $value }}" @selected($val('jenis_kelamin', $member->jenis_kelamin) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('jenis_kelamin')
                        <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </div>

        <!-- Kontak & login -->
        <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="border-b border-gray-100 px-5 py-4 sm:px-6 dark:border-gray-800">
                <h3 class="{{ $sectionTitle }}">Kontak &amp; Login</h3>
                <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">Member login dengan e-mail atau No. HP. Keduanya opsional, tapi tanpa keduanya member tidak bisa login.</p>
            </div>
            <div class="grid grid-cols-1 gap-6 px-5 py-6 sm:px-6 md:grid-cols-2">
                <div>
                    <label for="email" class="{{ $labelClass }}">E-mail</label>
                    <input type="email" id="email" name="email" maxlength="255" autocomplete="off"
                        value="{{ $val('email', $member->email) }}" class="{{ $inputClass }} {{ $border('email') }}" />
                    @error('email')
                        <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label for="no_hp" class="{{ $labelClass }}">No. HP</label>
                    <input type="tel" id="no_hp" name="no_hp" maxlength="20" placeholder="081234567890" autocomplete="off"
                        value="{{ $val('no_hp', $member->no_hp) }}" class="{{ $inputClass }} {{ $border('no_hp') }}" />
                    @error('no_hp')
                        <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <!-- Kata sandi -->
            <div class="space-y-4 border-t border-gray-100 px-5 py-6 sm:px-6 dark:border-gray-800">
                <span class="{{ $labelClass }}">Kata Sandi{!! $required !!}</span>
                <div class="space-y-3">
                    @foreach (MemberRequest::passwordOptions(! $isEdit) as $value => $label)
                        <label class="flex cursor-pointer items-center gap-2 text-sm text-gray-700 dark:text-gray-400">
                            <input type="radio" name="password_mode" value="{{ $value }}" x-model="passwordMode" class="accent-brand-500 h-4 w-4" />
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
                @error('password_mode')
                    <p class="text-error-500 text-theme-xs">{{ $message }}</p>
                @enderror

                <p x-show="passwordMode === @js(MemberRequest::PASSWORD_EMAIL)" x-cloak class="{{ $hint }}">
                    Member mengatur kata sandi sendiri lewat link di e-mail (berlaku {{ config('auth.passwords.members.expire') }} menit).
                    Kalau link kedaluwarsa, member bisa memakai "Lupa kata sandi" di halaman login.
                    @if ($isEdit)
                        Kata sandi lama tetap berlaku sampai member menggantinya.
                    @endif
                </p>

                <div x-show="passwordMode === @js(MemberRequest::PASSWORD_ADMIN)" x-cloak class="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <div>
                        <label for="password" class="{{ $labelClass }}">Kata Sandi Sementara{!! $required !!}</label>
                        <div class="relative">
                            <input :type="showPassword ? 'text' : 'password'" id="password" name="password" autocomplete="new-password"
                                maxlength="72" class="{{ $inputClass }} pr-20 {{ $border('password') }}" />
                            <button type="button" @click="showPassword = !showPassword"
                                class="absolute top-1/2 right-3 -translate-y-1/2 text-theme-xs text-gray-500 hover:text-gray-700 dark:text-gray-400"
                                x-text="showPassword ? 'Sembunyikan' : 'Tampilkan'"></button>
                        </div>
                        @error('password')
                            <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="password_confirmation" class="{{ $labelClass }}">Ulangi Kata Sandi{!! $required !!}</label>
                        <input :type="showPassword ? 'text' : 'password'" id="password_confirmation" name="password_confirmation"
                            autocomplete="new-password" maxlength="72" class="{{ $inputClass }} {{ $border('password') }}" />
                    </div>
                    <p class="{{ $hint }} md:col-span-2 !mt-0">
                        Minimal {{ App\Http\Requests\Member\UpdateMemberPasswordRequest::MIN_LENGTH }} karakter, huruf besar, huruf kecil, dan angka.
                        Sampaikan ke member secara pribadi. Setelah login, member mendapat pengingat di dasbor untuk menggantinya.
                        @if ($isEdit)
                            Member akan keluar dari semua sesi login.
                        @endif
                    </p>
                </div>
            </div>
        </div>

        <!-- Alamat -->
        <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="border-b border-gray-100 px-5 py-4 sm:px-6 dark:border-gray-800">
                <h3 class="{{ $sectionTitle }}">Alamat</h3>
            </div>
            <div class="grid grid-cols-1 gap-6 px-5 py-6 sm:px-6 md:grid-cols-3">
                <div>
                    <label for="city_code" class="{{ $labelClass }}">Kota / Kabupaten</label>
                    <select id="city_code" name="city_code" x-model="city" @change="loadDistricts()" class="{{ $selectClass }} {{ $border('city_code') }}">
                        <option value="">-</option>
                        <template x-for="r in regencies" :key="r.code">
                            <option :value="r.code" x-text="`${r.name} (${r.province})`" :selected="r.code === city"></option>
                        </template>
                    </select>
                    @error('city_code')
                        <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label for="district_code" class="{{ $labelClass }}">Kecamatan</label>
                    <select id="district_code" name="district_code" x-model="district" @change="loadVillages()" :disabled="!city || loading"
                        class="{{ $selectClass }} {{ $border('district_code') }}">
                        <option value="">-</option>
                        <template x-for="d in districts" :key="d.code">
                            <option :value="d.code" x-text="d.name" :selected="d.code === district"></option>
                        </template>
                    </select>
                    @error('district_code')
                        <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label for="village_code" class="{{ $labelClass }}">Kelurahan / Desa</label>
                    <select id="village_code" name="village_code" x-model="village" :disabled="!district || loading"
                        class="{{ $selectClass }} {{ $border('village_code') }}">
                        <option value="">-</option>
                        <template x-for="v in villages" :key="v.code">
                            <option :value="v.code" x-text="v.name" :selected="v.code === village"></option>
                        </template>
                    </select>
                    @error('village_code')
                        <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                    @enderror
                </div>
                <div class="md:col-span-3">
                    <label for="alamat" class="{{ $labelClass }}">Alamat Lengkap</label>
                    <textarea id="alamat" name="alamat" rows="3" maxlength="500"
                        class="shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 w-full rounded-lg border bg-transparent px-4 py-2.5 text-sm text-gray-800 focus:ring-3 focus:outline-hidden dark:bg-gray-900 dark:text-white/90 {{ $border('alamat') }}">{{ $val('alamat', $member->alamat) }}</textarea>
                    @error('alamat')
                        <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </div>

        <!-- Kendaraan -->
        <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="border-b border-gray-100 px-5 py-4 sm:px-6 dark:border-gray-800">
                <h3 class="{{ $sectionTitle }}">Kendaraan</h3>
                <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">Kendaraan yang diisi / diubah admin tercatat terverifikasi oleh admin.</p>
            </div>
            <div class="space-y-6 px-5 py-6 sm:px-6">
                <div class="flex flex-wrap gap-6">
                    <input type="hidden" name="has_vehicle" :value="hasVehicle ? 1 : 0" />
                    <label class="flex cursor-pointer items-center gap-2 text-sm text-gray-700 dark:text-gray-400">
                        <input type="radio" :checked="hasVehicle" @change="hasVehicle = true" class="accent-brand-500 h-4 w-4" /> Punya mobil listrik
                    </label>
                    <label class="flex cursor-pointer items-center gap-2 text-sm text-gray-700 dark:text-gray-400">
                        <input type="radio" :checked="!hasVehicle" @change="hasVehicle = false" class="accent-brand-500 h-4 w-4" /> Belum punya
                    </label>
                </div>

                <div x-show="hasVehicle" x-cloak class="grid grid-cols-1 gap-6 md:grid-cols-3">
                    <div class="md:col-span-3">
                        <span class="{{ $labelClass }}">No. Plat{!! $required !!}</span>
                        <div class="grid grid-cols-3 gap-3 sm:max-w-md">
                            <input type="text" name="plat_kode" maxlength="2" placeholder="B" aria-label="Kode wilayah plat"
                                value="{{ $val('plat_kode', $vehicle?->plat_kode) }}" class="{{ $inputClass }} uppercase {{ $border('plat_kode') }}" />
                            <input type="text" name="plat_nomor" maxlength="4" inputmode="numeric" placeholder="1234" aria-label="Nomor plat"
                                value="{{ $val('plat_nomor', $vehicle?->plat_nomor) }}" class="{{ $inputClass }} {{ $border('plat_nomor') }}" />
                            <input type="text" name="plat_seri" maxlength="3" placeholder="XYZ" aria-label="Huruf belakang plat"
                                value="{{ $val('plat_seri', $vehicle?->plat_seri) }}" class="{{ $inputClass }} uppercase {{ $border('plat_seri') }}" />
                        </div>
                        @foreach (['plat_kode', 'plat_nomor', 'plat_seri'] as $field)
                            @error($field)
                                <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                            @enderror
                        @endforeach
                    </div>
                    <div>
                        <label for="plat_status" class="{{ $labelClass }}">Status No. Plat{!! $required !!}</label>
                        <select id="plat_status" name="plat_status" class="{{ $selectClass }} {{ $border('plat_status') }}">
                            @foreach (['permanen' => 'Permanen', 'sementara' => 'Sementara'] as $value => $label)
                                <option value="{{ $value }}" @selected($val('plat_status', $vehicle?->plat_status ?? 'permanen') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('plat_status')
                            <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="vehicle_brand_id" class="{{ $labelClass }}">Merek</label>
                        <select id="vehicle_brand_id" name="vehicle_brand_id" x-model="brand" @change="model = ''"
                            class="{{ $selectClass }} {{ $border('vehicle_brand_id') }}">
                            <option value="">-</option>
                            <template x-for="b in brands" :key="b.id">
                                <option :value="String(b.id)" x-text="b.name" :selected="String(b.id) === brand"></option>
                            </template>
                        </select>
                        @error('vehicle_brand_id')
                            <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="vehicle_model_id" class="{{ $labelClass }}">Tipe</label>
                        <select id="vehicle_model_id" name="vehicle_model_id" x-model="model" :disabled="!brand"
                            class="{{ $selectClass }} {{ $border('vehicle_model_id') }}">
                            <option value="">-</option>
                            <template x-for="m in models" :key="m.id">
                                <option :value="String(m.id)" x-text="m.name" :selected="String(m.id) === model"></option>
                            </template>
                        </select>
                        @error('vehicle_model_id')
                            <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="vin" class="{{ $labelClass }}">No. Rangka (VIN)</label>
                        <input type="text" id="vin" name="vin" maxlength="{{ UpdateMemberProfileRequest::VIN_LENGTH }}"
                            value="{{ $val('vin', $vehicle?->vin) }}" class="{{ $inputClass }} uppercase {{ $border('vin') }}" />
                        @error('vin')
                            <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="tahun" class="{{ $labelClass }}">Tahun</label>
                        <input type="number" id="tahun" name="tahun" min="1990" max="{{ now()->year + 1 }}"
                            value="{{ $val('tahun', $vehicle?->tahun) }}" class="{{ $inputClass }} {{ $border('tahun') }}" />
                        @error('tahun')
                            <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label for="warna" class="{{ $labelClass }}">Warna</label>
                        <input type="text" id="warna" name="warna" maxlength="30"
                            value="{{ $val('warna', $vehicle?->warna) }}" class="{{ $inputClass }} {{ $border('warna') }}" />
                        @error('warna')
                            <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
                @if ($isEdit && $vehicle && ! $hasVehicle)
                    <p class="text-theme-xs text-gray-500 dark:text-gray-400">Data kendaraan lama tetap tersimpan sebagai riwayat.</p>
                @endif
            </div>
        </div>

        <!-- Catatan -->
        <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="space-y-2 px-5 py-6 sm:px-6">
                <label for="notes" class="{{ $labelClass }}">Catatan Admin</label>
                <textarea id="notes" name="notes" rows="3" maxlength="{{ MemberRequest::MAX_NOTES }}"
                    class="shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 w-full rounded-lg border bg-transparent px-4 py-2.5 text-sm text-gray-800 focus:ring-3 focus:outline-hidden dark:bg-gray-900 dark:text-white/90 {{ $border('notes') }}">{{ $val('notes', $member->notes) }}</textarea>
                @error('notes')
                    <p class="text-error-500 mt-1.5 text-theme-xs">{{ $message }}</p>
                @enderror
                <p class="{{ $hint }}">Hanya terlihat di CMS.</p>
            </div>
        </div>

        <div class="flex items-center justify-end gap-3">
            <a href="{{ $isEdit ? route('members.show', $member) : route('members.index') }}"
                class="shadow-theme-xs inline-flex h-11 items-center justify-center rounded-lg border border-gray-300 bg-white px-4 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.03]">
                Batal
            </a>
            <button type="submit"
                class="bg-brand-500 shadow-theme-xs hover:bg-brand-600 inline-flex h-11 items-center justify-center rounded-lg px-5 text-sm font-medium text-white transition">
                {{ $isEdit ? 'Simpan Perubahan' : 'Tambah Member' }}
            </button>
        </div>
    </form>

    <script>
        function memberForm(init) {
            return {
                ...init,
                loading: false,
                get models() {
                    return this.brands.find((b) => String(b.id) === this.brand)?.models ?? [];
                },
                async fetchList(url) {
                    this.loading = true;
                    try {
                        const res = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                        return res.ok ? (await res.json()).data : [];
                    } catch {
                        return [];
                    } finally {
                        this.loading = false;
                    }
                },
                async loadDistricts() {
                    this.district = '';
                    this.village = '';
                    this.districts = [];
                    this.villages = [];
                    if (this.city) {
                        this.districts = await this.fetchList(`${this.regionsUrl}/${encodeURIComponent(this.city)}/districts`);
                    }
                },
                async loadVillages() {
                    this.village = '';
                    this.villages = [];
                    if (this.district) {
                        this.villages = await this.fetchList(`${this.regionsUrl}/districts/${encodeURIComponent(this.district)}/villages`);
                    }
                },
            };
        }
    </script>
@endsection
