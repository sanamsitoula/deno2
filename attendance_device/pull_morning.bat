@echo off
REM ================================================================
REM ZKTeco Attendance Pull - Morning Schedule (07:35 AM)
REM Captures morning check-in punches
REM
REM FIX: Pass --doc_root so PHP knows the web root when running
REM      as a CLI process under Task Scheduler (no IIS/Apache env)
REM ================================================================

SET PHP_PATH=C:\php\php.exe
SET SCRIPT_PATH=C:\xampp\htdocs\deno2\attendance_device\zkteco_puller.php
SET DOC_ROOT=C:\xampp\htdocs
SET LOG_PATH=C:\xampp\htdocs\deno2\attendance_device\logs\zkteco\scheduler_morning.log

echo ================================================= >> %LOG_PATH%
echo Morning Pull Started: %date% %time%               >> %LOG_PATH%
echo ================================================= >> %LOG_PATH%

REM Execute PHP script — pass doc_root explicitly
"%PHP_PATH%" "%SCRIPT_PATH%" --schedule=morning --doc_root="%DOC_ROOT%" >> %LOG_PATH% 2>&1

SET EXIT_CODE=%ERRORLEVEL%

echo.                                                   >> %LOG_PATH%
echo Morning Pull Completed: %date% %time%              >> %LOG_PATH%
echo Exit Code: %EXIT_CODE%                             >> %LOG_PATH%
echo ================================================= >> %LOG_PATH%
echo.                                                   >> %LOG_PATH%

exit /b %EXIT_CODE%
