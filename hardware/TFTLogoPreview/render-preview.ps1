Add-Type -AssemblyName System.Drawing

$root = Split-Path -Parent $PSScriptRoot
$logoHeader = Join-Path $root 'DCSAttendanceTFT/cvsu_logo.h'
$dcsLogoHeader = Join-Path $root 'DCSAttendanceTFT/dcs_logo.h'
$output = Join-Path $PSScriptRoot 'tft-logo-preview.png'

function Convert-Rgb565([uint16]$value) {
  $red = (($value -shr 11) -band 0x1F) * 255 / 31
  $green = (($value -shr 5) -band 0x3F) * 255 / 63
  $blue = ($value -band 0x1F) * 255 / 31
  return [System.Drawing.Color]::FromArgb([int]$red, [int]$green, [int]$blue)
}

$values = [regex]::Matches((Get-Content $logoHeader -Raw), '0x[0-9A-Fa-f]{4}') |
  ForEach-Object { [Convert]::ToUInt16($_.Value.Substring(2), 16) }
$dcsValues = [regex]::Matches((Get-Content $dcsLogoHeader -Raw), '0x[0-9A-Fa-f]{4}') |
  ForEach-Object { [Convert]::ToUInt16($_.Value.Substring(2), 16) }

if ($values.Count -lt 4096 -or $dcsValues.Count -lt 4096) {
  throw 'The logo data is incomplete.'
}

$green = [System.Drawing.ColorTranslator]::FromHtml('#064C36')
$gold = [System.Drawing.ColorTranslator]::FromHtml('#FFD54A')
$ink = [System.Drawing.ColorTranslator]::FromHtml('#202020')
$muted = [System.Drawing.ColorTranslator]::FromHtml('#666666')
$rule = [System.Drawing.ColorTranslator]::FromHtml('#D9D9D9')
$canvas = New-Object System.Drawing.Bitmap 480, 320
$graphics = [System.Drawing.Graphics]::FromImage($canvas)
$graphics.TextRenderingHint = [System.Drawing.Text.TextRenderingHint]::AntiAliasGridFit
$graphics.Clear([System.Drawing.Color]::White)
$graphics.FillRectangle((New-Object System.Drawing.SolidBrush $green), 0, 0, 480, 76)
$graphics.FillRectangle((New-Object System.Drawing.SolidBrush $gold), 0, 76, 480, 2)

function Draw-Logo([uint16[]]$logo, [int]$x, [int]$y) {
  for ($row = 0; $row -lt 64; $row++) {
    for ($column = 0; $column -lt 64; $column++) {
      $color = Convert-Rgb565 $logo[$row * 64 + $column]
      if ($color.ToArgb() -ne $green.ToArgb()) {
        $canvas.SetPixel($x + $column, $y + $row, $color)
      }
    }
  }
}

Draw-Logo $values 12 6
Draw-Logo $dcsValues 404 6

$whiteBrush = New-Object System.Drawing.SolidBrush ([System.Drawing.Color]::White)
$goldBrush = New-Object System.Drawing.SolidBrush $gold
$inkBrush = New-Object System.Drawing.SolidBrush $ink
$mutedBrush = New-Object System.Drawing.SolidBrush $muted
$greenBrush = New-Object System.Drawing.SolidBrush $green
$titleFont = New-Object System.Drawing.Font 'Arial', 24, ([System.Drawing.FontStyle]::Regular)
$subtitleFont = New-Object System.Drawing.Font 'Arial', 13, ([System.Drawing.FontStyle]::Regular)
$dateFont = New-Object System.Drawing.Font 'Arial', 20, ([System.Drawing.FontStyle]::Regular)
$clockFont = New-Object System.Drawing.Font 'Arial', 45, ([System.Drawing.FontStyle]::Regular)
$footerFont = New-Object System.Drawing.Font 'Arial', 11, ([System.Drawing.FontStyle]::Regular)
$graphics.DrawString('CvSU IMUS', $titleFont, $whiteBrush, 92, 10)
$graphics.DrawString('DCS / FACULTY ATTENDANCE', $subtitleFont, $goldBrush, 94, 45)

function Draw-Centered([string]$text, [System.Drawing.Font]$font, [System.Drawing.Brush]$brush, [int]$centerX, [int]$top) {
  $size = $graphics.MeasureString($text, $font)
  $graphics.DrawString($text, $font, $brush, $centerX - $size.Width / 2, $top)
}

Draw-Centered 'Mon, Oct 12, 2026' $dateFont $mutedBrush 240 108
Draw-Centered '08:42:16 AM' $clockFont $inkBrush 240 159
$graphics.DrawLine((New-Object System.Drawing.Pen $rule), 16, 257, 464, 257)
$graphics.FillEllipse($greenBrush, 16, 277, 8, 8)
$graphics.DrawString('WI-FI CONNECTED', $footerFont, $mutedBrush, 34, 272)
$pending = 'PENDING 0'
$pendingSize = $graphics.MeasureString($pending, $footerFont)
$graphics.DrawString($pending, $footerFont, $mutedBrush, 462 - $pendingSize.Width, 272)

$canvas.Save($output, [System.Drawing.Imaging.ImageFormat]::Png)
$graphics.Dispose()
$canvas.Dispose()
Write-Output "Created $output"
