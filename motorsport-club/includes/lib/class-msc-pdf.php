<?php
/**
 * MSC_PDF — Reliable pure-PHP A4 PDF generator.
 * No external dependencies. Uses PDF 1.4 spec.
 * Supports: multiline text, filled rectangles, JPEG/PNG images via GD.
 */
if ( ! defined('ABSPATH') ) exit;

class MSC_PDF {

    private $w    = 595.28;  // A4 width  in pts
    private $h    = 841.89;  // A4 height in pts
    private $ml   = 50;      // margins
    private $mr   = 50;
    private $mt   = 50;
    private $mb   = 50;

    private $objects  = [];
    private $n        = 0;
    private $offsets  = [];
    private $buf      = '';
    private $pages    = [];
    private $cur_page = -1;
    private $images   = [];
    private $n_img    = 0;

    // current state
    private $font_size  = 11;
    private $line_h     = 16;
    private $cur_y      = 50;
    private $text_r = 0; private $text_g = 0; private $text_b = 0;
    private $fill_r = 0; private $fill_g = 0; private $fill_b = 0;

    public function __construct() {}

    /* ── Pages ──────────────────────────────────────────────────────── */
    public function add_page() {
        $this->pages[] = '';
        $this->cur_page = count($this->pages) - 1;
        $this->cur_y = $this->mt;
    }

    private function page_out( $s ) {
        $this->pages[$this->cur_page] .= $s . "\n";
    }

    /* ── State ──────────────────────────────────────────────────────── */
    public function set_font_size( $sz, $line_h = null ) {
        $this->font_size = $sz;
        $this->line_h    = $line_h ?: $sz * 1.4;
    }

    public function set_text_color( $r, $g = -1, $b = -1 ) {
        $this->text_r = $r / 255;
        $this->text_g = $g < 0 ? $r / 255 : $g / 255;
        $this->text_b = $b < 0 ? $r / 255 : $b / 255;
    }

    public function set_fill_color( $r, $g = -1, $b = -1 ) {
        $this->fill_r = $r / 255;
        $this->fill_g = $g < 0 ? $r / 255 : $g / 255;
        $this->fill_b = $b < 0 ? $r / 255 : $b / 255;
    }

    public function get_y() { return $this->cur_y; }
    public function set_y( $y ) { $this->cur_y = $y; }

    /* ── Drawing primitives ─────────────────────────────────────────── */
    public function rect( $x, $y, $w, $h, $style = 'F' ) {
        $py = $this->h - $y - $h;
        $op = $style === 'F' ? 'f' : ( $style === 'FD' ? 'B' : 'S' );
        $r = $this->fill_r; $g = $this->fill_g; $b = $this->fill_b;
        $this->page_out( sprintf('%.3f %.3f %.3f rg %.2f %.2f %.2f %.2f re %s', $r,$g,$b, $x,$py,$w,$h, $op ) );
    }

    public function line( $x1, $y1, $x2, $y2, $lw = 0.5 ) {
        $py1 = $this->h - $y1;
        $py2 = $this->h - $y2;
        $this->page_out( sprintf('%.2f w %.3f %.3f %.3f RG %.2f %.2f m %.2f %.2f l S',
            $lw, $this->fill_r, $this->fill_g, $this->fill_b, $x1, $py1, $x2, $py2 ) );
    }

    /* ── Text ───────────────────────────────────────────────────────── */
    // Single line at absolute position
    public function text_at( $x, $y, $str, $sz = null ) {
        $sz  = $sz ?: $this->font_size;
        $py  = $this->h - $y;
        $str = $this->esc( $str );
        $r   = $this->text_r; $g = $this->text_g; $b = $this->text_b;
        $this->page_out( sprintf(
            'BT /F1 %.1f Tf %.3f %.3f %.3f rg %.2f %.2f Td (%s) Tj ET',
            $sz, $r, $g, $b, $x, $py, $str
        ) );
    }

    // Write text at current Y, auto word-wrap. Returns new Y.
    public function write( $x, $str, $max_w = null, $sz = null, $lh = null, $bold = false ) {
        $sz     = $sz ?: $this->font_size;
        $lh     = $lh ?: $this->line_h;
        $max_w  = $max_w ?: ( $this->w - $x - $this->mr );
        $font   = $bold ? '/F2' : '/F1';
        $cpl    = max(1, (int)( $max_w / ($sz * 0.52) ));

        // Split into lines first (honour \n)
        $paragraphs = explode("\n", $str);
        foreach ($paragraphs as $para) {
            $words = preg_split('/\s+/', trim($para));
            $line  = '';
            foreach ($words as $word) {
                $test = $line === '' ? $word : $line . ' ' . $word;
                if (mb_strlen($test) > $cpl && $line !== '') {
                    $py = $this->h - $this->cur_y;
                    $r  = $this->text_r; $g = $this->text_g; $b = $this->text_b;
                    $this->page_out( sprintf(
                        'BT %s %.1f Tf %.3f %.3f %.3f rg %.2f %.2f Td (%s) Tj ET',
                        $font, $sz, $r, $g, $b, $x, $py, $this->esc($line)
                    ) );
                    $this->cur_y += $lh;
                    $line = $word;
                } else {
                    $line = $test;
                }
            }
            if ($line !== '') {
                $py = $this->h - $this->cur_y;
                $r  = $this->text_r; $g = $this->text_g; $b = $this->text_b;
                $this->page_out( sprintf(
                    'BT %s %.1f Tf %.3f %.3f %.3f rg %.2f %.2f Td (%s) Tj ET',
                    $font, $sz, $r, $g, $b, $x, $py, $this->esc($line)
                ) );
                $this->cur_y += $lh;
            }
            if (count($paragraphs) > 1) $this->cur_y += $lh * 0.3;
        }
        return $this->cur_y;
    }

    /* ── Images ─────────────────────────────────────────────────────── */
    public function image_from_file( $path, $x, $y, $w, $h ) {
        if (!function_exists('imagecreatefromstring')) return;
        $data = @file_get_contents($path);
        if (!$data) return;
        $this->embed_image_data($data, $x, $y, $w, $h);
    }

    public function image_from_dataurl( $dataurl, $x, $y, $w, $h ) {
        if (strpos($dataurl, 'data:image/') !== 0) return false;
        $b64  = substr($dataurl, strpos($dataurl, ',') + 1);
        $data = base64_decode($b64);
        if (!$data) return false;
        $this->embed_image_data($data, $x, $y, $w, $h);
        return true;
    }

    private function embed_image_data( $data, $x, $y, $w, $h ) {
        if (!function_exists('imagecreatefromstring')) return;
        $im = @imagecreatefromstring($data);
        if (!$im) return;
        $iw = imagesx($im); $ih = imagesy($im);
        ob_start();
        imagejpeg($im, null, 92);
        $jpeg = ob_get_clean();
        imagedestroy($im);

        $this->n_img++;
        $key = 'Im' . $this->n_img;
        $this->images[$key] = ['data' => $jpeg, 'w' => $iw, 'h' => $ih];

        $py = $this->h - $y - $h;
        $this->page_out( sprintf(
            'q %.2f 0 0 %.2f %.2f %.2f cm /%s Do Q', $w, $h, $x, $py, $key
        ) );
    }

    /* ── Typed signature (cursive-style text) ──────────────────────── */
    public function typed_signature( $x, $y, $name, $w, $h ) {
        // Draw border box
        $this->set_fill_color(200,200,200);
        $this->rect($x, $y, $w, 0.5, 'F');
        // Write name in italic style (Helvetica-Oblique = F3)
        $py  = $this->h - $y - $h + 6;
        $str = $this->esc($name);
        $this->page_out( sprintf(
            'BT /F3 18 Tf 0.2 0.2 0.5 rg %.2f %.2f Td (%s) Tj ET',
            $x, $py, $str
        ) );
    }

    /* ── Helpers ───────────────────────────────────────────────────── */
    private function esc( $s ) {
        $s = iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', (string)$s);
        return strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
    }

    private function check_page_break( $needed = 20 ) {
        if ($this->cur_y + $needed > $this->h - $this->mb) {
            $this->add_page();
        }
    }

    /* ── PDF output ─────────────────────────────────────────────────── */
    public function output_string() {
        $out = '%PDF-1.4' . "\n";
        $objs = []; // id => content

        // --- Object 1: Catalog (placeholder, filled after we know pages obj id)
        // --- Object 2: Pages  (placeholder)
        // We'll build in order: images, fonts, page streams, page dicts, pages, catalog

        $oid = 2; // start after catalog(1) and pages(2)

        // Image XObjects
        $img_oids = [];
        foreach ($this->images as $key => $img) {
            $oid++;
            $img_oids[$key] = $oid;
            $len = strlen($img['data']);
            $objs[$oid] = $oid . " 0 obj\n<<\n/Type /XObject\n/Subtype /Image\n"
                . "/Width {$img['w']}\n/Height {$img['h']}\n"
                . "/ColorSpace /DeviceRGB\n/BitsPerComponent 8\n"
                . "/Filter /DCTDecode\n/Length $len\n>>\nstream\n"
                . $img['data'] . "\nendstream\nendobj";
        }

        // Fonts
        $oid++; $f1_oid = $oid;
        $objs[$f1_oid] = "$f1_oid 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>\nendobj";
        $oid++; $f2_oid = $oid;
        $objs[$f2_oid] = "$f2_oid 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>\nendobj";
        $oid++; $f3_oid = $oid;
        $objs[$f3_oid] = "$f3_oid 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Oblique /Encoding /WinAnsiEncoding >>\nendobj";

        // Page streams + page dicts
        $page_oids  = [];
        $xobj_str   = '';
        foreach ($img_oids as $k => $v) { $xobj_str .= "/$k $v 0 R "; }
        $res = "<< /Font << /F1 $f1_oid 0 R /F2 $f2_oid 0 R /F3 $f3_oid 0 R >> /XObject << $xobj_str>> >>";

        foreach ($this->pages as $pi => $pcontent) {
            // Stream object
            $oid++; $stream_oid = $oid;
            $len = strlen($pcontent);
            $objs[$stream_oid] = "$stream_oid 0 obj\n<< /Length $len >>\nstream\n$pcontent\nendstream\nendobj";
            // Page dict object
            $oid++; $page_oid = $oid;
            $page_oids[] = $page_oid;
            $mbox = "0 0 {$this->w} {$this->h}";
            $objs[$page_oid] = "$page_oid 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [$mbox] /Contents $stream_oid 0 R /Resources $res >>\nendobj";
        }

        // Pages dict (obj 2)
        $kids = implode(' 0 R ', $page_oids) . ' 0 R';
        $cnt  = count($page_oids);
        $objs[2] = "2 0 obj\n<< /Type /Pages /Kids [$kids] /Count $cnt >>\nendobj";

        // Catalog (obj 1)
        $objs[1] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj";

        // Assemble in order 1..n
        ksort($objs);
        $offsets = [];
        foreach ($objs as $id => $content) {
            $offsets[$id] = strlen($out);
            $out .= $content . "\n";
        }

        // xref
        $xref_pos = strlen($out);
        $total    = max(array_keys($objs)) + 1;
        $out .= "xref\n0 $total\n";
        $out .= "0000000000 65535 f \n";
        for ($i = 1; $i < $total; $i++) {
            $out .= str_pad(isset($offsets[$i]) ? $offsets[$i] : 0, 10, '0', STR_PAD_LEFT) . " 00000 n \n";
        }
        $out .= "trailer\n<< /Size $total /Root 1 0 R >>\n";
        $out .= "startxref\n$xref_pos\n%%EOF";

        return $out;
    }
}
