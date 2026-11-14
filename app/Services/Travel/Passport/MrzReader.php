<?php

namespace App\Services\Travel\Passport;

/**
 * Reads the machine-readable zone of a passport or ID card from a photo or a PDF, ON THIS SERVER.
 * Nothing is sent to any outside service: OCR is Tesseract, run as a local program.
 *
 * The file only ever exists as private temporary files (mode 0600, in a 0700 directory) for the
 * seconds the OCR takes, and they are deleted in a finally block whatever happens. The caller keeps
 * the bytes in memory only.
 *
 * Several attempts are made, cheapest first, and the first one whose MRZ passes every check digit
 * wins: the whole page, the bottom of the page enlarged, the same sharpened, then the page turned
 * a quarter, three quarters and half a turn (phone photos are often sideways).
 *
 * The OCR program is injectable so tests can replace it; by default it runs `tesseract`.
 */
class MrzReader
{
    public const MAX_BYTES = 12_000_000;

    /** About 100MB once decoded by GD; rotations make copies, and PHP has 512MB (docker/php.ini). */
    public const MAX_PIXELS = 25_000_000;

    /** Above this, OCR on the image as sent is slow; it goes straight to a scaled-down copy. */
    private const AS_SENT_MAX_PIXELS = 8_000_000;

    public const MAX_PDF_PAGES = 2;

    /**
     * Seconds allowed for one OCR run, and for one whole reading. A reading runs inside the message
     * job, which holds the conversation lock for 30 s (ProcessIncomingMessage::middleware) and is
     * killed by the worker after 60 s: no run starts unless it can finish within the budget.
     */
    public const RUN_TIMEOUT = 8;

    public const TOTAL_BUDGET = 18;

    private const WHITELIST = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789<';

    /** @var callable(string):string  image path → OCR text */
    private $ocr;

    private bool $injected;

    public function __construct(?callable $ocr = null, private string $language = 'eng')
    {
        $this->injected = $ocr !== null;
        $this->ocr = $ocr ?? fn (string $path): string => $this->tesseract($path);
    }

    /** Whether this reader can read anything: a replacement OCR was given, or tesseract is installed. */
    public function ready(): bool
    {
        return $this->injected || self::available();
    }

    /** Whether the OCR program is installed on this server. */
    public static function available(): bool
    {
        static $ok = null;
        if ($ok === null) {
            $out = [];
            $code = 1;
            @exec('command -v tesseract 2>/dev/null', $out, $code);
            $ok = $code === 0 && $out !== [];
        }

        return $ok;
    }

    /**
     * @param  string $bytes  the file's raw bytes (not base64)
     * @param  string $mime   as the provider reported it
     * @param  string $today  Y-m-d in the agency's timezone
     * @return array{status:string,mrz:?array,attempts:int}
     *         status: read | unreadable | unsupported | too_large | unavailable
     */
    public function read(string $bytes, string $mime, string $today): array
    {
        if ($bytes === '') {
            return ['status' => 'unreadable', 'mrz' => null, 'attempts' => 0];
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            return ['status' => 'too_large', 'mrz' => null, 'attempts' => 0];
        }
        $isPdf = str_starts_with($bytes, '%PDF') || stripos($mime, 'pdf') !== false;
        $info = $isPdf ? null : @getimagesizefromstring($bytes);
        if (! $isPdf && ! $info) {
            return ['status' => 'unsupported', 'mrz' => null, 'attempts' => 0];
        }
        if ($info && (int) $info[0] * (int) $info[1] > self::MAX_PIXELS) {
            return ['status' => 'too_large', 'mrz' => null, 'attempts' => 0];
        }

        $dir = $this->privateDir();
        $files = [];
        $attempts = 0;
        $started = microtime(true);
        try {
            $pages = [];
            if ($isPdf) {
                $pdf = $this->tempFile($dir, '.pdf', $files);
                file_put_contents($pdf, $bytes);
                $pages = $this->pdfPages($pdf, $dir, $files);
                if ($pages === []) {
                    return ['status' => 'unreadable', 'mrz' => null, 'attempts' => 0];
                }
            } else {
                $pages = [$bytes];
            }

            foreach ($pages as $page) {
                foreach ($this->plans() as $plan) {
                    if (microtime(true) - $started + self::RUN_TIMEOUT > self::TOTAL_BUDGET) {
                        break 2;
                    }
                    $png = $this->prepare($page, $plan, $dir, $files);
                    if ($png === null) {
                        continue;
                    }
                    $attempts++;
                    $mrz = Mrz::find((string) ($this->ocr)($png), $today);
                    if ($mrz !== null) {
                        return ['status' => 'read', 'mrz' => $mrz, 'attempts' => $attempts];
                    }
                }
            }

            return ['status' => 'unreadable', 'mrz' => null, 'attempts' => $attempts];
        } finally {
            foreach ($files as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
        }
    }

    /**
     * [turn in degrees anticlockwise, keep the bottom fraction of the page, minimum width, sharpen].
     * The first plan is the image exactly as sent: on a clean scan, processing only loses detail.
     */
    private function plans(): array
    {
        return [
            'as-sent',
            [0, 1.0, 1600, false],
            [0, 0.45, 2400, false],
            [0, 0.45, 3000, true],
            [90, 0.45, 2400, false],
            [270, 0.45, 2400, false],
            [180, 0.45, 2400, false],
        ];
    }

    /** One OCR-ready PNG from image bytes, or null if GD cannot decode them. */
    private function prepare(string $bytes, array|string $plan, string $dir, array &$files): ?string
    {
        if ($plan === 'as-sent') {
            $info = @getimagesizefromstring($bytes);
            if (! $info || (int) $info[0] * (int) $info[1] > self::AS_SENT_MAX_PIXELS) {
                return null;
            }
            $ext = match ($info[2] ?? 0) { IMAGETYPE_JPEG => '.jpg', IMAGETYPE_PNG => '.png', IMAGETYPE_WEBP => '.webp', default => null };
            if ($ext === null) {
                return null;
            }
            $path = $this->tempFile($dir, $ext, $files);
            file_put_contents($path, $bytes);

            return $path;
        }
        [$turn, $keep, $minWidth, $sharpen] = $plan;
        $im = @imagecreatefromstring($bytes);
        if (! $im) {
            return null;
        }
        try {
            if ($turn !== 0) {
                $turned = imagerotate($im, $turn, (int) imagecolorallocate($im, 255, 255, 255));
                if (! $turned) {
                    return null;
                }
                $im = $turned;
            }
            $w = imagesx($im);
            $h = imagesy($im);
            if ($keep < 1.0) {
                $top = (int) floor($h * (1 - $keep));
                $cropped = imagecrop($im, ['x' => 0, 'y' => $top, 'width' => $w, 'height' => $h - $top]);
                if (! $cropped) {
                    return null;
                }
                $im = $cropped;
                $w = imagesx($im);
            }
            // Too small and OCR cannot separate the characters; far too large (a 250 dpi page) and the
            // contrast step below starts to merge them.
            $target = $w < $minWidth ? $minWidth : ($w > 3200 ? 2600 : 0);
            if ($target > 0) {
                $scaled = imagescale($im, $target, -1, IMG_BICUBIC);
                if ($scaled) {
                    $im = $scaled;
                }
            }
            imagefilter($im, IMG_FILTER_GRAYSCALE);
            imagefilter($im, IMG_FILTER_CONTRAST, -30);
            if ($sharpen) {
                imageconvolution($im, [[0, -1, 0], [-1, 5, -1], [0, -1, 0]], 1, 0);
            }
            $png = $this->tempFile($dir, '.png', $files);
            imagepng($im, $png);

            return $png;
        } finally {
            imagedestroy($im);
        }
    }

    /** Render the first pages of a PDF to images with pdftoppm (poppler-utils). */
    private function pdfPages(string $pdf, string $dir, array &$files): array
    {
        $prefix = $this->tempFile($dir, '', $files);
        $this->run(['pdftoppm', '-r', '250', '-png', '-f', '1', '-l', (string) self::MAX_PDF_PAGES, $pdf, $prefix]);
        $pages = [];
        $rendered = glob($prefix . '*.png') ?: [];
        sort($rendered);                                    // page order (prefix-1.png, prefix-2.png)
        foreach ($rendered as $png) {
            $files[] = $png;
            $data = @file_get_contents($png);
            $info = $data !== false ? @getimagesizefromstring($data) : false;
            if ($info && (int) $info[0] * (int) $info[1] <= self::MAX_PIXELS) {
                $pages[] = $data;
            }
        }
        return array_slice($pages, 0, self::MAX_PDF_PAGES);
    }

    private function tesseract(string $path): string
    {
        return $this->run(['tesseract', $path, 'stdout', '-l', $this->language, '--psm', '6', '-c', 'tessedit_char_whitelist=' . self::WHITELIST]);
    }

    /** Run a program without a shell, with a time limit. Returns its standard output. */
    private function run(array $cmd): string
    {
        $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($proc)) {
            return '';
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $deadline = microtime(true) + self::RUN_TIMEOUT;
        while (true) {
            $out .= (string) stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            $st = proc_get_status($proc);
            if (! $st['running']) {
                $out .= (string) stream_get_contents($pipes[1]);
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($proc, 9);
                break;
            }
            usleep(20_000);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        return $out;
    }

    private function privateDir(): string
    {
        $dir = rtrim(sys_get_temp_dir(), '/') . '/cbss-mrz';
        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        @chmod($dir, 0700);

        return $dir;
    }

    private function tempFile(string $dir, string $suffix, array &$files): string
    {
        $base = tempnam($dir, 'm');
        if ($base === false) {
            throw new \RuntimeException('no temporary file');
        }
        @chmod($base, 0600);
        $files[] = $base;
        if ($suffix === '') {
            return $base;
        }
        $path = $base . $suffix;
        touch($path);
        @chmod($path, 0600);
        $files[] = $path;

        return $path;
    }
}
