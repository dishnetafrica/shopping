<?php

declare(strict_types=1);

/**
 * Phase 1 — Customer Re-engagement Engine, pure-logic harness (runs in the sandbox, no framework).
 *
 * Proves the three pure deciders the whole engine rests on: ContactScore (ranking), EngagementEligibility
 * (the strict consent/cooldown gate), and CampaignType (which message kind). The framework-bound
 * EngagementPlanner that stitches them over real customers must be verified on staging (it needs the DB);
 * everything worth testing about the decision logic lives here and in tests/Unit/Engagement/*.
 *
 * Run:  php tests/Engagement/engagement_harness.php
 */

$base = dirname(__DIR__, 2) . '/app/Services/Engagement/';
require $base . 'ContactScore.php';
require $base . 'EngagementEligibility.php';
require $base . 'CampaignType.php';
require dirname(__DIR__, 2) . '/app/Services/Comms/Marketing/OptInKeywords.php';
require dirname(__DIR__, 2) . '/app/Services/Comms/Marketing/OptOutKeywords.php';

use App\Services\Comms\Marketing\OptInKeywords;
use App\Services\Comms\Marketing\OptOutKeywords;
use App\Services\Engagement\CampaignType;
use App\Services\Engagement\ContactScore;
use App\Services\Engagement\EngagementEligibility as EE;

$pass = 0; $fail = 0;
function ok(string $n, bool $c): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  \033[32m✓\033[0m $n\n"; }
    else    { $fail++; echo "  \033[31m✗ $n\033[0m\n"; }
}
function score(array $s): int { return ContactScore::score($s)['score']; }

echo "\033[1mContactScore — ranking\033[0m\n";
$vip = ['health'=>90,'flags'=>['VIP'],'reorder_state'=>'overdue','reorder_confidence'=>0.8,'orders'=>8,'value'=>5000,'replied_before'=>true,'days_since_contacted'=>40];
$weak = ['health'=>20,'flags'=>[],'reorder_state'=>'healthy','orders'=>2,'value'=>50,'marketed_30d'=>3,'replied_before'=>false,'days_since_contacted'=>5];
ok('VIP+overdue+replied scores high (>70)',      score($vip) > 70);
ok('weak+fatigued scores low (<35)',             score($weak) < 35);
ok('strong ranks above weak',                    score($vip) > score($weak));
ok('score is clamped 0..100',                    score($vip) <= 100 && score($weak) >= 0);
ok('reasons are produced for a strong customer',  count(ContactScore::score($vip)['reasons']) >= 3);

// overdue (slipping now) should outrank long-lapsed (probably gone), all else equal
$overdue = ['health'=>60,'reorder_state'=>'overdue','reorder_confidence'=>0.9,'orders'=>5,'value'=>1000];
$lapsed  = ['health'=>60,'reorder_state'=>'lapsed','reorder_confidence'=>0.9,'orders'=>5,'value'=>1000];
ok('overdue outranks lapsed (same otherwise)',    score($overdue) > score($lapsed));

// fatigue: repeated marketing with no reply pulls the score DOWN vs a fresh contact
$fresh   = ['health'=>50,'orders'=>4,'value'=>500,'marketed_30d'=>0,'days_since_contacted'=>null];
$fatig   = ['health'=>50,'orders'=>4,'value'=>500,'marketed_30d'=>3,'days_since_contacted'=>2,'replied_before'=>false];
ok('fatigued (3× no reply) scores below fresh',   score($fatig) < score($fresh));

// a customer with no orders has no re-engagement basis → pushed far down
ok('zero-order customer is penalised',            score(['health'=>40,'orders'=>0,'value'=>0]) < score(['health'=>40,'orders'=>3,'value'=>300]));

echo "\n\033[1mEngagementEligibility — the strict gate\033[0m\n";
$base14 = ['opted_in'=>true,'opted_out'=>false,'suppressed'=>false,'active_conversation'=>false,'days_since_contacted'=>30];
ok('opted-in, clear, past cooldown → eligible',   EE::decide($base14, 14)['eligible'] === true);
ok('opted-out → blocked opted_out',               EE::decide(['opted_out'=>true,'opted_in'=>true], 14)['blocked'] === EE::OPTED_OUT);
ok('suppressed → blocked suppressed',             EE::decide(['suppressed'=>true,'opted_in'=>true], 14)['blocked'] === EE::SUPPRESSED);
ok('not opted-in → blocked not_opted_in',         EE::decide(['opted_in'=>false], 14)['blocked'] === EE::NOT_OPTED_IN);
ok('a prior inbound is NOT consent (default off)',EE::decide([], 14)['eligible'] === false);
ok('active conversation → blocked',               EE::decide(['opted_in'=>true,'active_conversation'=>true], 14)['blocked'] === EE::ACTIVE_CONVERSATION);
ok('opt-out beats not-opted-in (precedence)',     EE::decide(['opted_out'=>true,'opted_in'=>false], 14)['blocked'] === EE::OPTED_OUT);

echo "\n\033[1mCooldown (14–21 day rotation)\033[0m\n";
ok('contacted 5d ago, 14d cooldown → blocked',    EE::decide(['opted_in'=>true,'days_since_contacted'=>5], 14)['blocked'] === EE::COOLDOWN);
ok('  … reports 9 days until eligible',           EE::decide(['opted_in'=>true,'days_since_contacted'=>5], 14)['next_eligible_in_days'] === 9);
ok('contacted 20d ago, 14d cooldown → eligible',  EE::decide(['opted_in'=>true,'days_since_contacted'=>20], 14)['eligible'] === true);
ok('never contacted → eligible',                  EE::decide(['opted_in'=>true,'days_since_contacted'=>null], 21)['eligible'] === true);
ok('exactly at cooldown boundary → eligible',     EE::decide(['opted_in'=>true,'days_since_contacted'=>21], 21)['eligible'] === true);
ok('21-day cooldown honoured',                    EE::decide(['opted_in'=>true,'days_since_contacted'=>20], 21)['blocked'] === EE::COOLDOWN);

echo "\n\033[1mCampaignType — deterministic classification\033[0m\n";
ok('DORMANT stage → win-back',                    CampaignType::decide(['stage'=>'DORMANT']) === CampaignType::DORMANT_WINBACK);
ok('WINBACK flag → win-back',                     CampaignType::decide(['flags'=>['WINBACK']]) === CampaignType::DORMANT_WINBACK);
ok('lapsed reorder → win-back',                   CampaignType::decide(['reorder_state'=>'lapsed']) === CampaignType::DORMANT_WINBACK);
ok('overdue reorder → replenishment',             CampaignType::decide(['reorder_state'=>'overdue']) === CampaignType::REPLENISHMENT);
ok('due reorder → replenishment',                 CampaignType::decide(['reorder_state'=>'due']) === CampaignType::REPLENISHMENT);
ok('VIP (nothing urgent) → VIP offer',            CampaignType::decide(['flags'=>['VIP'],'reorder_state'=>'healthy']) === CampaignType::VIP_OFFER);
ok('has recommendation → recommendation',         CampaignType::decide(['has_recommendation'=>true]) === CampaignType::RECOMMENDATION);
ok('nothing special → new stock',                 CampaignType::decide([]) === CampaignType::NEW_STOCK);
ok('win-back beats VIP when both apply',           CampaignType::decide(['stage'=>'DORMANT','flags'=>['VIP']]) === CampaignType::DORMANT_WINBACK);

echo "\n\033[1mConsent keywords (Phase 2 opt-in / opt-out)\033[0m\n";
ok('SUBSCRIBE → opt-in',                          OptInKeywords::isOptIn('SUBSCRIBE') === true);
ok('JOIN / OPT IN → opt-in',                      OptInKeywords::isOptIn('join') && OptInKeywords::isOptIn('opt in'));
ok('opt-in tolerates case/punctuation',           OptInKeywords::isOptIn('  Subscribe! '));
ok('ordinary order text is NOT opt-in',           OptInKeywords::isOptIn('yes 2kg') === false && OptInKeywords::isOptIn('start with rice') === false);
ok('bare YES is NOT opt-in (ambiguous)',          OptInKeywords::isOptIn('yes') === false);
ok('STOP is not misread as opt-in',               OptInKeywords::isOptIn('STOP') === false);
ok('STOP still detected as opt-out',              OptOutKeywords::isStop('STOP') === true);
ok('opt-in word is not an opt-out',               OptOutKeywords::isStop('SUBSCRIBE') === false);

echo "\n" . str_repeat('─', 60) . "\n";
echo $fail === 0
    ? "\033[32mALL {$pass} CHECKS PASSED.\033[0m\n"
    : "\033[31m{$fail} FAILED\033[0m, {$pass} passed.\n";
exit($fail === 0 ? 0 : 1);
