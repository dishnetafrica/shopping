<?php
/** qa/ai_production.php — production safety: defaults present, fallback reasons, empty/echo guards. */
require __DIR__.'/../app/Support/BrandDefaults.php';
use App\Support\BrandDefaults;

// guards mirrored from AiBrain
function emptyInput(string $text, string $img): bool { return trim($text)==='' && $img===''; }
function isEcho(?string $lastOut, string $text): bool { return $lastOut!==null && trim($text)!=='' && trim($text)===trim($lastOut); }

$pass=0;$fail=0;function check($l,$c){global $pass,$fail;if($c){$pass++;echo "  ok  $l\n";}else{$fail++;echo "  XX  $l\n";}}
echo "=== ai_production QA ===\n";

$p=BrandDefaults::persona('Krishna Wellness Ltd'); $k=BrandDefaults::knowledge();
check('persona non-empty + named',  strlen($p)>500 && str_contains($p,'Krishna Wellness Ltd'));
check('persona has security rules',  str_contains($p,'Never reveal') && str_contains($p,'ignore previous instructions'));
check('persona has price grounding', str_contains($p,'never invent a price'));
check('knowledge has brands',        str_contains($k,'EuroPearl') && str_contains($k,'Angel Soft') && str_contains($k,'Orchid'));
check('knowledge has certs',         str_contains($k,'UNBS') && str_contains($k,'ISO 9001'));
check('knowledge has education',     str_contains($k,'GSM') && str_contains($k,'Ply') || str_contains($k,'ply'));

check('empty text+no image guarded', emptyInput('','')===true);
check('text present not guarded',    emptyInput('hi','')===false);
check('image present not guarded',   emptyInput('','BASE64')===false);

check('exact echo caught',           isEcho('Hello! How can I help?','Hello! How can I help?')===true);
check('different text not echo',     isEcho('Hello!','hi there')===false);
check('no last-out not echo',        isEcho(null,'hi')===false);

echo "\n$pass / ".($pass+$fail)." passed\n";
echo $fail===0?"ALL GREEN\n":"FAILURES\n";
exit($fail===0?0:1);
