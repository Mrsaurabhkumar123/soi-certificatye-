@echo off
echo Starting SOI Certificate Management Platform on http://localhost:8000 ...
set SOI_CERT_ENV=development
set SOI_CERT_STANDALONE_DEMO=1
"C:\xampp\php\php.exe" -S localhost:8000 index.php
