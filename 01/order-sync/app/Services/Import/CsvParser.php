<?php

declare(strict_types=1);

namespace App\Services\Import;

use Generator;
use League\Csv\Reader;
use RuntimeException;

final class CsvParser
{
    public function parse(string $path, string $delimiter = ';'): Generator
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("file read error {$path}");
        }

        $csv = Reader::from($path);

        $csv->setDelimiter($delimiter);
        $csv->setEnclosure('"');
        $csv->setEscape('');
        $csv->setHeaderOffset(0);

        foreach ($csv->getRecords() as $record) {
            yield $record;
        }
    }
}
