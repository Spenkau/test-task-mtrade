## Запуск задания

Требуются **PHP** и Composer.

1. Установить зависимости из корня проекта:

   ```bash
   composer install
   ```

2. Создать `.env`, если его ещё нет:

   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

3. Указать в `.env`:

   ```dotenv
   IMPORT_DRIVER=file
   IMPORT_TOKEN=
   IMPORT_PATH=orders.json
   ```

4. Поместить исходные файлы в `storage` (при скачивании с гит они там по умолчанию):

   ```text
   storage/orders.json
   storage/nomenclature_1c.csv
   ```

5. Запустить проверку:

   ```bash
   php artisan config:clear
   php artisan orders:check
   ```

CSV-отчёт появится в `storage/app/reports/`. Ошибки обработки записываются в лог, при стандартной настройке — `storage/logs/laravel.log`.


ДОПОЛНЕНИЕ:
