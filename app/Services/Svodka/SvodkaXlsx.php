<?php

namespace App\Services\Svodka;

use RuntimeException;
use ZipArchive;

/**
 * Svodka uchun .xlsx yozuvchi: birlashtirilgan kataklar, chegaralar, qalin sarlavhalar, ustun kengligi.
 * Qo'shimcha kutubxonasiz (faqat PHP «zip» kengaytmasi). [Paket 29]
 *
 *   $x = new SvodkaXlsx();
 *   $s = $x->sheet('26.10.26');
 *   $x->put($s, 2, 1, 'Tartib raqami', 'head'); $x->merge($s, 2, 1, 3, 1);
 *   $path = $x->save();
 */
class SvodkaXlsx
{
    /** uslub nomi => cellXfs indeksi */
    private const STYLES = ['plain' => 0, 'title' => 1, 'head' => 2, 'text' => 3, 'num' => 4, 'code' => 5, 'note' => 6, 'bold' => 7, 'numBold' => 8];

    private array $sheets = [];

    public static function available(): bool
    {
        return class_exists(ZipArchive::class);
    }

    public function sheet(string $name): int
    {
        $name = mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/u', ' ', $name), 0, 31) ?: 'Sheet' . (count($this->sheets) + 1);
        $base = $name;
        $n = 2;
        while (in_array($name, array_column($this->sheets, 'name'), true)) {
            $name = mb_substr($base, 0, 28) . ' ' . $n++;
        }
        $this->sheets[] = ['name' => $name, 'cells' => [], 'merges' => [], 'widths' => [], 'heights' => []];

        return count($this->sheets) - 1;
    }

    /** Qator va ustun 1 dan boshlanadi */
    public function put(int $sheet, int $row, int $col, $value, string $style = 'text'): void
    {
        $this->sheets[$sheet]['cells'][$row][$col] = [$value, self::STYLES[$style] ?? 0];
    }

    /** Birlashtirish; ichidagi bo'sh kataklarga ham chegara (aks holda chiziq ko'rinmaydi) */
    public function merge(int $sheet, int $r1, int $c1, int $r2, int $c2): void
    {
        if ($r1 === $r2 && $c1 === $c2) {
            return;
        }
        $style = $this->sheets[$sheet]['cells'][$r1][$c1][1] ?? 0;
        for ($r = $r1; $r <= $r2; $r++) {
            for ($c = $c1; $c <= $c2; $c++) {
                if (! isset($this->sheets[$sheet]['cells'][$r][$c])) {
                    $this->sheets[$sheet]['cells'][$r][$c] = [null, $style];
                }
            }
        }
        $this->sheets[$sheet]['merges'][] = self::ref($r1, $c1) . ':' . self::ref($r2, $c2);
    }

    public function width(int $sheet, int $col, float $w): void
    {
        $this->sheets[$sheet]['widths'][$col] = $w;
    }

    public function height(int $sheet, int $row, float $h): void
    {
        $this->sheets[$sheet]['heights'][$row] = $h;
    }

    public function save(?string $path = null): string
    {
        if (! self::available()) {
            throw new RuntimeException('PHP zip kengaytmasi yoqilmagan');
        }
        if (! $this->sheets) {
            $this->sheet('Svodka');
        }
        $path ??= tempnam(sys_get_temp_dir(), 'svx');
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Faylni yaratib bo'lmadi");
        }
        $n = count($this->sheets);
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        for ($i = 1; $i <= $n; $i++) {
            $ct .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $zip->addFromString('[Content_Types].xml', $ct . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');

        $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($this->sheets as $i => $s) {
            $id = $i + 1;
            $wb .= '<sheet name="' . self::esc($s['name']) . '" sheetId="' . $id . '" r:id="rId' . $id . '"/>';
            $rels .= '<Relationship Id="rId' . $id . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $id . '.xml"/>';
        }
        $rels .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $zip->addFromString('xl/workbook.xml', $wb . '</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', $rels . '</Relationships>');
        $zip->addFromString('xl/styles.xml', self::styles());
        foreach ($this->sheets as $i => $s) {
            $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $this->sheetXml($s));
        }
        $zip->close();

        return $path;
    }

    private static function styles(): string
    {
        $thin = '<left style="thin"><color rgb="FF808080"/></left><right style="thin"><color rgb="FF808080"/></right>'
            . '<top style="thin"><color rgb="FF808080"/></top><bottom style="thin"><color rgb="FF808080"/></bottom><diagonal/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="4">'
            . '<font><sz val="11"/><name val="Times New Roman"/></font>'
            . '<font><b/><sz val="11"/><name val="Times New Roman"/></font>'
            . '<font><b/><sz val="13"/><name val="Times New Roman"/></font>'
            . '<font><i/><sz val="10"/><color rgb="FF7F7F7F"/><name val="Times New Roman"/></font>'
            . '</fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFF2F2F2"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border>' . $thin . '</border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="9">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'                                                            // plain
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"><alignment vertical="center"/></xf>'               // title
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
            . '<alignment horizontal="center" vertical="center" wrapText="1"/></xf>'                                                       // head
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>' // text
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' // num
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' // code
            . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'                                               // note
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>' // bold
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' // numBold
            . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }

    private function sheetXml(array $s): string
    {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'   // chop etishda — bitta varaq kengligiga
            . '<sheetViews><sheetView workbookViewId="0"/></sheetViews><sheetFormatPr defaultRowHeight="15"/>';
        if ($s['widths']) {
            ksort($s['widths']);
            $x .= '<cols>';
            foreach ($s['widths'] as $c => $w) {
                $x .= '<col min="' . $c . '" max="' . $c . '" width="' . (float) $w . '" customWidth="1"/>';
            }
            $x .= '</cols>';
        }
        $x .= '<sheetData>';
        $rows = $s['cells'];
        ksort($rows);
        foreach ($rows as $r => $cols) {
            ksort($cols);
            $h = $s['heights'][$r] ?? null;
            $x .= '<row r="' . $r . '"' . ($h ? ' ht="' . (float) $h . '" customHeight="1"' : '') . '>';
            foreach ($cols as $c => [$v, $st]) {
                $ref = self::ref($r, $c);
                $sAttr = $st ? ' s="' . $st . '"' : '';
                if ($v === null || $v === '') {
                    $x .= '<c r="' . $ref . '"' . $sAttr . '/>';
                } elseif (is_int($v) || is_float($v)) {
                    $x .= '<c r="' . $ref . '"' . $sAttr . '><v>' . self::num($v) . '</v></c>';
                } else {
                    $x .= '<c r="' . $ref . '"' . $sAttr . ' t="inlineStr"><is><t xml:space="preserve">' . self::esc((string) $v) . '</t></is></c>';
                }
            }
            $x .= '</row>';
        }
        $x .= '</sheetData>';
        if ($s['merges']) {
            $x .= '<mergeCells count="' . count($s['merges']) . '">';
            foreach ($s['merges'] as $m) {
                $x .= '<mergeCell ref="' . $m . '"/>';
            }
            $x .= '</mergeCells>';
        }
        $x .= '<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>'
            . '<pageSetup orientation="landscape" paperSize="9" fitToWidth="1" fitToHeight="0"/>';

        return $x . '</worksheet>';
    }

    private static function num($v): string
    {
        if (is_int($v)) {
            return (string) $v;
        }
        $s = rtrim(rtrim(number_format((float) $v, 6, '.', ''), '0'), '.');

        return $s === '-0' ? '0' : $s;
    }

    public static function ref(int $row, int $col): string
    {
        $s = '';
        for ($i = $col; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + (($i - 1) % 26)) . $s;
        }

        return $s . $row;
    }

    private static function esc(string $v): string
    {
        $v = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $v) ?? '';

        return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
