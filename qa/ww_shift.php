<?php
/** Winworld ShiftCalendar - 12h window scheduling. Pure logic. */
require __DIR__ . '/../app/Services/Winworld/ShiftCalendar.php';
use App\Services\Winworld\ShiftCalendar;

$pass = 0; $fail = 0;
function ok($c, string $l): void { global $pass,$fail; if($c)$pass++; else {$fail++; echo "  FAIL $l\n";} }
function eqs(string $g, string $w, string $l): void { global $pass,$fail; if($g===$w)$pass++; else {$fail++; echo "  FAIL $l -> $g != $w\n";} }
$fmt = fn(DateTimeImmutable $d) => $d->format('Y-m-d H:i');

$cal = new ShiftCalendar(7, 19); // 07:00-19:00, all week
ok($cal->dailyHours() === 12, 'daily window = 12h');

// within-day job
$start = new DateTimeImmutable('2026-06-18 07:00');
eqs($fmt($cal->addWorkingHours($start, 4)), '2026-06-18 11:00', '4h within day');
eqs($fmt($cal->addWorkingHours($start, 12)), '2026-06-18 19:00', '12h fills the day to 19:00');

// spill to next day: 10h from 14:00 -> 5h today (to 19:00) + 5h tomorrow (to 12:00)
$start2 = new DateTimeImmutable('2026-06-18 14:00');
eqs($fmt($cal->addWorkingHours($start2, 10)), '2026-06-19 12:00', '10h from 14:00 spills to next day 12:00');

// start before window -> snaps to 07:00
$early = new DateTimeImmutable('2026-06-18 05:30');
eqs($fmt($cal->addWorkingHours($early, 2)), '2026-06-18 09:00', 'pre-window snaps to 07:00 then +2h');

// start after window -> next day 07:00
$late = new DateTimeImmutable('2026-06-18 20:00');
eqs($fmt($cal->addWorkingHours($late, 3)), '2026-06-19 10:00', 'post-window -> next day 07:00 +3h');

// big job: 30h from 07:00 = 12+12+6 -> day3 13:00
eqs($fmt($cal->addWorkingHours($start, 30)), '2026-06-20 13:00', '30h spans 3 days to 13:00');

// zero hours -> normalized start
eqs($fmt($cal->addWorkingHours($start2, 0)), '2026-06-18 14:00', '0h returns start');

// working hours available between two stamps (planned_time)
$a = new DateTimeImmutable('2026-06-18 14:00');
$b = new DateTimeImmutable('2026-06-19 12:00');
$gh = $cal->workingHoursBetween($a, $b); // 5 (today) + 5 (tomorrow) = 10
ok(abs($gh - 10.0) < 0.001, "working hours between = 10 (got $gh)");

// holiday skip
$cal2 = new ShiftCalendar(7, 19, [1,2,3,4,5,6,7], ['2026-06-19']);
eqs($fmt($cal2->addWorkingHours($start2, 10)), '2026-06-20 12:00', '10h skips a holiday to following day');

echo "ww_shift: $pass passed, " . ($fail ? "FAIL $fail" : "0 failed") . "\n";
