param(
  [string]$src = "C:\laragon\www\VIP-system\capstone\assets\images\vip_logo.jpg",
  [string]$out192 = "C:\laragon\www\VIP-system\capstone\assets\images\pwa-icon-192.png",
  [string]$out512 = "C:\laragon\www\VIP-system\capstone\assets\images\pwa-icon-512.png"
)

Add-Type -AssemblyName System.Drawing
$img = [System.Drawing.Image]::FromFile($src)

function Resize-Icon {
  param(
    [int]$size,
    [string]$outPath,
    [System.Drawing.Image]$image
  )
  $bmp = New-Object System.Drawing.Bitmap $size, $size
  $g = [System.Drawing.Graphics]::FromImage($bmp)
  $g.Clear([System.Drawing.Color]::Transparent)
  $g.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
  $g.DrawImage($image, 0, 0, $size, $size)
  $bmp.Save($outPath, [System.Drawing.Imaging.ImageFormat]::Png)
  $g.Dispose()
  $bmp.Dispose()
}

Resize-Icon -size 192 -outPath $out192 -image $img
Resize-Icon -size 512 -outPath $out512 -image $img

$img.Dispose()
Write-Output "icons_created"

