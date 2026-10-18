<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Generate static bearer token untuk API frontend dan tulis ke .env (FRONTEND_API_TOKEN).
 *
 *   php artisan api:frontend-token          # generate & simpan ke .env
 *   php artisan api:frontend-token --show   # hanya tampilkan, tidak menyimpan
 *   php artisan api:frontend-token --force  # timpa token lama
 *
 * Salin token yang sama ke fe/.env.local (BACKEND_API_TOKEN), lalu jalankan `php artisan config:clear`.
 */
class GenerateFrontendToken extends Command
{
    protected $signature = 'api:frontend-token
        {--show : Hanya tampilkan token, tidak menyimpan ke .env}
        {--force : Timpa token yang sudah ada}';

    protected $description = 'Generate bearer token untuk API yang dikonsumsi frontend';

    private const KEY = 'FRONTEND_API_TOKEN';

    public function handle(): int
    {
        $token = Str::random(64);

        if ($this->option('show')) {
            $this->line($token);

            return self::SUCCESS;
        }

        $path = $this->laravel->environmentFilePath();

        if (! is_file($path)) {
            $this->error('.env tidak ditemukan.');

            return self::FAILURE;
        }

        $env = file_get_contents($path);
        $pattern = '/^'.self::KEY.'=.*$/m';
        $exists = preg_match($pattern, $env, $match) === 1;

        if ($exists && trim(substr($match[0], strlen(self::KEY) + 1)) !== '' && ! $this->option('force')) {
            $this->warn(self::KEY.' sudah ada. Pakai --force untuk menimpa (token lama di frontend jadi tidak berlaku).');

            return self::FAILURE;
        }

        $line = self::KEY.'='.$token;
        $env = $exists
            ? preg_replace($pattern, $line, $env)
            : rtrim($env).PHP_EOL.PHP_EOL.$line.PHP_EOL;

        file_put_contents($path, $env);

        $this->info('Token disimpan ke .env:');
        $this->line($token);
        $this->newLine();
        $this->line('Salin ke fe/.env.local sebagai BACKEND_API_TOKEN, lalu jalankan `php artisan config:clear`.');

        return self::SUCCESS;
    }
}
