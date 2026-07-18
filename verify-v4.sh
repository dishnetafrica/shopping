#!/usr/bin/env bash
# ============================================================================
# verify-v4.sh — V4 deploy diagnostic (blanket workspace 500s)
# Run INSIDE the app container (EasyPanel → Console):  bash verify-v4.sh
# Paste the FULL output back to Claude — section 0 alone usually names the bug.
# ============================================================================
ROOT=""
for d in "$(pwd)" /app /var/www/html /var/www /srv/app; do
  [ -f "$d/artisan" ] && ROOT="$d" && break
done
[ -z "$ROOT" ] && { echo "❌ Laravel root not found"; exit 1; }
cd "$ROOT" || exit 1
echo "== V4 diagnostic ==  $(date '+%H:%M:%S')"

echo
echo "--- 0. THE ACTUAL EXCEPTION (last error in laravel.log) ---"
LOG=$(ls -t storage/logs/laravel*.log 2>/dev/null | head -1)
if [ -n "$LOG" ]; then
  grep -n "ERROR\|Exception\|Error:" "$LOG" | tail -3
  echo "····· last stack head ·····"
  tac "$LOG" | grep -m1 -B0 -A0 "production.ERROR\|local.ERROR" | head -1
  tail -40 "$LOG" | head -40
else
  echo "no laravel.log found at storage/logs/"
fi

echo
echo "--- 1. all 28 V4 files present? (missing one = registry boot death) ---"
PASS=0; FAIL=0
ck () { if [ -f "$2" ] && grep -qF -- "$3" "$2"; then echo "✅ $1"; PASS=$((PASS+1)); else echo "❌ $1  → $2"; FAIL=$((FAIL+1)); fi; }
ckf () { if [ -f "$2" ]; then echo "✅ $1"; PASS=$((PASS+1)); else echo "❌ MISSING FILE: $2"; FAIL=$((FAIL+1)); fi; }
ckf "migration: relationship columns"   "database/migrations/2026_07_18_000020_add_relationship_memory_columns.php"
ckf "migration: followup_proposals"     "database/migrations/2026_07_18_000030_create_followup_proposals.php"
ckf "RelationshipExtractor"             "app/Services/Relationship/RelationshipExtractor.php"
ckf "ProfileContext"                    "app/Services/Relationship/ProfileContext.php"
ckf "GoalEngine"                        "app/Services/Relationship/GoalEngine.php"
ckf "PlanParser"                        "app/Services/Relationship/PlanParser.php"
ckf "EscalationService"                 "app/Services/Relationship/EscalationService.php"
ckf "FollowupProposal model"            "app/Models/FollowupProposal.php"
ckf "FollowupsCapability"               "app/Capability/Process/FollowupsCapability.php"
for c in RelationshipExtractCommand RelationshipStagesCommand RelationshipPlanCommand RelationshipAttributeCommand RelationshipReviewCommand ShopConfigureCommand; do
  ckf "$c" "app/Console/Commands/$c.php"
done
ck  "registry registers followups"      "app/Capability/Registry/CoreCapabilityProvider.php" "FollowupsCapability"
ck  "AiBrain: profile block"            "app/Services/Bot/AiBrain.php" "ProfileContext::block"
ck  "AiBrain: planning"                 "app/Services/Bot/AiBrain.php" "PlanParser::instructions"
ck  "Briefing: AI desk"                 "app/Capability/Process/MorningBriefingCapability.php" "ai_plan"
ck  "Customer 360: relationship card"   "app/Capability/Object/CustomerCapability.php" "Relationship (AI memory)"
ck  "models: profile columns"           "app/Models/CustomerProfile.php" "health_flags"
ck  "models: conversation review"       "app/Models/Conversation.php" "'review' => 'array'"
ck  "papi: followup approve"            "app/Http/Controllers/Panel/PanelApiController.php" "followupApprove"
ck  "routes: followups"                 "routes/web.php" "followups/{id}/approve"
ck  "schedules (5 relationship crons)"  "routes/console.php" "relationship:review"
ck  "cadence touch stamp"               "app/Console/Commands/SalesCadenceCommand.php" "last_contacted_at"

echo
echo "--- 2. migrations actually RUN? ---"
if command -v php >/dev/null 2>&1; then
  for m in add_relationship_memory_columns create_followup_proposals; do
    if php artisan migrate:status 2>/dev/null | grep "$m" | grep -qiE "Ran|\[1\]|Yes"; then
      echo "✅ $m RAN"
    else
      echo "❌ $m NOT RUN → php artisan migrate"
    fi
  done
  echo
  echo "--- 3. can the registry even boot? (prints the real error if not) ---"
  php artisan tinker --execute="try { echo 'capabilities: '.count(app(\App\Capability\Registry\CapabilityRegistry::class)->all()); } catch (\Throwable $e) { echo 'BOOT FAILS: '.get_class($e).' — '.$e->getMessage(); }" 2>&1 | tail -2
else
  echo "⚠️ php not on PATH"
fi

echo
echo "================================================"
echo "files: $PASS ok · $FAIL missing/wrong"
echo "Paste ALL of this to Claude. Section 0 (the log) usually names the exact bug."
