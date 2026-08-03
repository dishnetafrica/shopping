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

    private function pdf(string $bytes): array
    {
        if (class_exists(\Smalot\PdfParser\Parser::class)) {
            try {
                $doc = (new \Smalot\PdfParser\Parser())->parseContent($bytes);
                $text = trim((string) $doc->getText());
                if ($text !== '') {
                    return ['text' => $this->clip($text), 'parsed' => true, 'note' => ''];
                }

                return ['text' => '', 'parsed' => false, 'note' => 'PDF has no extractable text (likely scanned images)'];
            } catch (\Throwable $e) {
                return ['text' => '', 'parsed' => false, 'note' => 'PDF could not be parsed'];
            }
        }

        return ['text' => '', 'parsed' => false, 'note' => 'PDF reader not installed on server (smalot/pdfparser)'];
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
