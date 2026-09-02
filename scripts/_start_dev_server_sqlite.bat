@echo off
set DB_CONNECTION=sqlite
set DB_DATABASE=C:\travile\SafarakEalayna\storage\app\local_flight_audit.sqlite
cd /D C:\travile\SafarakEalayna
php artisan serve --port=8080 --host=127.0.0.1
