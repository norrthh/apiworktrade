#!/bin/sh
set -e
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app bootstrap/cache database
touch database/database.sqlite storage/logs/laravel.log
exec php artisan serve --host=0.0.0.0 --port=8000
