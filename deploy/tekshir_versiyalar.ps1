# LIMS: loyiha fayllari eng oxirgi paket versiyasidami - tekshirish. Hech narsani o'zgartirmaydi.
#
# Ishlatish (loyiha papkasida, lokalda yoki serverda):
#   powershell -ExecutionPolicy Bypass -File deploy\tekshir_versiyalar.ps1
#
# deploy\fayllar_hash.txt: "fayl | sha256 | paket". Solishtirishda qator oxiri (CRLF/LF) va BOM
# hisobga olinmaydi - git core.autocrlf fayllarni o'zgartirsa ham "farq" chiqmaydi.
#   ESKI/FARQ - fayl bor, lekin oxirgi paketdagidan farq qiladi (eski paket qayta yoyilgan yoki qo'lda o'zgartirilgan)
#   YO'Q      - fayl umuman yo'q

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$list = Join-Path $PSScriptRoot 'fayllar_hash.txt'
if (-not (Test-Path -LiteralPath $list)) { Write-Host "deploy\fayllar_hash.txt topilmadi" -ForegroundColor Red; exit 2 }

$sha  = [System.Security.Cryptography.SHA256]::Create()
$utf8 = New-Object System.Text.UTF8Encoding($false)
$ok = 0; $bad = 0; $missing = 0

foreach ($line in [System.IO.File]::ReadAllLines($list, $utf8)) {
    if (-not $line.Trim()) { continue }
    $parts = $line -split '\s\|\s'
    if ($parts.Count -lt 3) { continue }
    $rel  = $parts[0].Trim()
    $want = $parts[1].Trim()
    $pkg  = $parts[2].Trim()
    $path = Join-Path $root $rel

    if (-not (Test-Path -LiteralPath $path)) {
        Write-Host ("YO'Q       {0,-78} <- {1}" -f $rel, $pkg) -ForegroundColor Red
        $missing++
        continue
    }
    $text = [System.IO.File]::ReadAllText($path, $utf8)
    if ($text.Length -gt 0 -and $text[0] -eq [char]0xFEFF) { $text = $text.Substring(1) }
    $text = $text.Replace("`r`n", "`n")
    $hash = -join ($sha.ComputeHash($utf8.GetBytes($text)) | ForEach-Object { $_.ToString('x2') })

    if ($hash -ne $want) {
        Write-Host ("ESKI/FARQ  {0,-78} <- {1}" -f $rel, $pkg) -ForegroundColor Yellow
        $bad++
    } else {
        $ok++
    }
}

Write-Host ""
Write-Host ("To'g'ri: {0}    Farq qiladi: {1}    Yo'q: {2}" -f $ok, $bad, $missing)
if ($bad -or $missing) {
    Write-Host "Farq qilgan fayllarni ko'rsatilgan paketdan qayta yoying yoki ro'yxatni yuboring." -ForegroundColor Yellow
    Write-Host "Eslatma: resources\js\router\index.js ni o'rnatish yo'riqnomasi bo'yicha qo'lda tahrirlagan bo'lsangiz - farq kutilgan."
    exit 1
}
exit 0
