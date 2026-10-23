<?php

namespace App\Providers;

use App\Contracts\DataSourceInterface;
use App\Services\Import\Sources\ApiSource;
use App\Services\Import\Sources\FileSource;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            DataSourceInterface::class,
            function (): DataSourceInterface {
                $path = config('import.path');

                if (!isset($path)) {
                    throw new \InvalidArgumentException('IMPORT_PATH отсутствует');
                }

                return match (config('import.driver')) {
                    'file' => new FileSource(
                        storage_path($path),
                    ),

                    'api' => new ApiSource(
                        url: $path,
                        token: config('import.token'),
                    ),

                    default => throw new \InvalidArgumentException(
                        'IMPORT_DRIVER должен быть file или api.'
                    ),
                };
            },
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
