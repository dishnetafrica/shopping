<?php declare(strict_types=1);

namespace App\Services\Advisor;

/**
 * Solar Experience Center — the public Knowledge Center articles.
 *
 * Longer, customer-facing educational articles (distinct from the internal sales knowledge library).
 * Each is server-rendered on its own URL with a unique title and meta description, so the Knowledge
 * Center doubles as an SEO engine. Data-only; add articles by adding to ARTICLES.
 *   slug, title, category, read (minutes), meta (description), excerpt, body (HTML), related (slugs)
 */
class SolarArticles
{
    public const ARTICLES = [
        [
            'slug' => 'how-solar-works', 'title' => 'How Solar Power Works', 'category' => 'Basics', 'read' => 3,
            'meta' => 'A simple, jargon-free explanation of how a solar system turns sunlight into power for your home or business.',
            'excerpt' => 'Panels, inverter, battery — what each part does and how energy flows, explained simply.',
            'related' => ['do-i-need-a-battery', 'how-we-size', 'hybrid-vs-offgrid'],
            'body' => '<p>A solar system quietly turns sunlight into the electricity your home or business already uses. There is no fuel and almost nothing to maintain. Here is what each part does.</p>'
                . '<h2>The four parts</h2>'
                . '<ul><li><b>Solar panels</b> sit on your roof or a frame and convert sunlight into DC electricity.</li>'
                . '<li>A <b>charge controller</b> feeds that power safely into the battery.</li>'
                . '<li>A <b>battery</b> stores energy for the night or for power cuts.</li>'
                . '<li>An <b>inverter</b> converts the stored DC into the normal AC power your appliances use.</li></ul>'
                . '<h2>How energy flows</h2>'
                . '<p>During the day the panels run your appliances directly and charge the battery at the same time. At night, or when the grid goes off, your appliances draw from the battery. If you stay connected to the grid, a hybrid system can also top up from it when needed.</p>'
                . '<p>That is the whole idea: capture power by day, use it whenever you need it.</p>',
        ],
        [
            'slug' => 'do-i-need-a-battery', 'title' => 'Do I Need a Battery?', 'category' => 'Basics', 'read' => 3,
            'meta' => 'When a solar battery is worth it, when it is not, and how backup hours affect the size and price of your system.',
            'excerpt' => 'A battery is what keeps you powered at night and during outages — but you only pay for the hours you need.',
            'related' => ['battery-guide', 'hybrid-vs-offgrid', 'solar-vs-generator'],
            'body' => '<p>Panels only make power when the sun is shining. A battery stores some of that power so you can use it at night or during a blackout. Whether you need one — and how big — depends on your goal.</p>'
                . '<h2>You want a battery if…</h2>'
                . '<ul><li>You have frequent power cuts and want the lights, fridge and Wi-Fi to stay on.</li>'
                . '<li>You want to run on solar in the evening instead of the grid or a generator.</li></ul>'
                . '<h2>You may not need one if…</h2>'
                . '<p>If the grid is reliable and you only want to cut your daytime bill, a grid-tie system without a battery can be enough — panels offset what you use while the sun is up.</p>'
                . '<h2>Backup hours drive the size</h2>'
                . '<p>The more hours of backup you want for your essential appliances, the bigger the battery. That is why we ask which appliances truly must stay on — sizing the battery around only those keeps the price sensible.</p>',
        ],
        [
            'slug' => 'hybrid-vs-offgrid', 'title' => 'Hybrid vs Off-Grid vs Backup', 'category' => 'Basics', 'read' => 4,
            'meta' => 'The three common solar setups — grid backup, hybrid and off-grid — and how to choose the right one.',
            'excerpt' => 'Three setups, three goals. Here is which one fits your situation.',
            'related' => ['do-i-need-a-battery', 'solar-vs-generator', 'how-we-size'],
            'body' => '<p>Most systems fall into one of three types. The right one depends on your grid situation and your goal.</p>'
                . '<h2>Backup (grid + battery)</h2>'
                . '<p>You stay on the grid, and the battery takes over instantly when it fails. Best where power exists but cuts out often.</p>'
                . '<h2>Hybrid</h2>'
                . '<p>Solar runs your loads and charges the battery by day; the grid tops up only when needed. This usually gives the best savings where grid power is available but expensive or unreliable.</p>'
                . '<h2>Off-grid</h2>'
                . '<p>No grid at all — solar and battery do everything. Best for remote sites, or where a grid connection is not practical. It needs a larger battery for full autonomy.</p>'
                . '<p>Not sure which you are? Our 3-minute assessment recommends the right one from your answers.</p>',
        ],
        [
            'slug' => 'battery-guide', 'title' => 'Solar Battery Guide: LiFePO4 vs Lead-Acid', 'category' => 'Batteries', 'read' => 4,
            'meta' => 'Compare LiFePO4 and lead-acid solar batteries on lifespan, usable capacity, maintenance and real value.',
            'excerpt' => 'Why the cheaper battery is often the more expensive choice over its life.',
            'related' => ['do-i-need-a-battery', 'solar-myths', 'how-we-size'],
            'body' => '<p>The battery is the heart of a backup system, and the two common types behave very differently.</p>'
                . '<h2>LiFePO4 (lithium)</h2>'
                . '<ul><li>Lasts about 8–10 years (4,000–6,000 cycles).</li><li>You can use ~80–90% of its capacity.</li><li>Maintenance-free, lighter, safer.</li></ul>'
                . '<h2>Lead-acid (AGM / gel)</h2>'
                . '<ul><li>Cheaper to buy, but lasts only 2–4 years.</li><li>You should use only ~50% of its capacity, so you need more of it.</li><li>Heavier; some types need maintenance.</li></ul>'
                . '<h2>Which is better value?</h2>'
                . '<p>Over its life, LiFePO4 usually costs less per year despite the higher sticker price — it lasts longer, gives more usable energy, and needs no upkeep. We size and warranty the bank so it is never over-stressed, which is what actually makes a battery last.</p>',
        ],
        [
            'slug' => 'solar-vs-generator', 'title' => 'Solar vs Generator: The Real Cost', 'category' => 'Cost & savings', 'read' => 4,
            'meta' => 'A generator is cheaper to buy but expensive to run. Compare fuel, maintenance, noise and lifetime cost against solar.',
            'excerpt' => 'Cheap to buy, expensive to run — the true cost of a generator vs solar.',
            'related' => ['hybrid-vs-offgrid', 'solar-myths', 'do-i-need-a-battery'],
            'body' => '<p>Generators win on upfront price and lose almost everywhere else. Here is the honest comparison.</p>'
                . '<h2>Running cost</h2>'
                . '<p>A generator burns fuel every hour it runs, needs regular servicing, and only makes power while it is on. Solar runs on free sunlight, has almost no running cost, and powers you day and night.</p>'
                . '<h2>Comfort and reliability</h2>'
                . '<p>Solar is silent and fume-free, and the battery switches on instantly during a cut — no walking outside to start anything. Many customers keep their generator only as a rare backup for long cloudy spells.</p>'
                . '<h2>The bottom line</h2>'
                . '<p>Add up fuel and servicing over a few years and solar is usually far cheaper overall, while being quieter and more reliable. Our assessment can show your estimated monthly saving.</p>',
        ],
        [
            'slug' => 'can-solar-run-ac', 'title' => 'Can Solar Run My Air Conditioner?', 'category' => 'Technical', 'read' => 3,
            'meta' => 'Yes — solar can run air conditioners, fridges and pumps, if the system is sized for their power and start-up surge.',
            'excerpt' => 'Short answer: yes. Here is what it takes to run heavy appliances on solar.',
            'related' => ['how-we-size', 'do-i-need-a-battery', 'battery-guide'],
            'body' => '<p>Yes — solar can run air conditioners, refrigerators and pumps. The trick is sizing the system for two things: how much energy they use, and their start-up surge.</p>'
                . '<h2>Energy use</h2>'
                . '<p>An AC uses a lot of power for the hours it runs, so it needs enough panels and battery to cover that. Running it fewer hours, or choosing an inverter (energy-efficient) AC, reduces the system size and cost.</p>'
                . '<h2>Start-up surge</h2>'
                . '<p>Motors — in ACs, fridges and pumps — briefly draw two to three times their normal power when they switch on. The inverter must be sized to handle that surge, or the system will trip. Our sizing engine accounts for this automatically.</p>'
                . '<p>Tell our assessment you have an AC (and how many) and it will size the system to run it.</p>',
        ],
        [
            'slug' => 'how-we-size', 'title' => 'How We Size Your Solar System', 'category' => 'Technical', 'read' => 4,
            'meta' => 'How a solar system is sized from your appliances: daily energy, panel array, battery bank and inverter, with safety margin.',
            'excerpt' => 'The exact, transparent method behind every recommendation we make.',
            'related' => ['can-solar-run-ac', 'do-i-need-a-battery', 'how-solar-works'],
            'body' => '<p>Every recommendation we make comes from the same transparent method — no guesswork.</p>'
                . '<h2>Step by step</h2>'
                . '<ul><li><b>Daily energy:</b> we add up your appliances and their daily run-hours to get how many units (kWh) you use per day.</li>'
                . '<li><b>Panels:</b> sized to generate that energy within your location\'s sun hours, plus about 20% for cloudy days and growth.</li>'
                . '<li><b>Battery:</b> sized to run your essential appliances for the backup hours you choose, after allowing for usable capacity.</li>'
                . '<li><b>Inverter:</b> sized for your peak demand, including motor start-up surge, with a safety margin.</li></ul>'
                . '<h2>Why we explain it</h2>'
                . '<p>Two quotes can differ hugely simply because they assumed different usage or backup. When you see the working, you can compare fairly — and trust the number. Try the assessment and it will show you exactly why it recommends what it does.</p>',
        ],
        [
            'slug' => 'solar-for-schools', 'title' => 'Solar for Schools', 'category' => 'By industry', 'read' => 3,
            'meta' => 'How solar keeps classrooms, offices, computer labs and boarding facilities powered reliably and affordably.',
            'excerpt' => 'Reliable power for classrooms, labs and boarding — and lower running costs.',
            'related' => ['hybrid-vs-offgrid', 'solar-vs-generator', 'how-we-size'],
            'body' => '<p>Schools need dependable power for learning and lower bills to protect their budgets — a great fit for solar.</p>'
                . '<h2>What it powers</h2>'
                . '<ul><li>Classroom and office lighting</li><li>Computer labs, projectors and printers</li><li>Water pumping and boarding facilities</li><li>Evening prep and security lighting</li></ul>'
                . '<h2>Why it fits schools</h2>'
                . '<p>Daytime usage lines up perfectly with peak sunshine, so a hybrid system offsets most of the bill. A battery keeps essentials running through outages and into the evening. Systems can start with the essentials and expand as the school grows.</p>',
        ],
        [
            'slug' => 'solar-for-health-centres', 'title' => 'Solar for Clinics & Health Centres', 'category' => 'By industry', 'read' => 3,
            'meta' => 'Uninterrupted solar power for clinics — protecting the vaccine cold chain, lighting and critical equipment 24/7.',
            'excerpt' => 'Keep vaccines cold and critical equipment powered — around the clock.',
            'related' => ['do-i-need-a-battery', 'how-we-size', 'solar-vs-generator'],
            'body' => '<p>For a clinic, power is not a convenience — it is care. Solar with battery backup keeps critical services running day and night.</p>'
                . '<h2>What it protects</h2>'
                . '<ul><li>The vaccine and medicine cold chain</li><li>Ward, theatre and delivery-room lighting</li><li>Diagnostic and monitoring equipment</li><li>Water pumping and communications</li></ul>'
                . '<h2>Designed for uptime</h2>'
                . '<p>Health facilities are sized for longer autonomy so critical loads never go dark, with the battery carrying essentials through the night and cloudy spells. It is quieter and cleaner than a generator, and far cheaper to run.</p>',
        ],
        [
            'slug' => 'solar-for-farms', 'title' => 'Solar for Farms & Irrigation', 'category' => 'By industry', 'read' => 3,
            'meta' => 'Solar for farm operations — irrigation and borehole pumping, cold storage, lighting and processing — without fuel.',
            'excerpt' => 'Pump water, run cold storage and light the sheds — on free daytime power.',
            'related' => ['can-solar-run-ac', 'how-we-size', 'solar-vs-generator'],
            'body' => '<p>Farms run on power — for water, storage and processing — and fuel costs eat into margins. Solar changes that.</p>'
                . '<h2>What it powers</h2>'
                . '<ul><li>Irrigation and borehole pumps</li><li>Cold storage for produce and dairy</li><li>Shed and yard lighting</li><li>Processing and workshop tools</li></ul>'
                . '<h2>Pumping by daylight</h2>'
                . '<p>Water pumping lines up naturally with sunshine — often you can pump into a tank by day and use it any time, sometimes without a battery at all. Pumps need surge headroom on the inverter, which we size in automatically.</p>',
        ],
        [
            'slug' => 'solar-myths', 'title' => '5 Common Solar Myths', 'category' => 'Basics', 'read' => 3,
            'meta' => 'Solar does not work on cloudy days? Batteries fail fast? We bust five common myths about solar power.',
            'excerpt' => 'Cloudy days, battery life, cost — the myths that hold people back, cleared up.',
            'related' => ['battery-guide', 'solar-vs-generator', 'how-solar-works'],
            'body' => '<p>A few myths stop people from switching. Here is the reality.</p>'
                . '<h2>“It doesn’t work on cloudy days.”</h2><p>Panels still generate in cloud — just less. Systems are sized for real local weather, and the battery covers the gaps.</p>'
                . '<h2>“Batteries fail within a year.”</h2><p>That was cheap lead-acid, over-stressed. Modern LiFePO4 lasts 8–10 years and comes warranted.</p>'
                . '<h2>“It’s too expensive.”</h2><p>Compared to years of fuel and grid bills, solar usually pays for itself in a few years — and you can start small and expand.</p>'
                . '<h2>“It needs constant maintenance.”</h2><p>Keep the panels clean and clear of shade. That is essentially it.</p>'
                . '<h2>“It can’t run big appliances.”</h2><p>It can — ACs, fridges and pumps — when sized for their power and surge.</p>',
        ],
        [
            'slug' => 'solar-in-uganda', 'title' => 'Solar in Uganda: What to Know', 'category' => 'Local', 'read' => 3,
            'meta' => 'Why solar suits Uganda and the region: strong year-round sun, unreliable or costly grid power, and fast payback.',
            'excerpt' => 'Strong sun, unreliable grid, high fuel costs — why solar makes sense here.',
            'related' => ['solar-vs-generator', 'hybrid-vs-offgrid', 'how-we-size'],
            'body' => '<p>Uganda and much of the region are close to the equator, with strong, steady sunshine all year — around five productive sun hours a day on average. That makes solar unusually effective here.</p>'
                . '<h2>Why it fits</h2>'
                . '<ul><li><b>Reliable sun</b> year-round means consistent production.</li>'
                . '<li><b>Frequent outages</b> make battery backup genuinely useful, not just a saving.</li>'
                . '<li><b>High fuel and grid costs</b> mean solar pays back quickly.</li></ul>'
                . '<h2>Getting it right</h2>'
                . '<p>The key is sizing for your actual appliances and backup needs, and using batteries built to last in warm conditions. Our assessment uses local sun-hour figures so the recommendation reflects real conditions here.</p>',
        ],
    ];

    /** All articles (each with its slug). */
    public static function all(): array
    {
        return self::ARTICLES;
    }

    public static function get(string $slug): ?array
    {
        foreach (self::ARTICLES as $a) {
            if ($a['slug'] === $slug) {
                return $a;
            }
        }
        return null;
    }

    /** Distinct categories in first-seen order. */
    public static function categories(): array
    {
        $seen = [];
        foreach (self::ARTICLES as $a) {
            if (! in_array($a['category'], $seen, true)) {
                $seen[] = $a['category'];
            }
        }
        return $seen;
    }
}
