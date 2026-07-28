# Applies any .sql files in sql/migrations/ against the press_jemc database.
# Safe to re-run — migrations use CREATE TABLE IF NOT EXISTS / ADD COLUMN IF NOT EXISTS.

$psql = "C:/Program Files/PostgreSQL/15/bin/psql.exe"
$env:PGPASSWORD = "Nepal@123"

$migrationsDir = Join-Path $PSScriptRoot "migrations"
$files = Get-ChildItem -Path $migrationsDir -Filter "*.sql" | Sort-Object Name

foreach ($f in $files) {
    Write-Host "Applying $($f.Name)..."
    & $psql -h localhost -p 5432 -U postgres -d press_jemc -v ON_ERROR_STOP=1 -f $f.FullName
    if ($LASTEXITCODE -ne 0) {
        Write-Host "FAILED at $($f.Name)" -ForegroundColor Red
        exit 1
    }
}

Write-Host "All migrations applied." -ForegroundColor Green
