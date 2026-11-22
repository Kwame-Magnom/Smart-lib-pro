[CmdletBinding()]
param(
    [string]$Time = '02:00'
)

$ErrorActionPreference = 'Stop'
$projectPath = $PSScriptRoot
$phpPath = 'C:\xampp\php\php.exe'
$backupJob = Join-Path $projectPath 'backup_database.php'

if (-not (Test-Path -LiteralPath $phpPath)) { throw "PHP was not found at $phpPath" }
if (-not (Test-Path -LiteralPath $backupJob)) { throw "Backup job was not found at $backupJob" }
if ($Time -notmatch '^([01]\d|2[0-3]):[0-5]\d$') { throw 'Time must use 24-hour HH:mm format.' }

$action = New-ScheduledTaskAction -Execute $phpPath -Argument ('"{0}"' -f $backupJob) -WorkingDirectory $projectPath
$trigger = New-ScheduledTaskTrigger -Daily -At $Time
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew
Register-ScheduledTask -TaskName 'SecurePortalDatabaseBackup' -Action $action -Trigger $trigger -Settings $settings -Description 'Daily Secure Portal database backup' -Force | Out-Null

Write-Host "Daily backup task created. It runs at $Time and writes outside the web root to C:\xampp\backups\secure-portal."
