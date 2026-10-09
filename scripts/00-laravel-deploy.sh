#!/usr/bin/env bash
set -e

APP_ROOT=/var/www/nssf-project-mgmt-2
cd "$APP_ROOT"

echo "Running composer"
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction

echo "Clearing stale config cache..."
php artisan config:clear || true

echo "Running migrations..."
php artisan migrate --force

echo "Seeding reference roles and permissions only..."
php artisan db:seed --class=ReferenceDataSeeder --force

echo "Caching config..."
php artisan config:cache

echo "Caching routes..."
php artisan route:cache
