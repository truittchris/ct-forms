\
    Param(
      [string]$PluginSlug = "ct-forms"
    )

    $ErrorActionPreference = "Stop"

    $RootDir = Split-Path -Parent $MyInvocation.MyCommand.Path
    Set-Location $RootDir

    $MainFile = Join-Path $RootDir "ct-forms.php"
    if (!(Test-Path $MainFile)) {
      throw "Cannot find ct-forms.php (run this from the plugin root)."
    }

    $versionLine = Select-String -Path $MainFile -Pattern '^\s*\*\s*Version:' -SimpleMatch | Select-Object -First 1
    if ($null -eq $versionLine) {
      throw "Could not determine Version from ct-forms.php header."
    }

    $Version = ($versionLine.Line -replace '.*Version:\s*','').Trim()
    if ([string]::IsNullOrWhiteSpace($Version)) {
      throw "Version value is empty."
    }

    $DistDir = Join-Path $RootDir "dist"
    $BuildDir = Join-Path $RootDir ".build"
    $ZipPath = Join-Path $DistDir "$PluginSlug-v$Version.zip"

    if (Test-Path $BuildDir) { Remove-Item $BuildDir -Recurse -Force }
    New-Item -ItemType Directory -Force -Path (Join-Path $BuildDir $PluginSlug) | Out-Null
    New-Item -ItemType Directory -Force -Path $DistDir | Out-Null

    $exclude = @(
      ".git", ".github", "dist", ".build", "node_modules", "*.zip", ".DS_Store", "Thumbs.db"
    )

    # Copy everything to staging except excludes
    Get-ChildItem -Path $RootDir -Force | ForEach-Object {
      $name = $_.Name
      foreach ($ex in $exclude) {
        if ($name -like $ex) { return }
      }
      Copy-Item -Path $_.FullName -Destination (Join-Path (Join-Path $BuildDir $PluginSlug) $name) -Recurse -Force
    }

    if (Test-Path $ZipPath) { Remove-Item $ZipPath -Force }

    Add-Type -AssemblyName System.IO.Compression.FileSystem
    [System.IO.Compression.ZipFile]::CreateFromDirectory($BuildDir, $ZipPath)

    Remove-Item $BuildDir -Recurse -Force
    Write-Host "Built: $ZipPath"
