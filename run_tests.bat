@echo off
echo Running Unit and Multi-Tenant Isolation Tests...
php -d extension_dir="C:\tools\php85\ext" -d extension=pdo_sqlite -d extension=pdo_mysql tests/run_tests.php
if %ERRORLEVEL% NEQ 0 exit /b %ERRORLEVEL%

echo.
echo Running HTTP Smoke Tests...
php -d extension_dir="C:\tools\php85\ext" -d extension=pdo_sqlite -d extension=pdo_mysql tests/http_smoke_test.php
if %ERRORLEVEL% NEQ 0 exit /b %ERRORLEVEL%

echo.
echo Packaging Update Center Cumulative Release...
php -d extension_dir="C:\tools\php85\ext" -d extension=zip package.php
