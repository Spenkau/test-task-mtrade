<?php

namespace App\Services\Import\Sources;

use App\Contracts\DataSourceInterface;

class FileSource implements DataSourceInterface
{
    public function __construct(
        private readonly string $path,
    ) {}

    public function read(): string
    {
        if (! is_file($this->path) || ! is_readable($this->path)) {
            throw new \RuntimeException('Файл отсутствует или недоступен.');
        }

        $content = file_get_contents($this->path);

        if ($content === false) {
            throw new \RuntimeException('Не удалось прочитать файл.');
        }

        return $content;
    }
}
