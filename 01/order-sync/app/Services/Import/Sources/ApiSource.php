<?php

namespace App\Services\Import\Sources;

use App\Contracts\DataSourceInterface;
use Illuminate\Support\Facades\Http;

class ApiSource implements DataSourceInterface
{
    public function __construct(
        private readonly string $url,
        private readonly ?string $token = null,
    ) {}

    public function read(): string
    {
        $request = Http::connectTimeout(5)
            ->timeout(60);

        if (isset($this->token)) {
            $request = $request->withToken($this->token);
        }

        $response = $request->get($this->url);
        $response->onError(fn () => throw new \RuntimeException("api request error"));

        return $response->body();
    }
}
