<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use League\Csv\Reader;
use League\Csv\Writer;
use RuntimeException;
use Throwable;

#[Description('Сверка заказов с маркета с номенклатурой 1с')]
final class CheckOrders extends Command
{
    protected $signature = 'orders:check';

    public function handle(): int
    {
        try {
            $orders = $this->loadOrders();

            [$catalog, $catalogErrors] = $this->loadCatalog();

            $reports = $this->checkOrders($orders, $catalog);

            if ($catalogErrors > 0) {
                foreach ($reports as &$report) {
                    if ($report['action'] === 'ПРОВЕРИТЬ') {
                        $report['issues'][] = 'номенклатура 1С содержит повреждённые записи, исправить и повторить проверку';
                    }
                }

                unset($report);
            }

            $rows = [];

            foreach ($reports as $report) {
                $issues = array_values(array_unique($report['issues']));

                $action = $report['action'];

                if ($action === 'ПРОВЕРИТЬ' && empty($issues)) {
                    $action = 'ГОТОВ К ПЕРЕДАЧЕ';
                }

                $rows[] = [
                    $report['id'],
                    $action,
                    $issues === []
                        ? 'Расхождений не найдено'
                        : implode(' | ', $issues),
                ];
            }

            $path = $this->writeReport($rows);

            echo 'Проверено записей заказов: ' . count($orders) . "\n";
            echo "Отчёт сохранён: {$path}" . "\n";

            if ($catalogErrors > 0) {
                $this->error(
                    "Повреждённых записей CSV: {$catalogErrors}. Подробности в логах"
                );
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            Log::error('orders.check.failed', [
                'exception' => $exception,
            ]);

            $this->error('произошла ошибка. проверьте файлы номенклатуры, заказов, env и логи');

            return self::FAILURE;
        }
    }
    private function loadOrders(): array
    {
        $path = (string) config('import.path');

        $content = match (config('import.driver')) {
            'file' => $this->readFile(storage_path($path)),
            'api' => $this->readApi($path),
            default => throw new RuntimeException(
                'IMPORT_DRIVER должен быть file или api'
            ),
        };

        $document = json_decode($content, true, flags: JSON_THROW_ON_ERROR);

        $orders = $document['data']['orders'] ?? null;

        if (! is_array($orders)) {
            throw new RuntimeException('В ответе нет data.orders');
        }

        return $orders;
    }

    private function readApi(string $url): string
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Невалидный IMPORT_PATH в env');
        }

        $request = Http::acceptJson()->timeout(10);

        $token = config('import.token');

        if (empty($token)) {
            $request = $request->withToken($token);
        }

        $response = $request->get($url);
        $response->throwIfStatus(fn (int $status, Response $response) => $status >= 300);

        return $response->body();
    }

    private function readFile(string $path): string
    {
        if (! is_file($path)) {
            throw new RuntimeException("файл не существует!!: {$path}");
        }

        $content = file_get_contents($path);

        if (empty($content)) {
            throw new RuntimeException("нет данных в файле: {$path}");
        }

        return $content;
    }

    private function loadCatalog(): array
    {
        $path = storage_path((string) config('import.nomenclature'));

        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("CSV 1С нет: {$path}");
        }

        $csv = Reader::from($path, 'r');

        $csv->setDelimiter(';');
        $csv->setEnclosure('"');
        $csv->setEscape('');

        $expectedHeader = ['Код', 'Артикул', 'Наименование','ЕдИзм', 'Цена', 'Остаток'];

        $catalog = [];
        $errors = 0;
        $headerFound = false;

        foreach ($csv->getRecords() as $offset => $row) {
            if (! $headerFound) {
                if ($row !== $expectedHeader) {
                    throw new RuntimeException('Неверные заголовки CSV 1С');
                }

                $headerFound = true;

                continue;
            }

            try {
                $record = array_combine($expectedHeader, $row);

                $sku = $this->sku($record['Артикул']);

                if (trim($record['Код']) === '') {
                    throw new RuntimeException('Пустой код 1С');
                }

                $catalog[$sku][] = [
                    'code' => $record['Код'],
                    'price' => $this->kopecks($record['Цена']),
                    'stock' => $this->toFloat($record['Остаток']),
                ];
            } catch (Throwable $exception) {
                $errors++;

                Log::warning('orders.catalog.invalid_record', [
                    'position' => $offset + 1,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if (! $headerFound) {
            throw new RuntimeException('CSV 1С пуст');
        }

        return [$catalog, $errors];
    }

    private function checkOrders(array $orders, array $catalog): array
    {
        $reports = [];
        $seen = [];
        $demand = [];

        foreach ($orders as $i => $order) {
            $id = (string) ($order['id'] ?? 'запись ' . ($i + 1));

            $report = [
                'id' => $id,
                'action' => 'ПРОВЕРИТЬ',
                'issues' => [],
                'lines' => [],
            ];

            try {
                if (isset($seen[$id])) {
                    $report['action'] = 'ДУБЛЬ — НЕ ПЕРЕДАВАТЬ';
                    $report['issues'][] =
                        'Номер заказа повторяется, учитывается только первая запись';
                    $reports[] = $report;

                    continue;
                }

                $seen[$id] = true;

                if (($order['status'] ?? null) === 'cancelled') {
                    $report['action'] = 'НЕ ПЕРЕДАВАТЬ';
                    $report['issues'][] = 'Заказ отменён';
                    $reports[] = $report;

                    continue;
                }

                if (empty($order['items'])) {
                    throw new RuntimeException('В заказе нет товаров');
                }

                foreach ($order['items'] as $n => $item) {
                    try {
                        $sku = $this->sku($item['sku'] ?? null);
                        $qty = $this->toFloat($item['quantity'] ?? null);

                        if (isset($report['lines'][$sku])) {
                            $report['issues'][] =
                                "{$sku}: артикул повторяется в заказе";
                        }

                        $report['lines'][$sku] =
                            ($report['lines'][$sku] ?? 0) + $qty;

                        $demand[$sku] = ($demand[$sku] ?? 0) + $qty;

                        $cards = $catalog[$sku] ?? [];

                        if ($cards === []) {
                            $report['issues'][] =
                                "{$sku}: артикула нет в 1С";

                            continue;
                        }

                        if (count($cards) > 1) {
                            $report['issues'][] =
                                "{$sku}: в 1С несколько карточек "
                                . implode(', ', array_column($cards, 'code'))
                                . ', уточнить какая нужна';

                            continue;
                        }

                        $price = $this->kopecks($item['price'] ?? null);

                        if ($price !== $cards[0]['price']) {
                            $report['issues'][] =
                                "{$sku}: цена в заказе " . $price / 100
                                . ', в 1С ' . $cards[0]['price'] / 100;
                        }
                    } catch (Throwable $exception) {
                        $report['issues'][] =
                            'Позиция ' . ($n + 1) . ': '
                            . $exception->getMessage();

                        Log::warning('orders.invalid_item', [
                            'order_id' => $id,
                            'position' => $n + 1,
                            'error' => $exception->getMessage(),
                        ]);
                    }
                }
            } catch (Throwable $exception) {
                $report['issues'][] = $exception->getMessage();

                Log::warning('orders.invalid_order', [
                    'order_id' => $id,
                    'error' => $exception->getMessage(),
                ]);
            }

            $reports[] = $report;
        }

        foreach ($reports as &$report) {
            foreach ($report['lines'] as $sku => $qty) {
                $cards = $catalog[$sku] ?? [];

                if (count($cards) !== 1) {
                    continue;
                }

                $stock = $cards[0]['stock'];

                if ($qty > $stock) {
                    $report['issues'][] =
                        "{$sku}: не хватает остатка, нужно {$qty}, на складе {$stock}";
                } elseif ($demand[$sku] > $stock) {
                    $report['issues'][] =
                        "{$sku}: на этот заказ хватает, но на все заказы вместе нет: "
                        . "нужно {$demand[$sku]}, на складе {$stock}";
                }
            }
        }

        unset($report);

        return $reports;
    }

    private function sku(mixed $value): string
    {
        $sku = mb_strtoupper(trim((string) $value));

        if ($sku === '') {
            throw new RuntimeException('Пустой артикул');
        }

        return $sku;
    }

    private function toFloat(mixed $value): float
    {
        $value = str_replace([' ', ','], ['', '.'], (string) $value);

        if (! is_numeric($value)) {
            throw new RuntimeException('Некорректное число');
        }

        return (float) $value;
    }

    private function kopecks(mixed $value): int
    {
        return (int) round($this->toFloat($value) * 100);
    }

    private function writeReport(array $rows): string
    {
        $dir = storage_path();

        $path = $dir . '/orders-check-' . now()->format('Ymd-His') . '.csv';

        file_put_contents($path, "\xEF\xBB\xBF");


        $csv = Writer::from($path, 'a');

        $csv->setDelimiter(';');
        $csv->setEnclosure('"');
        $csv->setEscape('');

        $csv->insertOne(['Заказ', 'Решение', 'Что сделать \проверить']);
        $csv->insertAll($rows);

        return $path;
    }
}
