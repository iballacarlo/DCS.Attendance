param(
    [ValidateSet('cvsu', 'dcs')]
    [string]$Logo = 'dcs'
)

Add-Type -AssemblyName System.Drawing
$newCvSUPng = Join-Path $PSScriptRoot 'cvsu-logo-new.png'
$newCvSUJpg = Join-Path $PSScriptRoot 'cvsu-logo-new.jpg'
$sourcePath = if ($Logo -eq 'cvsu' -and (Test-Path $newCvSUPng)) {
    $newCvSUPng
} elseif ($Logo -eq 'cvsu' -and (Test-Path $newCvSUJpg)) {
    $newCvSUJpg
} else {
    Join-Path $PSScriptRoot "../../public/images/$Logo-logo.png"
}
$source = [System.Drawing.Image]::FromFile($sourcePath)
$bitmap = New-Object System.Drawing.Bitmap 64, 64
$graphics = [System.Drawing.Graphics]::FromImage($bitmap)
$graphics.Clear([System.Drawing.ColorTranslator]::FromHtml('#064C36'))
$graphics.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
$graphics.DrawImage($source, 3, 3, 58, 58)
$lines = [System.Collections.Generic.List[string]]::new()
$lines.Add('#pragma once')
$lines.Add('#include <Arduino.h>')
$constantName = "$(($Logo.ToUpper()))_LOGO"
$lines.Add("// Generated from $([System.IO.Path]::GetFileName($sourcePath)) by generate-logo.ps1.")
$lines.Add("const uint16_t $constantName[64 * 64] PROGMEM = {")
for ($y = 0; $y -lt 64; $y++) {
    $pixels = for ($x = 0; $x -lt 64; $x++) {
        $color = $bitmap.GetPixel($x, $y)
        # The replacement CvSU image has a white square canvas. Its crest is a
        # diamond, so remove only pixels outside that diamond. The white book
        # pages and the white crest outline inside the boundary are retained.
        $outsideCvSUCrest = $Logo -eq 'cvsu' -and
            ([math]::Abs(($x + 0.5) - 32) + [math]::Abs(($y + 0.5) - 32) -gt 30.5)
        if ($outsideCvSUCrest -or
            ($Logo -eq 'dcs' -and $color.R -gt 245 -and $color.G -gt 245 -and $color.B -gt 245) -or
            ($Logo -eq 'cvsu' -and $color.R -lt 96 -and $color.G -lt 96 -and $color.B -lt 96)) {
            $color = [System.Drawing.ColorTranslator]::FromHtml('#064C36')
        }
        # The TFT renders the source crest's turquoise gradients as blue.
        # Gold is stable on this panel and keeps the small diamond readable.
        elseif ($Logo -eq 'cvsu' -and $color.G -gt ($color.R * 1.15) -and
                $color.G -gt ($color.B * 0.80)) {
            $color = [System.Drawing.ColorTranslator]::FromHtml('#FFD54A')
        }
        # The CvSU flame is red. Avoid a red/blue channel variation on some
        # ILI9488 modules by using the header's established gold instead.
        elseif ($Logo -eq 'cvsu' -and $color.R -gt 140 -and
                $color.R -gt ($color.G * 1.25) -and $color.R -gt ($color.B * 1.25)) {
            $color = [System.Drawing.ColorTranslator]::FromHtml('#FFD54A')
        }
        # Widen byte channels before shifting, otherwise PowerShell drops high bits.
        $rgb565 = (([int]$color.R -shr 3) -shl 11) -bor (([int]$color.G -shr 2) -shl 5) -bor ([int]$color.B -shr 3)
        '0x{0:X4}' -f $rgb565
    }
    $lines.Add('  ' + ($pixels -join ', ') + ',')
}
$lines.Add('};')
[System.IO.File]::WriteAllLines((Join-Path $PSScriptRoot "$Logo`_logo.h"), $lines, [System.Text.Encoding]::ASCII)
$graphics.Dispose()
$bitmap.Dispose()
$source.Dispose()
