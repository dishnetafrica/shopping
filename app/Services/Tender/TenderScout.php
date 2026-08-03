<?php

namespace App\Services\Tender;

use App\Models\Tenant;
use App\Models\Tender;

/**
 * v4.19 TenderBot — the daily forum watcher ("ai can browse daily … instead of manually
 * upload the docs").
 *
 * The South Sudan NGO Forum runs Discourse, which exposes clean JSON:
 *   {category}.json           → topic list (id, title, created_at)
 *   {base}/t/{id}.json        → first post HTML + attachment links (/uploads/…)
 * scan() finds topics newer than the freshness window that we have not analysed yet,
 * downloads their attachments, runs TenderAnalyzer, and WhatsApps the owner the executive
 * summary. Every step is best-effort: one broken topic never stops the run.
 */
final class TenderScout
{
    private const FRESH_DAYS = 14;        // ignore old/pinned topics
    private const MAX_PER_RUN = 3;        // new tenders analysed per run (cost guard)
    private const MAX_FILE = 20 * 1024 * 1024;

    /** @return array{scanned:int, new:int, analysed:array<int,string>, note:string} */
    public function scan(Tenant $tenant, ?TenderAnalyzer $analyzer = null): array
    {
        $out = ['scanned' => 0, 'new' => 0, 'analysed' => [], 'note' => ''];
        try {
            $watch = trim((string) $tenant->setting('tender_watch_url', 'https://comms.southsudanngoforum.org/c/tenders/8'));
            if ($watch === '') {
                $out['note'] = 'no watch URL configured';

                return $out;
            }
            $base = preg_replace('~^(https?://[^/]+).*$~', '$1', $watch);
            $listUrl = rtrim($watch, '/');
            if (! str_ends_with($listUrl, '.json')) {
                $listUrl .= '.json';
            }
            $list = $this->fetchJson($listUrl);
            $topics = $list['topic_list']['topics'] ?? null;
            if (! is_array($topics)) {
                $out['note'] = 'forum did not answer with a topic list';

                return $out;
            }

            $analyzer = $analyzer ?: new TenderAnalyzer();
            $cutoff = time() - self::FRESH_DAYS * 86400;
            foreach ($topics as $t) {
                if (count($out['analysed']) >= self::MAX_PER_RUN) {
                    break;
                }
                $id = (int) ($t['id'] ?? 0);
                $title = trim((string) ($t['title'] ?? ''));
                $created = strtotime((string) ($t['created_at'] ?? '')) ?: 0;
                if ($id <= 0 || $title === '' || $created < $cutoff) {
                    continue;
                }
                $out['scanned']++;
                $ref = 'topic:' . $id;
                if (Tender::where('tenant_id', $tenant->id)->where('source', 'forum')->where('source_ref', $ref)->exists()) {
                    continue;
                }
                $out['new']++;

                // topic detail → first post text + attachments
                $topic = $this->fetchJson($base . '/t/' . $id . '.json');
                $cooked = (string) ($topic['post_stream']['posts'][0]['cooked'] ?? '');
                $postText = trim(html_entity_decode(strip_tags(preg_replace('~<(br|/p|/li|/h\d)[^>]*>~i', "\n", $cooked)), ENT_QUOTES, 'UTF-8'));
                $files = [];
                if (preg_match_all('~href="([^"]*/uploads/[^"]+)"[^>]*>([^<]{1,200})<~i', $cooked, $mm, PREG_SET_ORDER)) {
                    foreach (array_slice($mm, 0, 10) as $m) {
                        $href = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
                        $url = str_starts_with($href, 'http') ? $href : $base . $href;
                        $name = trim(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'));
                        if (! preg_match('/\.[a-z0-9]{2,5}$/i', $name)) {
                            $ext = preg_match('/\.([a-z0-9]{2,5})(?:\?|$)/i', $url, $em) ? $em[1] : 'pdf';
                            $name = ($name !== '' ? $name : 'attachment') . '.' . $ext;
                        }
                        $bytes = $this->fetchBytes($url);
                        if ($bytes !== null) {
                            $files[] = ['name' => $name, 'bytes' => $bytes];
                        }
                    }
                }

                $tender = $analyzer->analyze($tenant, $files, 'forum', $ref,
                    'Forum post: ' . $title . ' — ' . $base . '/t/' . $id, $postText);
                if ($tender) {
                    $out['analysed'][] = $title;
                    // v4.20 — action items become real tasks in the ops inbox (assignee gets
                    // the standard WhatsApp nudge + reminders/escalation automatically)
                    $nTasks = 0;
                    try {
                        $nTasks = app(TenderTasks::class)->forTender($tenant, $tender);
                    } catch (\Throwable $e) {
                    }
                    if ($tender->summary && $tender->status !== 'failed'
                        && (bool) $tenant->setting('tender_notify', true)) {
                        try {
                            \App\Jobs\NotifyOwner::dispatch($tenant->id, $tender->summary
                                . ($nTasks > 0 ? "\n\n📋 {$nTasks} task(s) created for your team — check the task inbox." : ''));
                        } catch (\Throwable $e) {
                        }
                    }
                }
            }

            return $out;
        } catch (\Throwable $e) {
            \Log::warning('TenderScout failed: ' . $e->getMessage());
            $out['note'] = 'scan error: ' . $e->getMessage();

            return $out;
        }
    }

    private function fetchJson(string $url): ?array
    {
        $raw = $this->fetchBytes($url, 5 * 1024 * 1024);
        if ($raw === null) {
            return null;
        }
        $j = json_decode($raw, true);

        return is_array($j) ? $j : null;
    }

    private function fetchBytes(string $url, int $cap = self::MAX_FILE): ?string
    {
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4,
                CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_TIMEOUT => 40,
                CURLOPT_SSL_VERIFYPEER => false, CURLOPT_USERAGENT => 'CloudBSS-TenderBot/1.0',
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($code < 200 || $code >= 300 || ! is_string($body) || $body === '' || strlen($body) > $cap) {
                return null;
            }

            return $body;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
