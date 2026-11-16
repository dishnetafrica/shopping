<?php

namespace App\Services\Sales\Training;

/**
 * PURE rules of the sales training and certification (DishNet South Sudan plan, Phase 5).
 *
 *   Five modules with management's weights (25/20/25/20/10). The reading for each module is the
 *   Sales Brain's PUBLISHED knowledge of its topics; the questions are the published "quiz" entry of
 *   that module (governed like any knowledge: draft → approved → published).
 *   An assessment takes up to PER_MODULE questions from every module (options shuffled). Each module
 *   is scored as a percentage; the overall score is the weighted sum; PASS_MARK or more certifies.
 *   It can only start when every module has published questions — otherwise the weighting would be
 *   meaningless.
 */
final class Assessment
{
    public const PASS_MARK = 80.0;

    public const PER_MODULE = 8;

    public const MODULES = [
        'product'       => ['label' => 'Product knowledge', 'weight' => 25, 'topics' => ['company', 'starlink', 'fiber', 'packages', 'technical', 'coverage']],
        'qualification' => ['label' => 'Customer qualification', 'weight' => 20, 'topics' => ['qualification']],
        'communication' => ['label' => 'Communication and objection handling', 'weight' => 25, 'topics' => ['scripts', 'objections', 'faq']],
        'quotation'     => ['label' => 'Quotation and closing', 'weight' => 20, 'topics' => ['packages', 'terms', 'payment', 'followup']],
        'service'       => ['label' => 'Customer service', 'weight' => 10, 'topics' => ['faq', 'followup']],
    ];

    public const LETTERS = ['A', 'B', 'C', 'D', 'E', 'F'];

    // ── the question text box (one question block per paragraph) ─────────

    /**
     * "Q: question" / "- wrong option" / "* correct option" / optional "Why: explanation", questions
     * separated by a blank line.
     */
    public static function parseQuestions(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R\s*\R/', trim($text)) ?: [] as $block) {
            $q = ['q' => '', 'options' => [], 'answer' => null, 'why' => ''];
            foreach (preg_split('/\R/', $block) ?: [] as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                if (preg_match('/^Q\s*[:.)]\s*(.+)$/iu', $line, $m)) {
                    $q['q'] = trim($m[1]);
                } elseif (preg_match('/^Why\s*:\s*(.+)$/iu', $line, $m)) {
                    $q['why'] = trim($m[1]);
                } elseif (preg_match('/^([*\-])\s*(.+)$/u', $line, $m)) {
                    if ($m[1] === '*') {
                        $q['answer'] = $q['answer'] === null ? count($q['options']) : -1;      // a second "*" makes it invalid
                    }
                    $q['options'][] = trim($m[2]);
                } elseif ($q['q'] !== '' && ! $q['options']) {
                    $q['q'] .= ' ' . $line;                                                   // a question over two lines
                }
            }
            if ($q['q'] !== '' || $q['options']) {
                $out[] = $q;
            }
        }

        return $out;
    }

    public static function questionsText(array $questions): string
    {
        return implode("\n\n", array_map(function ($q) {
            $lines = ['Q: ' . $q['q']];
            foreach ($q['options'] as $i => $o) {
                $lines[] = ($i === $q['answer'] ? '* ' : '- ') . $o;
            }
            if (($q['why'] ?? '') !== '') {
                $lines[] = 'Why: ' . $q['why'];
            }

            return implode("\n", $lines);
        }, $questions));
    }

    /** Check a module's questions. ['ok'=>true,'data'=>..] or ['ok'=>false,'error'=>..,'question'=>n]. */
    public static function validate(string $module, array $questions): array
    {
        if (! isset(self::MODULES[$module])) {
            return ['ok' => false, 'error' => 'quiz_unknown_module'];
        }
        if (! $questions) {
            return ['ok' => false, 'error' => 'quiz_needs_questions'];
        }
        $clean = [];
        foreach (array_values($questions) as $i => $q) {
            $options = array_values(array_filter(array_map(fn ($o) => mb_substr(trim((string) $o), 0, 300), (array) ($q['options'] ?? [])), fn ($o) => $o !== ''));
            $answer = $q['answer'] ?? null;
            $text = mb_substr(trim((string) ($q['q'] ?? '')), 0, 500);
            if ($text === '' || count($options) < 2 || count($options) > count(self::LETTERS) || ! is_int($answer) || $answer < 0 || $answer >= count($options)) {
                return ['ok' => false, 'error' => 'quiz_question_invalid', 'question' => $i + 1];
            }
            $clean[] = ['q' => $text, 'options' => $options, 'answer' => $answer, 'why' => mb_substr(trim((string) ($q['why'] ?? '')), 0, 500)];
        }

        return ['ok' => true, 'data' => ['module' => $module, 'questions' => $clean]];
    }

    // ── an assessment ────────────────────────────────────────────────────

    /** Modules with no published questions (the assessment cannot start while any are missing). */
    public static function missing(array $bank): array
    {
        return array_values(array_filter(array_keys(self::MODULES), fn ($m) => empty($bank[$m])));
    }

    /**
     * The questions for one sitting: up to $per per module, in module order, options shuffled, with
     * the correct answer re-pointed. $seed makes it repeatable in tests.
     * @param array $bank module => list of validated questions
     */
    public static function pick(array $bank, int $seed, int $per = self::PER_MODULE): array
    {
        mt_srand($seed);
        $out = [];
        foreach (array_keys(self::MODULES) as $module) {
            $qs = array_values((array) ($bank[$module] ?? []));
            for ($i = count($qs) - 1; $i > 0; $i--) {                 // Fisher–Yates with the seeded generator
                $j = mt_rand(0, $i);
                [$qs[$i], $qs[$j]] = [$qs[$j], $qs[$i]];
            }
            foreach (array_slice($qs, 0, $per) as $q) {
                $order = array_keys($q['options']);
                for ($i = count($order) - 1; $i > 0; $i--) {
                    $j = mt_rand(0, $i);
                    [$order[$i], $order[$j]] = [$order[$j], $order[$i]];
                }
                $out[] = ['module' => $module, 'q' => $q['q'], 'options' => array_map(fn ($k) => $q['options'][$k], $order),
                    'answer' => array_search($q['answer'], $order, true), 'why' => $q['why'] ?? ''];
            }
        }
        mt_srand();

        return $out;
    }

    /**
     * Score a sitting. $answers: question index => letter ("A".."F"); anything else counts as wrong.
     * @return array{modules: array<string,array{label:string,weight:int,correct:int,total:int,percent:float}>, score: float, passed: bool, results: list<array>}
     */
    public static function score(array $questions, array $answers): array
    {
        $mods = [];
        foreach (self::MODULES as $k => $m) {
            $mods[$k] = ['label' => $m['label'], 'weight' => $m['weight'], 'correct' => 0, 'total' => 0, 'percent' => 0.0];
        }
        $results = [];
        foreach (array_values($questions) as $i => $q) {
            $given = strtoupper(trim((string) ($answers[$i] ?? $answers[(string) $i] ?? '')));
            $pos = array_search($given, self::LETTERS, true);
            $right = $pos !== false && $pos === $q['answer'];
            $mods[$q['module']]['total']++;
            $mods[$q['module']]['correct'] += $right ? 1 : 0;
            $results[] = ['correct' => $right, 'given' => $pos !== false && $pos < count($q['options']) ? self::LETTERS[$pos] : null, 'answer' => self::LETTERS[$q['answer']]];
        }
        $score = 0.0;
        foreach ($mods as $k => $m) {
            $mods[$k]['percent'] = $m['total'] > 0 ? round(100 * $m['correct'] / $m['total'], 1) : 0.0;
            $score += $m['weight'] * $mods[$k]['percent'] / 100;
        }
        $score = round($score, 1);

        return ['modules' => $mods, 'score' => $score, 'passed' => $score >= self::PASS_MARK, 'results' => $results];
    }

    /** "A) Mini · B) Standard" — the options as printed on the form. */
    public static function optionsLine(array $options): string
    {
        return implode(' · ', array_map(fn ($o, $i) => self::LETTERS[$i] . ') ' . $o, $options, array_keys($options)));
    }
}
