# LIMS: loyiha fayllari eng oxirgi paket versiyasidami - tekshirish. Hech narsani o'zgartirmaydi.
#
# Ishlatish (loyiha papkasida, lokalda yoki serverda):
#   powershell -ExecutionPolicy Bypass -File deploy\tekshir_versiyalar.ps1
#
# deploy\fayllar_hash.txt: "fayl | sha256[,sha256] | paket [| belgi]". Solishtirishda qator oxiri
# (CRLF/LF) va BOM hisobga olinmaydi - git core.autocrlf fayllarni o'zgartirsa ham "farq" chiqmaydi.
#   ESKI/FARQ  - fayl bor, lekin oxirgi paketdagidan farq qiladi (eski paket qayta yoyilgan yoki qo'lda o'zgartirilgan)
#   YO'Q       - fayl umuman yo'q
#   MAHALLIY   - siz lokalda o'zgartirgan fayl (belgi "mahalliy"): farq qilishi kutilgan, xato emas
#   belgi "ixtiyoriy" - fayl bo'lmasa ham xato emas
# Qo'shimcha: public\build (interfeys) Paket 33 bilan yig'ilganmi.
# Chiqish kodi: 0 - hammasi joyida; 1 - farq yoki yo'q fayl bor.

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$list = Join-Path $PSScriptRoot 'fayllar_hash.txt'
if (-not (Test-Path -LiteralPath $list)) { Write-Host "deploy\fayllar_hash.txt topilmadi" -ForegroundColor Red; exit 2 }

$sha  = [System.Security.Cryptography.SHA256]::Create()
$utf8 = New-Object System.Text.UTF8Encoding($false)
$ok = 0; $bad = 0; $missing = 0; $local = 0

foreach ($line in [System.IO.File]::ReadAllLines($list, $utf8)) {
    if (-not $line.Trim()) { continue }
    $parts = $line -split '\s\|\s'
    if ($parts.Count -lt 3) { continue }
    $rel  = $parts[0].Trim()
    $want = @($parts[1].Trim() -split ',' | ForEach-Object { $_.Trim() })
    $pkg  = $parts[2].Trim()
    $flag = ''
    if ($parts.Count -ge 4) { $flag = $parts[3].Trim() }
    $path = Join-Path $root $rel

    if (-not (Test-Path -LiteralPath $path)) {
        if ($flag -eq 'ixtiyoriy') { $ok++; continue }
        Write-Host ("YO'Q       {0,-78} <- {1}" -f $rel, $pkg) -ForegroundColor Red
        $missing++
        continue
    }
    $text = [System.IO.File]::ReadAllText($path, $utf8)
    if ($text.Length -gt 0 -and $text[0] -eq [char]0xFEFF) { $text = $text.Substring(1) }
    $text = $text.Replace("`r`n", "`n")
    $hash = -join ($sha.ComputeHash($utf8.GetBytes($text)) | ForEach-Object { $_.ToString('x2') })

    if ($want -contains $hash) {
        $ok++
    } elseif ($flag -eq 'mahalliy') {
        Write-Host ("MAHALLIY   {0,-78} (siz o'zgartirgan - kutilgan)" -f $rel) -ForegroundColor DarkGray
        $local++
    } else {
        Write-Host ("ESKI/FARQ  {0,-78} <- {1}" -f $rel, $pkg) -ForegroundColor Yellow
        $bad++
    }
}

# Interfeys (public\build) - Paket 33 dagi "Shablonlarim" bo'lishi kerak
$build = 'yo''q'
$assets = Join-Path $root 'public\build\assets'
if (Test-Path -LiteralPath $assets) {
    $hit = Get-ChildItem -LiteralPath $assets -Filter *.js | Select-String -SimpleMatch -Pattern 'portal/request-templates' -List | Select-Object -First 1
    if ($hit) { $build = 'ok' } else { $build = 'eski' }
}
Write-Host ""
if ($build -eq 'ok') {
    Write-Host "public\build: Paket 33 bilan yig'ilgan" -ForegroundColor Green
} else {
    Write-Host ("public\build: {0} - lokalda npm run build, git add -f public/build, commit, push" -f $build) -ForegroundColor Red
    $bad++
}

Write-Host ""
Write-Host ("To'g'ri: {0}    Farq qiladi: {1}    Yo'q: {2}    Mahalliy (kutilgan): {3}" -f $ok, $bad, $missing, $local)
if ($bad -or $missing) {
    Write-Host "Farq qilgan / yo'q fayllarni to'liq to'plamdan (paket33_shablon_qabul - P31, P32 va P33 hammasi ichida) qayta yoying va ro'yxatni yuboring." -ForegroundColor Yellow
    exit 1
}
Write-Host "Hammasi joyida." -ForegroundColor Green
exit 0
