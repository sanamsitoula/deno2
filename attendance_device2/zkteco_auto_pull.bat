@echo off
REM ============================================
REM ZKTeco Attendance Auto-Pull Scheduler
REM Windows Task Scheduler BAT File
REM ============================================

REM Set your PHP path (update this to match your installation)
SET PHP_PATH=C:\xampp\php\php.exe

REM Set your web root path
SET WEB_ROOT=C:\xampp\htdocs

REM Set the puller script path
SET SCRIPT_PATH=%WEB_ROOT%\deno2\attendance_device\zkteco_puller.php

REM Get current time in HH:MM format
FOR /F "tokens=1-2 delims=:" %%a IN ("%TIME%") DO SET HOUR=%%a& SET MINUTE=%%b
SET CURRENT_TIME=%HOUR:~-2%:%MINUTE%

REM Determine schedule type based on time
SET SCHEDULE_TYPE=

IF "%CURRENT_TIME%" == "07:35" SET SCHEDULE_TYPE=morning
IF "%CURRENT_TIME%" == "10:45" SET SCHEDULE_TYPE=midmorning
IF "%CURRENT_TIME%" == "13:25" SET SCHEDULE_TYPE=afternoon
IF "%CURRENT_TIME%" == "17:25" SET SCHEDULE_TYPE=evening
IF "%CURRENT_TIME%" == "19:15" SET SCHEDULE_TYPE=night

REM If no schedule matches, exit
IF "%SCHEDULE_TYPE%" == "" (
    echo No schedule defined for current time: %CURRENT_TIME%
    exit /b 0
)

echo ============================================
echo ZKTeco Attendance Pull - %SCHEDULE_TYPE%
echo Time: %CURRENT_TIME%
echo ============================================

REM Run the puller
"%PHP_PATH%" "%SCRIPT_PATH%" --schedule=%SCHEDULE_TYPE% --doc_root=%WEB_ROOT%

REM Check exit code
IF %ERRORLEVEL% EQU 0 (
    echo Pull completed successfully
) ELSE (
    echo Pull failed with error code %ERRORLEVEL%
)

echo ============================================
exit /b %ERRORLEVEL%
