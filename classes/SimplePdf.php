<?php
declare(strict_types=1);

/**
 * Dependency-free PDF writer (A4, Helvetica, JPEG images, lines, rectangles, tables).
 * Enough for receipts and tabular report exports without shipping a PDF library.
 * Text is WinAnsi encoded; the rupee sign is written as "Rs." because core PDF fonts lack it.
 */
final class SimplePdf
{
    public const W = 595.28;
    public const H = 841.89;

    private array $pages = [];
    private string $buf = '';
    private array $images = [];
    private string $font = 'F1';
    private float $size = 10;
    private array $color = [0, 0, 0];
    public float $y = 0;
    public float $margin = 36;
    private ?Closure $onNewPage = null;

    private const HELV = [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584];
    private const HELVB = [278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584];

    public function __construct(public string $title = 'Document')
    {
        $this->addPage();
    }

    public function onNewPage(callable $fn): void
    {
        $this->onNewPage = Closure::fromCallable($fn);
    }

    public function addPage(): void
    {
        if ($this->pages || $this->buf !== '') {
            $this->pages[] = $this->buf;
        }
        $this->buf = '';
        $this->y = $this->margin;
        if ($this->onNewPage) {
            ($this->onNewPage)($this);
        }
    }

    public function ensure(float $height): void
    {
        if ($this->y + $height > self::H - $this->margin) {
            $this->addPage();
        }
    }

    public function font(bool $bold = false, float $size = 10): self
    {
        $this->font = $bold ? 'F2' : 'F1';
        $this->size = $size;
        return $this;
    }

    public function color(int $r, int $g, int $b): self
    {
        $this->color = [$r, $g, $b];
        return $this;
    }

    private static function enc(string $s): string
    {
        $s = str_replace(['₹', '–', '—', '•', '’', '‘', '“', '”'], ['Rs.', '-', '-', '-', "'", "'", '"', '"'], $s);
        $c = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s);
        return $c === false ? preg_replace('/[^\x20-\x7E]/', '?', $s) : $c;
    }

    public function width(string $s, ?float $size = null, ?bool $bold = null): float
    {
        $tbl = ($bold ?? $this->font === 'F2') ? self::HELVB : self::HELV;
        $w = 0;
        foreach (str_split(self::enc($s)) as $ch) {
            $o = ord($ch);
            $w += ($o >= 32 && $o <= 126) ? $tbl[$o - 32] : 556;
        }
        return $w * ($size ?? $this->size) / 1000;
    }

    private function rgb(array $c, string $op): string
    {
        return sprintf('%.3F %.3F %.3F %s', $c[0] / 255, $c[1] / 255, $c[2] / 255, $op);
    }

    /** Draws text with its top-left at (x, y) in top-down coordinates. align: L, C, R */
    public function text(float $x, float $y, string $s, string $align = 'L', ?float $boxW = null): void
    {
        $tw = $this->width($s);
        if ($align === 'R') {
            $x = ($boxW !== null ? $x + $boxW : $x) - $tw;
        } elseif ($align === 'C') {
            $x = ($boxW !== null ? $x + $boxW / 2 : $x) - $tw / 2;
        }
        $esc = strtr(self::enc($s), ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ']);
        $baseline = self::H - $y - $this->size * 0.8;
        $this->buf .= 'BT ' . $this->rgb($this->color, 'rg') . sprintf(' /%s %.2F Tf %.2F %.2F Td (%s) Tj ET', $this->font, $this->size, $x, $baseline, $esc) . "\n";
    }

    /** Word-wraps text into a box, returns the height used. */
    public function para(float $x, float $y, float $w, string $s, float $lineH = 1.35): float
    {
        $lines = [];
        foreach (preg_split('/\R/', $s) ?: [] as $p) {
            $cur = '';
            foreach (preg_split('/\s+/', $p) ?: [] as $word) {
                $try = $cur === '' ? $word : "$cur $word";
                if ($this->width($try) > $w && $cur !== '') {
                    $lines[] = $cur;
                    $cur = $word;
                } else {
                    $cur = $try;
                }
            }
            $lines[] = $cur;
        }
        foreach ($lines as $i => $l) {
            $this->text($x, $y + $i * $this->size * $lineH, $l);
        }
        return count($lines) * $this->size * $lineH;
    }

    public function line(float $x1, float $y1, float $x2, float $y2, array $rgb = [200, 200, 200], float $w = 0.6): void
    {
        $this->buf .= $this->rgb($rgb, 'RG') . sprintf(' %.2F w %.2F %.2F m %.2F %.2F l S', $w, $x1, self::H - $y1, $x2, self::H - $y2) . "\n";
    }

    public function rect(float $x, float $y, float $w, float $h, array $fill = [240, 240, 240], ?array $stroke = null): void
    {
        $op = $stroke ? 'B' : 'f';
        $this->buf .= $this->rgb($fill, 'rg') . ($stroke ? ' ' . $this->rgb($stroke, 'RG') . ' 0.6 w' : '') .
            sprintf(' %.2F %.2F %.2F %.2F re %s', $x, self::H - $y - $h, $w, $h, $op) . "\n";
    }

    /** Draws a JPEG/PNG (PNG is flattened onto white and converted to JPEG). */
    public function image(string $bytes, float $x, float $y, float $w, ?float $h = null): void
    {
        $info = @getimagesizefromstring($bytes);
        if (!$info) {
            return;
        }
        if ($info[2] !== IMAGETYPE_JPEG) {
            $src = @imagecreatefromstring($bytes);
            if (!$src) {
                return;
            }
            $dst = imagecreatetruecolor(imagesx($src), imagesy($src));
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
            imagecopy($dst, $src, 0, 0, 0, 0, imagesx($src), imagesy($src));
            ob_start();
            imagejpeg($dst, null, 88);
            $bytes = (string) ob_get_clean();
            $info = getimagesizefromstring($bytes);
        }
        $h ??= $w * $info[1] / $info[0];
        $name = 'I' . (count($this->images) + 1);
        $this->images[$name] = ['data' => $bytes, 'w' => $info[0], 'h' => $info[1], 'gray' => ($info['channels'] ?? 3) === 1];
        $this->buf .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n", $w, $h, $x, self::H - $y - $h, $name);
    }

    /**
     * Renders a table with automatic page breaks.
     * $cols: [['label'=>'Name','w'=>120,'align'=>'L'], ...]
     */
    public function table(array $cols, array $rows, float $rowH = 18, float $fontSize = 8.5): void
    {
        $x0 = $this->margin;
        $header = function () use ($cols, $x0, $rowH, $fontSize) {
            $tw = array_sum(array_column($cols, 'w'));
            $this->rect($x0, $this->y, $tw, $rowH, [36, 55, 120]);
            $this->font(true, $fontSize)->color(255, 255, 255);
            $x = $x0;
            foreach ($cols as $c) {
                $this->text($x + 4, $this->y + ($rowH - $fontSize) / 2, $c['label'], $c['align'] ?? 'L', $c['w'] - 8);
                $x += $c['w'];
            }
            $this->y += $rowH;
        };
        $header();
        $this->font(false, $fontSize)->color(30, 30, 30);
        foreach ($rows as $i => $r) {
            if ($this->y + $rowH > self::H - $this->margin) {
                $this->addPage();
                $header();
                $this->font(false, $fontSize)->color(30, 30, 30);
            }
            if ($i % 2) {
                $this->rect($x0, $this->y, array_sum(array_column($cols, 'w')), $rowH, [246, 247, 251]);
            }
            $x = $x0;
            foreach (array_values($cols) as $ci => $c) {
                $val = (string) ($r[$ci] ?? '');
                while ($val !== '' && $this->width($val) > $c['w'] - 8) {
                    $val = mb_substr($val, 0, -2) . '…';
                    if (mb_strlen($val) < 3) {
                        break;
                    }
                }
                $this->text($x + 4, $this->y + ($rowH - $fontSize) / 2, $val, $c['align'] ?? 'L', $c['w'] - 8);
                $x += $c['w'];
            }
            $this->y += $rowH;
        }
    }

    public function output(): string
    {
        $pages = $this->pages;
        $pages[] = $this->buf;
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $n = 5;
        $xobj = [];
        foreach ($this->images as $name => $img) {
            $objs[$n] = ['dict' => sprintf('<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /%s /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>', $img['w'], $img['h'], $img['gray'] ? 'DeviceGray' : 'DeviceRGB', strlen($img['data'])), 'stream' => $img['data']];
            $xobj[] = "/$name $n 0 R";
            $n++;
        }
        $res = '<< /Font << /F1 3 0 R /F2 4 0 R >> /XObject << ' . implode(' ', $xobj) . ' >> >>';
        $kids = [];
        foreach ($pages as $content) {
            $c = gzcompress($content);
            $objs[$n] = ['dict' => '<< /Length ' . strlen($c) . ' /Filter /FlateDecode >>', 'stream' => $c];
            $objs[$n + 1] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources %s /Contents %d 0 R >>', self::W, self::H, $res, $n);
            $kids[] = ($n + 1) . ' 0 R';
            $n += 2;
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        $objs[$n] = '<< /Title (' . strtr(self::enc($this->title), ['(' => '\\(', ')' => '\\)']) . ') /Producer (ClassNex) /CreationDate (D:' . date('YmdHis') . ') >>';
        $infoId = $n;
        ksort($objs);
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $id => $o) {
            $offsets[$id] = strlen($out);
            if (is_array($o)) {
                $out .= "$id 0 obj\n{$o['dict']}\nstream\n{$o['stream']}\nendstream\nendobj\n";
            } else {
                $out .= "$id 0 obj\n$o\nendobj\n";
            }
        }
        $xref = strlen($out);
        $max = max(array_keys($objs));
        $out .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $out .= isset($offsets[$i]) ? sprintf("%010d 00000 n \n", $offsets[$i]) : "0000000000 65535 f \n";
        }
        $out .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R /Info $infoId 0 R >>\nstartxref\n$xref\n%%EOF";
        return $out;
    }
}
