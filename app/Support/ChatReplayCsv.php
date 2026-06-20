<?php
namespace App\Support;

/**
 * Parses an exported WhatsApp/chat CSV into normalised rows for bot:replay.
 *
 * Pure logic — no Laravel, no DB — so it is unit-testable framework-free (qa/replay_csv.php).
 *
 * Tolerant by design, because real exports vary wildly:
 *   - Header row optional. If present, columns are matched by name in any order.
 *     If absent, positional order is assumed: timestamp, conversation_id, direction, text.
 *   - Direction values are normalised from many synonyms (in/out, inbound/outbound,
 *     received/sent, customer/shop, →/←, etc.) to exactly 'in' or 'out'.
 *   - Quoted fields with embedded commas and newlines are handled (real fgetcsv parsing).
 *   - A UTF-8 BOM on the first cell is stripped. Blank-text rows are dropped.
 *
 * Output: an in-FILE-order list of:
 *   ['ts' => string, 'cid' => string, 'dir' => 'in'|'out'|'?', 'text' => string]
 *
 * File order is preserved as the replay order (exports are chronological); we do NOT try to
 * re-sort on timestamp, since timestamp formats are unpredictable and re-sorting a
 * well-ordered export only risks corrupting turn order.
 */
class ChatReplayCsv
{
    /** Header tokens we recognise, mapped to the canonical field. */
    private const HEADER_ALIASES = [
        'ts'   => ['timestamp', 'time', 'date', 'datetime', 'sent_at', 'created_at', 'when'],
        'cid'  => ['conversation_id', 'conversation', 'conv_id', 'chat_id', 'chat', 'thread',
                   'thread_id', 'session', 'session_id', 'phone', 'number', 'from_number', 'wa_id', 'contact'],
        'dir'  => ['direction', 'dir', 'type', 'sender', 'flow', 'inout', 'side'],
        'text' => ['text', 'message', 'msg', 'body', 'content', 'content_text'],
    ];

    private const DIR_IN  = ['in', 'inbound', 'incoming', 'received', 'recv', 'rx', 'customer',
                             'client', 'user', 'contact', 'from_customer', 'cust', '→', '->', '>', '<-in'];
    private const DIR_OUT = ['out', 'outbound', 'outgoing', 'sent', 'send', 'tx', 'shop', 'store',
                             'business', 'bot', 'agent', 'staff', 'me', 'owner', 'system', '←', '<-', '<'];

    /**
     * @return array<int,array{ts:string,cid:string,dir:string,text:string}>
     */
    public static function parse(string $csv): array
    {
        $rows = self::readCsv($csv);
        if ($rows === []) return [];

        // Decide header vs positional from the first row.
        $first = $rows[0];
        $map = self::headerMap($first);
        if ($map !== null) {
            array_shift($rows);                 // consume the header row
        } else {
            $map = ['ts' => 0, 'cid' => 1, 'dir' => 2, 'text' => 3]; // positional fallback
        }

        $out = [];
        foreach ($rows as $r) {
            $get = static function (?int $i) use ($r): string {
                if ($i === null || ! array_key_exists($i, $r)) return '';
                return trim((string) $r[$i]);
            };
            $text = $get($map['text'] ?? null);
            if ($text === '') continue;        // nothing to replay

            $out[] = [
                'ts'   => $get($map['ts'] ?? null),
                'cid'  => $get($map['cid'] ?? null),
                'dir'  => self::normaliseDir($get($map['dir'] ?? null)),
                'text' => $text,
            ];
        }
        return $out;
    }

    /** Keep only inbound (customer -> shop) rows, grouped by conversation id, in file order. */
    public static function inboundByConversation(array $parsed): array
    {
        $groups = [];
        foreach ($parsed as $row) {
            if ($row['dir'] !== 'in') continue;
            $cid = $row['cid'] !== '' ? $row['cid'] : '_nogroup';
            $groups[$cid][] = $row;
        }
        return $groups;
    }

    public static function normaliseDir(string $raw): string
    {
        $d = strtolower(trim($raw));
        if ($d === '') return '?';
        if (in_array($d, self::DIR_IN, true))  return 'in';
        if (in_array($d, self::DIR_OUT, true)) return 'out';
        // Loose contains check for verbose labels like "Inbound message".
        if (preg_match('/\b(in|inbound|incoming|received|customer|client|user)\b/', $d)) return 'in';
        if (preg_match('/\b(out|outbound|outgoing|sent|shop|business|bot|agent|staff)\b/', $d)) return 'out';
        return '?';
    }

    /**
     * Build a field->columnIndex map if the first row looks like a header, else null.
     */
    private static function headerMap(array $first): ?array
    {
        $cells = [];
        foreach ($first as $i => $c) {
            $cells[$i] = strtolower(trim(self::stripBom((string) $c)));
        }
        $map = [];
        foreach (self::HEADER_ALIASES as $field => $aliases) {
            foreach ($cells as $i => $name) {
                if ($name !== '' && in_array($name, $aliases, true)) { $map[$field] = $i; break; }
            }
        }
        // Need at least a recognisable text column AND one more header to trust it's a header row.
        if (! isset($map['text'])) return null;
        return count($map) >= 2 ? $map : null;
    }

    /** Parse a CSV string into rows, honouring quoted multi-line / comma fields. */
    private static function readCsv(string $csv): array
    {
        $csv = self::stripBom($csv);
        if (trim($csv) === '') return [];

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $csv);
        rewind($fh);

        $rows = [];
        while (($r = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
            // fgetcsv yields [0=>null] for a truly blank line — skip those.
            if ($r === [null] || $r === [false]) continue;
            $rows[] = $r;
        }
        fclose($fh);
        return $rows;
    }

    private static function stripBom(string $s): string
    {
        return (strncmp($s, "\xEF\xBB\xBF", 3) === 0) ? substr($s, 3) : $s;
    }
}
