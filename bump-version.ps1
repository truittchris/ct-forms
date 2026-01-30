Param(
  [Parameter(Mandatory=$true)]
  [string]$Version
)

$ErrorActionPreference = "Stop"

$Root = Split-Path -Parent $MyInvocation.MyCommand.Path
$CtPhp = Join-Path $Root "ct-forms.php"
$Readme = Join-Path $Root "README.txt"
$Changelog = Join-Path $Root "CHANGELOG.md"

function Replace-InFile {
  param([string]$Path, [string]$Pattern, [string]$Replacement)
  $content = Get-Content -Raw -Encoding UTF8 $Path
  $new = [regex]::Replace($content, $Pattern, $Replacement, [System.Text.RegularExpressions.RegexOptions]::Multiline)
  if ($new -ne $content) {
    Set-Content -Encoding UTF8 -NoNewline $Path $new
    Add-Content -Encoding UTF8 $Path ""
  }
}

# ct-forms.php: header Version and CT_FORMS_VERSION constant
Replace-InFile -Path $CtPhp -Pattern '(^\s*\*\s*Version:\s*)\d+\.\d+\.\d+\s*$' -Replacement ('$1' + $Version)
Replace-InFile -Path $CtPhp -Pattern "(define\(\s*'CT_FORMS_VERSION'\s*,\s*')\d+\.\d+\.\d+('\s*\)\s*;)" -Replacement ('$1' + $Version + '$2')

# README.txt first line
Replace-InFile -Path $Readme -Pattern '^(CT Forms \(v)\d+\.\d+\.\d+(\))\s*$' -Replacement ('$1' + $Version + '$2')

# CHANGELOG prepend entry if missing
$ch = Get-Content -Raw -Encoding UTF8 $Changelog
if ($ch -notmatch ("^##\s+" + [regex]::Escape($Version) + "\b")) {
  $today = Get-Date -Format "yyyy-MM-dd"
  $lines = $ch -split "`n"
  if ($lines.Count -ge 2) {
    $newLines = @()
    $newLines += $lines[0]
    $newLines += ""
    $newLines += "## $Version – $today"
    $newLines += "- Version bump (automated)."
    $newLines += ""
    $newLines += $lines[1..($lines.Count-1)]
    $out = ($newLines -join "`n").TrimEnd() + "`n"
    Set-Content -Encoding UTF8 $Changelog $out
  }
}

Write-Host "Bumped to $Version"

$build = Join-Path $Root "build-zip.ps1"
if (Test-Path $build) {
  & $build
} else {
  Write-Host "build-zip.ps1 not found. Run your packaging step manually."
}
