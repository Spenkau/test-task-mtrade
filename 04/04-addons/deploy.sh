#!/usr/bin/env bash

set -Eeuo pipefail
umask 027

APP_DIR="${APP_DIR:-/var/www/b2b}"
APP_URL="${APP_URL:?Укажи APP_URL, например https://b2b.example.test}"
EXPECTED_DB_HOST="${EXPECTED_DB_HOST:?Укажи ожидаемый сервер БД}"
EXPECTED_DB_NAME="${EXPECTED_DB_NAME:-b2b}"

export EXPECTED_DB_HOST EXPECTED_DB_NAME

cd "$APP_DIR"

exec 9>"$APP_DIR/storage/framework/deploy.lock"

if ! flock -n 9; then
    echo "Другой деплой уже выполняется." >&2
    exit 1
fi

on_error() {
    local exit_code=$?

    echo "Деплой завершился ошибкой: код ${exit_code}." >&2
    echo "Автоматический откат и artisan up не выполняются." >&2
    echo "Проверь состояние приложения и миграций перед восстановлением." >&2

    exit "$exit_code"
}

trap on_error ERR

test -f .env.new
test -f composer.lock

echo "Включение режима обслуживания"
php artisan down --retry=60

echo "Обновление кода"
git pull --ff-only origin main

echo "Установка зависимостей"
composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --optimize-autoloader

echo "Установка production-конфигурации"
install -m 600 .env.new .env

echo "Пересборка кеша конфигурации"
php artisan config:cache

echo "Проверка целевой базы данных"
php <<'PHP'
<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require getcwd() . '/vendor/autoload.php';

$app = require getcwd() . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connection = DB::connection();

$actualHost = $connection->getConfig('host');
$actualDatabase = $connection->getConfig('database');

if ($actualHost !== getenv('EXPECTED_DB_HOST')) {
    throw new RuntimeException(
        'Сервер БД в конфигурации не совпадает с ожидаемым.'
    );
}

if ($actualDatabase !== getenv('EXPECTED_DB_NAME')) {
    throw new RuntimeException(
        'Имя БД в конфигурации не совпадает с ожидаемым.'
    );
}

$connection->select('SELECT 1');

fwrite(STDOUT, "Конфигурация БД и подключение проверены.\n");
PHP

echo "Запуск миграций"
php artisan migrate --force

echo "Подготовка представлений"
php artisan view:cache

echo "Отключение режима обслуживания"
php artisan up

echo "Проверка HTTP-ответа"
curl \
    --fail \
    --silent \
    --show-error \
    --connect-timeout 5 \
    --max-time 20 \
    --output /dev/null \
    "${APP_URL%/}/"

echo "Деплой успешно завершён"