@echo off
REM ================================================================
REM ZKTeco Attendance Pull - Evening Schedule (05:25 PM)
REM Captures evening check-out punches
REM ================================================================

SET PHP_PATH=C:\php\php.exe
SET SCRIPT_PATH=C:\xampp\htdocs\deno2\attendance_device\zkteco_puller.php
SET LOG_PATH=C:\xampp\htdocs\deno2\attendance_device\logs\zkteco\scheduler_evening.log

echo ================================================= >> %LOG_PATH%
echo Evening Pull Started: %date% %time% >> %LOG_PATH%
echo ================================================= >> %LOG_PATH%

%PHP_PATH% %SCRIPT_PATH% --schedule=evening >> %LOG_PATH% 2>&1

echo. >> %LOG_PATH%
echo Evening Pull Completed: %date% %time% >> %LOG_PATH%
echo ================================================= >> %LOG_PATH%
echo. >> %LOG_PATH%

exit /b %ERRORLEVEL%
