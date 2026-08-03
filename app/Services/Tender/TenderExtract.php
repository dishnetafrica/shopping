<?php

namespace App\Services\Tender;

/**
 * v4.19 TenderBot — turn tender attachments into plain text for the analyzer.
 *
 * Zero hard dependencies: DOCX and XLSX are read as the zip-of-XML they really are
 * (ZipArchive + tag stripping), CSV/TXT pass through, ZIPs recurse one level.
 * PDF text uses smalot/pdfparser WHEN INSTALLED (optional composer line in the
 * Dockerfile); without it the file is reported as unparsed — the analyzer states
 * that honestly instead of guessing ("never invent information").
 */
final class TenderExtract
{
    private const MAX_PER_FILE = 60000;    // chars of text kept per attachment
    private const MAX_BYTES = 25 * 1024 * 1024;

    /**
     * @param string $name original filename (extension decides the parser)
     * @param string $bytes raw file contents
     * @return array{text:string, parsed:bool, note:string}
     */
    public function extract(string $name, string $bytes, int $depth = 0): array
    {
        try {
            if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
                return ['text' => '', 'parsed' => false, 'note' => 'empty or larger than 25MB'];
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

            return match ($ext) {
                'pdf' => $this->pdf($bytes),
                'docx' => $this->docx($bytes),
                'xlsx', 'xlsm' => $this->xlsx($bytes),
                'zip' => $depth === 0 ? $this->zip($bytes) : ['text' => '', 'parsed' => false, 'note' => 'nested zip skipped'],
                'csv', 'txt', 'md' => ['text' => $this->clip($bytes), 'parsed' => true, 'note' => ''],
                'doc', 'xls' => ['text' => $this->legacyOffice($bytes), 'parsed' => true, 'note' => 'legacy format — rough text only'],
                default => ['text' => '', 'parsed' => false, 'note' => 'unsupported type .' . $ext],
            };
        } catch (\Throwable $e) {
            \Log::warning('TenderExtract failed for ' . $name . ': ' . $e->getMessage());

            return ['text' => '', 'parsed' => false, 'note' => 'could not read this file'];
        }
    }

    /**
     * PDF text, best reader first.
     *
     * 1. `pdftotext -layout` (poppler-utils, installed in the runtime image since v4.22). It keeps
     *    columns aligned, which is what makes a BOQ table survive as readable rows — smalot flattens
     *    them into one run-on line.
     * 2. smalot/pdfparser when poppler is missing (optional composer package).
     * 3. Neither found any text → the file is a SCAN. Flag `ocr` so the analyzer can render the pages
     *    and have the vision model read them; the extractor itself stays AI-free and testable.
     */
    private function pdf(string $bytes): array
    {
        $text = $this->meaningful($this->pdfToText($bytes));

        if ($text === '' && class_exists(\Smalot\PdfParser\Parser::class)) {
            try {
                $text = $this->meaningful((string) (new \Smalot\PdfParser\Parser())->parseContent($bytes)->getText());
            } catch (\Throwable $e) {
                $text = '';
            }
        }

        if ($text !== '') {
            return ['text' => $this->clip($text), 'parsed' => true, 'note' => ''];
        }

        return ['text' => '', 'parsed' => false, 'ocr' => true,
            'note' => 'scanned PDF — no text layer'];
    }

    /** poppler's pdftotext over a temp file; '' when the binary is absent or the PDF has no text. */
    private function pdfToText(string $bytes): string
    {
        $bin = $this->popplerBin('pdftotext');
        if ($bin === null) {
            return '';
        }
        $in = tempnam(sys_get_temp_dir(), 'tb') . '.pdf';
        $out = $in . '.txt';
        try {
            file_put_contents($in, $bytes);
            @exec(escapeshellcmd($bin) . ' -layout -q -enc UTF-8 ' . escapeshellarg($in) . ' ' . escapeshellarg($out) . ' 2>/dev/null');

            return is_file($out) ? (string) file_get_contents($out) : '';
        } catch (\Throwable $e) {
            return '';
        } finally {
            @unlink($in);
            @unlink($out);
        }
    }

    /**
     * Is there REAL text here, or only page furniture?
     *
     * pdftotext emits a form feed (\f) for every page, so a fully scanned document comes back as
     * "\f\f\f" — and `trim()` does NOT strip \f. Without this, every scanned tender would look
     * successfully read, the OCR fallback would never fire, and the analysis would run blind on a
     * notice title alone. Found by the v4.22 harness on a real scanned fixture.
     */
    private function meaningful(string $text): string
    {
        $clean = str_replace("\f", "\n", $text);
        $clean = (string) preg_replace('/[\x00-\x08\x0B\x0E-\x1F]/u', '', $clean);
        $clean = trim((string) preg_replace('/\n{3,}/', "\n\n", $clean));

        // A handful of stray characters is not a document — treat it as no text layer.
        return mb_strlen(preg_replace('/\s+/', '', $clean) ?? '') >= 20 ? $clean : '';
    }

    /** Absolute path to a poppler binary, or null when it is not installed. */
    public function popplerBin(string $name): ?string
    {
        foreach (['/usr/bin/', '/usr/local/bin/', '/opt/homebrew/bin/'] as $dir) {
            if (is_file($dir . $name) && is_executable($dir . $name)) {
                return $dir . $name;
            }
        }

        return null;
    }

    private function docx(string $bytes): array
    {
        $xml = $this->zipEntry($bytes, 'word/document.xml');
        if ($xml === null) {
            return ['text' => '', 'parsed' => false, 'note' => 'not a valid Word file'];
        }
        // paragraph/row CLOSING tags become newlines (mangling opening tags feeds strip_tags
        // an unterminated "<w:p <w:r>" and it silently eats the whole document)
        $xml = str_replace(['</w:p>', '</w:tr>'], "\n", $xml);
        $xml = preg_replace('~<w:tab[^>]*/>~', "\t", $xml);
        $text = trim(html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8'));

        return ['text' => $this->clip($text), 'parsed' => $text !== '', 'note' => $text === '' ? 'empty document' : ''];
    }

    private function xlsx(string $bytes): array
    {
        $zip = $this->openZip($bytes);
        if (! $zip) {
            return ['text' => '', 'parsed' => false, 'note' => 'not a valid Excel file'];
        }
        // shared strings first (most cell text lives here)
        $shared = [];
        $ss = $zip->getFromName('xl/sharedStrings.xml');
        if ($ss !== false && preg_match_all('~<si>(.*?)</si>~s', $ss, $mm)) {
            foreach ($mm[1] as $si) {
                $shared[] = trim(html_entity_decode(strip_tags($si), ENT_QUOTES | ENT_XML1, 'UTF-8'));
            }
        }
        $out = [];
        for ($i = 0; $i < $zip->numFiles && count($out) < 40000; $i++) {
            $n = (string) $zip->statIndex($i)['name'];
            if (! preg_match('~^xl/worksheets/sheet\d+\.xml$~', $n)) {
                continue;
            }
            $sheet = $zip->getFromIndex($i);
            if ($sheet === false) {
                continue;
            }
            $out[] = '--- sheet ' . basename($n) . ' ---';
            if (preg_match_all('~<row[^>]*>(.*?)</row>~s', $sheet, $rows)) {
                foreach ($rows[1] as $row) {
                    $cells = [];
                    if (preg_match_all('~<c([^>]*)>.*?<v>(.*?)</v>~s', $row, $cm, PREG_SET_ORDER)) {
                        foreach ($cm as $c) {
                            $v = trim(html_entity_decode($c[2], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                            $cells[] = (str_contains($c[1], 't="s"') && isset($shared[(int) $v])) ? $shared[(int) $v] : $v;
                        }
                    }
                    if ($cells) {
                        $out[] = implode(' | ', $cells);
                    }
                }
            }
        }
        $zip->close();
        $text = trim(implode("\n", $out));

        return ['text' => $this->clip($text), 'parsed' => $text !== '', 'note' => $text === '' ? 'no readable cells' : ''];
    }

    private function zip(string $bytes): array
    {
        $zip = $this->openZip($bytes);
        if (! $zip) {
            return ['text' => '', 'parsed' => false, 'note' => 'not a valid zip'];
        }
        $parts = [];
        $notes = [];
        for ($i = 0; $i < min($zip->numFiles, 25); $i++) {
            $n = (string) $zip->statIndex($i)['name'];
            $base = basename(str_replace('\\', '/', $n));
            if ($base === '' || $base[0] === '.' || str_contains($n, '__MACOSX') || str_ends_with($n, '/')) {
                continue;
            }
            $inner = $zip->getFromIndex($i);
            if ($inner === false) {
                continue;
            }
            $r = $this->extract($base, $inner, 1);
            if ($r['text'] !== '') {
                $parts[] = "===== {$base} =====\n" . $r['text'];
            } elseif ($r['note'] !== '') {
                $notes[] = "{$base}: {$r['note']}";
            }
        }
        $zip->close();
        $text = trim(implode("\n\n", $parts));

        return ['text' => $this->clip($text), 'parsed' => $text !== '',
            'note' => $notes ? ('inside zip — ' . implode('; ', array_slice($notes, 0, 5))) : ''];
    }

    /** Old binary .doc/.xls: salvage readable ASCII/UTF-8 runs (imperfect but honest). */
    private function legacyOffice(string $bytes): string
    {
        preg_match_all('/[\x20-\x7E\xC2-\xF4][\x20-\x7E\x80-\xBF]{5,}/', $bytes, $m);
        $text = implode("\n", array_filter(array_map('trim', $m[0] ?? []), fn ($s) => strlen($s) > 8));

        return $this->clip($text);
    }

    private function zipEntry(string $bytes, string $entry): ?string
    {
        $zip = $this->openZip($bytes);
        if (! $zip) {
            return null;
        }
        $data = $zip->getFromName($entry);
        $zip->close();

        return $data === false ? null : $data;
    }

    private function openZip(string $bytes): ?\ZipArchive
    {
        $tmp = tempnam(sys_get_temp_dir(), 'tdx');
        file_put_contents($tmp, $bytes);
        $zip = new \ZipArchive();
        if ($zip->open($tmp) !== true) {
            @unlink($tmp);

            return null;
        }
        register_shutdown_function(fn () => @unlink($tmp));

        return $zip;
    }

    private function clip(string $s): string
    {
        $s = preg_replace('/[ \t]{3,}/', '  ', preg_replace('/\n{3,}/', "\n\n", $s));

        return mb_strlen($s, 'UTF-8') > self::MAX_PER_FILE
            ? mb_substr($s, 0, self::MAX_PER_FILE, 'UTF-8') . "\n…[truncated]"
            : $s;
    }
}
