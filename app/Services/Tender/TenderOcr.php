<?php

namespace App\Services\Tender;

use App\Models\Tenant;
use App\Support\BotTrace;

/**
 * v4.22 TenderBot — read SCANNED tender documents.
 *
 * Most notices on the South Sudan NGO Forum are photographed/scanned pages: a 3MB PDF with no text
 * layer at all, which is why an analysis of one came back with no deadline and no product list. This
 * service renders those pages to JPEG (poppler's pdftoppm, installed in the runtime image) and has
 * the vision model transcribe them, so the rest of the pipeline sees ordinary text.
 *
 * Cost discipline: OCR only ever runs for a file that produced NO text by normal means, is capped at
 * MAX_PAGES per document, and sends pages in small batches. Every run is traced with the page count
 * so the spend is visible. A tenant can switch it off (`tender_ocr`).
 *
 * The `$vision` closure is the test seam — the harness passes a fake and never calls OpenAI.
 */
final class TenderOcr
{
    /** Hard ceiling per document. A 60-page annex is not worth OCR'ing in full to decide bid/no-bid. */
    public const MAX_PAGES = 15;

    /** Pages per vision call — small batches keep each request well inside the model's limits. */
    private const BATCH = 4;

    private const DPI = 150;

    public function __construct(private ?\Closure $vision = null) {}

    public function enabled(Tenant $tenant): bool
    {
        return (bool) $tenant->setting('tender_ocr', true);
    }

    /** True when this server can rasterise PDFs at all. */
    public function available(): bool
    {
        return (new TenderExtract())->popplerBin('pdftoppm') !== null;
    }

    /**
     * Read a scanned PDF.
     *
     * @return array{text:string, pages:int, note:string}
     */
    public function read(Tenant $tenant, string $bytes, string $name): array
    {
        if (! $this->enabled($tenant)) {
            return ['text' => '', 'pages' => 0, 'note' => 'scanned — OCR is switched off in Settings'];
        }
        $t0 = microtime(true);
        $images = $this->rasterise($bytes);
        if (! $images) {
            return ['text' => '', 'pages' => 0, 'note' => $this->available()
                ? 'scanned — pages could not be rendered'
                : 'scanned — this server cannot render PDF pages (poppler-utils missing)'];
        }

        $out = [];
        foreach (array_chunk($images, self::BATCH) as $i => $batch) {
            $text = $this->readBatch($tenant, $batch, $i * self::BATCH + 1);
            if ($text !== '') {
                $out[] = $text;
            }
        }
        $text = trim(implode("\n\n", $out));

        BotTrace::log($tenant->id, 'tender_ocr', null, 'ocr', $name . ' · ' . count($images) . ' page(s) · '
            . mb_strlen($text) . ' chars', (int) ((microtime(true) - $t0) * 1000));

        if ($text === '') {
            return ['text' => '', 'pages' => count($images), 'note' => 'scanned — the pages could not be read'];
        }

        return ['text' => $text, 'pages' => count($images),
            'note' => 'read by OCR from ' . count($images) . ' scanned page(s)'];
    }

    /**
     * PDF → JPEG pages via pdftoppm. Returns raw JPEG bytes, first MAX_PAGES pages only.
     *
     * @return array<int,string>
     */
    public function rasterise(string $bytes): array
    {
        $bin = (new TenderExtract())->popplerBin('pdftoppm');
        if ($bin === null) {
            return [];
        }
        $dir = sys_get_temp_dir() . '/tbocr-' . bin2hex(random_bytes(6));
        $pdf = $dir . '/in.pdf';
        try {
            if (! @mkdir($dir, 0700, true)) {
                return [];
            }
            file_put_contents($pdf, $bytes);
            @exec(escapeshellcmd($bin) . ' -jpeg -r ' . self::DPI . ' -f 1 -l ' . self::MAX_PAGES
                . ' ' . escapeshellarg($pdf) . ' ' . escapeshellarg($dir . '/p') . ' 2>/dev/null');

            $files = glob($dir . '/p*.jpg') ?: [];
            sort($files, SORT_NATURAL);
            $out = [];
            foreach (array_slice($files, 0, self::MAX_PAGES) as $f) {
                $img = (string) file_get_contents($f);
                if ($img !== '') {
                    $out[] = $img;
                }
            }

            return $out;
        } catch (\Throwable $e) {
            \Log::warning('TenderOcr rasterise failed: ' . $e->getMessage());

            return [];
        } finally {
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    /** One vision call over a handful of page images. */
    private function readBatch(Tenant $tenant, array $jpegs, int $firstPage): string
    {
        $prompt = "These are scanned pages (from page {$firstPage}) of a procurement tender document. "
            . "Transcribe ALL text you can read, in reading order, exactly as printed. "
            . "Keep tables as rows with their columns separated by ' | ' so quantities stay attached to their items. "
            . "Do not summarise, translate, explain or add anything. If a page is blank or unreadable, write nothing for it.";

        if ($this->vision !== null) {
            return trim((string) ($this->vision)($prompt, $jpegs, $firstPage));
        }

        try {
            if (! class_exists(\OpenAI\Laravel\Facades\OpenAI::class)) {
                return '';
            }
            // Vision needs a multimodal model; the tenant's chat model may be a text-only one.
            $model = (string) ($tenant->setting('ai_vision_model', '') ?: env('OPENAI_VISION_MODEL', 'gpt-4o-mini'));
            $key = (string) $tenant->setting('openai_api_key', '');
            $client = $key !== '' ? \OpenAI::client($key) : \OpenAI\Laravel\Facades\OpenAI::getFacadeRoot();

            $content = [['type' => 'text', 'text' => $prompt]];
            foreach ($jpegs as $img) {
                $content[] = ['type' => 'image_url', 'image_url' => [
                    'url' => 'data:image/jpeg;base64,' . base64_encode($img), 'detail' => 'high',
                ]];
            }
            $resp = $client->chat()->create([
                'model' => $model,
                'temperature' => 0,
                'messages' => [['role' => 'user', 'content' => $content]],
            ]);

            return trim((string) ($resp->choices[0]->message->content ?? ''));
        } catch (\Throwable $e) {
            \Log::warning('TenderOcr vision call failed: ' . $e->getMessage());

            return '';
        }
    }
}
