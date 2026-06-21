<?php

namespace App\Services\Bot\Validation;

/**
 * Platform Validation — ground-truth fixtures for five business types. Pure data.
 *
 * Each fixture is a realistic WhatsApp history + a few orders + the known "actual" facts, so the
 * Validation Runner can measure discovery accuracy without needing a live tenant. These double as
 * the regression corpus for the whole onboarding pipeline.
 */
class ValidationFixtures
{
    /** @return array<string,array> keyed by business type */
    public static function all(): array
    {
        return [
            'snack'      => self::snack(),
            'restaurant' => self::restaurant(),
            'pharmacy'   => self::pharmacy(),
            'hardware'   => self::hardware(),
            'grocery'    => self::grocery(),
        ];
    }

    public static function get(string $type): ?array
    {
        return self::all()[$type] ?? null;
    }

    private static function orders(array $names): array
    {
        return array_map(fn ($n) => ['items_json' => [['name' => $n]]], $names);
    }

    // --------------------------------------------------------------------- snack
    private static function snack(): array
    {
        $export = <<<TXT
12/01/24, 9:00 AM - Owner: Welcome to Pal's Snacks! We are open 9am to 9pm daily
12/01/24, 9:01 AM - Owner: Closed on Sunday
12/01/24, 9:02 AM - Owner: Free delivery above 50000. Delivery fee 3000 otherwise
12/01/24, 9:03 AM - Owner: We deliver to Kololo, Ntinda and Bugolobi
12/01/24, 9:04 AM - Owner: We accept Mpesa and cash. Minimum order 10000
12/01/24, 9:05 AM - Owner: Today special 10% off on fafda!
12/01/24, 10:00 AM - Amit: fafda kitla che bhai
12/01/24, 10:01 AM - Sara: how much is jalebi?
12/01/24, 10:02 AM - Raj: what time you open today?
12/01/24, 10:03 AM - Mia: what time do you close?
12/01/24, 10:04 AM - Joel: do you deliver to ntinda?
12/01/24, 10:05 AM - Mary: do you deliver to kololo?
12/01/24, 10:06 AM - Amit: i want fafda and dhokla
12/01/24, 10:07 AM - Sara: samosa available?
12/01/24, 10:08 AM - Raj: is khaman available?
12/01/24, 10:09 AM - Mia: price of sev?
12/01/24, 10:10 AM - Joel: how much is samosa
12/01/24, 10:11 AM - Mary: can i pay with mpesa?
12/01/24, 10:12 AM - Amit: mpesa ok?
12/01/24, 10:13 AM - Sara: jalebi su bhav che
12/01/24, 10:14 AM - Raj: fafda fresh che?
12/01/24, 10:15 AM - Mia: dhokla and khaman please
TXT;
        return [
            'name'   => "Pal's Snacks",
            'owner'  => ['Owner'],
            'export' => $export,
            'orders' => self::orders(['Fafda', 'Jalebi', 'Samosa', 'Dhokla', 'Khaman', 'Sev', 'Fafda', 'Jalebi', 'Samosa']),
            'actual' => [
                'products'       => ['Fafda', 'Jalebi', 'Samosa', 'Dhokla', 'Khaman', 'Sev'],
                'faqs'           => ['hours', 'delivery', 'price', 'payment', 'availability'],
                'delivery_areas' => ['Kololo', 'Ntinda', 'Bugolobi'],
                'languages'      => ['English', 'Gujlish'],
                'offers'         => 1,
                'readiness'      => 80,
            ],
        ];
    }

    // ----------------------------------------------------------------- restaurant
    private static function restaurant(): array
    {
        $export = <<<TXT
12/01/24, 9:00 AM - Owner: Welcome to Spice Garden. Open 11am to 11pm everyday
12/01/24, 9:01 AM - Owner: We deliver to Kololo, Naguru and Bukoto
12/01/24, 9:02 AM - Owner: Delivery fee 5000. Minimum order 20000
12/01/24, 9:03 AM - Owner: We accept cash and mobile money
12/01/24, 9:04 AM - Owner: Today's thali: dal, rice, naan and paneer
12/01/24, 9:05 AM - Owner: Buy 1 get 1 on biryani this weekend
12/01/24, 10:00 AM - Rita: how much is masala dosa?
12/01/24, 10:01 AM - Sam: price of biryani?
12/01/24, 10:02 AM - Tina: what time do you open?
12/01/24, 10:03 AM - Ken: are you open now?
12/01/24, 10:04 AM - Rita: do you deliver to bukoto?
12/01/24, 10:05 AM - Sam: home delivery available?
12/01/24, 10:06 AM - Tina: i want paneer tikka and naan
12/01/24, 10:07 AM - Ken: is butter chicken available?
12/01/24, 10:08 AM - Rita: gulab jamun available?
12/01/24, 10:09 AM - Sam: biryani kitna hai
12/01/24, 10:10 AM - Tina: masala dosa chahiye
12/01/24, 10:11 AM - Ken: can i pay cash?
12/01/24, 10:12 AM - Rita: mobile money ok?
12/01/24, 10:13 AM - Sam: naan and butter chicken please
12/01/24, 10:14 AM - Tina: paneer tikka kitna
TXT;
        return [
            'name'   => 'Spice Garden',
            'owner'  => ['Owner'],
            'export' => $export,
            'orders' => self::orders(['Masala Dosa', 'Biryani', 'Paneer Tikka', 'Naan', 'Butter Chicken', 'Gulab Jamun', 'Biryani', 'Naan', 'Masala Dosa']),
            'actual' => [
                'products'       => ['Masala Dosa', 'Biryani', 'Paneer Tikka', 'Naan', 'Butter Chicken', 'Gulab Jamun'],
                'faqs'           => ['hours', 'delivery', 'price', 'payment', 'availability'],
                'delivery_areas' => ['Kololo', 'Naguru', 'Bukoto'],
                'languages'      => ['English', 'Hindi'],
                'offers'         => 1,
                'readiness'      => 80,
            ],
        ];
    }

    // ------------------------------------------------------------------- pharmacy
    private static function pharmacy(): array
    {
        $export = <<<TXT
12/01/24, 9:00 AM - Owner: Welcome to HealthPlus Pharmacy. Open 8am to 10pm daily
12/01/24, 9:01 AM - Owner: We deliver to Kololo and Nakawa
12/01/24, 9:02 AM - Owner: Delivery fee 2000. We accept cash and mpesa
12/01/24, 9:03 AM - Owner: Prescription required for antibiotics
12/01/24, 9:04 AM - Owner: 5% off on vitamins this week
12/01/24, 10:00 AM - Ann: do you have panadol?
12/01/24, 10:01 AM - Ben: is amoxicillin available?
12/01/24, 10:02 AM - Cara: how much is cough syrup?
12/01/24, 10:03 AM - Dan: price of vitamin c?
12/01/24, 10:04 AM - Ann: what time do you open?
12/01/24, 10:05 AM - Ben: are you open on sunday?
12/01/24, 10:06 AM - Cara: do you deliver to nakawa?
12/01/24, 10:07 AM - Dan: home delivery available?
12/01/24, 10:08 AM - Ann: do you have bandage?
12/01/24, 10:09 AM - Ben: is hand sanitizer in stock?
12/01/24, 10:10 AM - Cara: panadol price?
12/01/24, 10:11 AM - Dan: can i pay mpesa?
12/01/24, 10:12 AM - Ann: cash ok?
12/01/24, 10:13 AM - Ben: cough syrup and vitamin c please
12/01/24, 10:14 AM - Cara: amoxicillin in stock?
TXT;
        return [
            'name'   => 'HealthPlus Pharmacy',
            'owner'  => ['Owner'],
            'export' => $export,
            'orders' => self::orders(['Panadol', 'Amoxicillin', 'Cough Syrup', 'Vitamin C', 'Bandage', 'Hand Sanitizer', 'Panadol', 'Vitamin C']),
            'actual' => [
                'products'       => ['Panadol', 'Amoxicillin', 'Cough Syrup', 'Vitamin C', 'Bandage', 'Hand Sanitizer'],
                'faqs'           => ['hours', 'delivery', 'price', 'payment', 'availability'],
                'delivery_areas' => ['Kololo', 'Nakawa'],
                'languages'      => ['English'],
                'offers'         => 1,
                'readiness'      => 78,
            ],
        ];
    }

    // ------------------------------------------------------------------- hardware
    private static function hardware(): array
    {
        $export = <<<TXT
12/01/24, 9:00 AM - Owner: Welcome to BuildMart Hardware. Open 7am to 7pm Monday to Saturday
12/01/24, 9:01 AM - Owner: Closed on Sunday
12/01/24, 9:02 AM - Owner: We deliver to Industrial Area and Ntinda
12/01/24, 9:03 AM - Owner: Delivery fee 10000. We accept cash and bank transfer
12/01/24, 9:04 AM - Owner: Bulk discount 15% off on cement orders
12/01/24, 10:00 AM - Eric: how much is cement?
12/01/24, 10:01 AM - Fred: price of paint per litre?
12/01/24, 10:02 AM - Gina: do you have nails?
12/01/24, 10:03 AM - Hank: is a hammer available?
12/01/24, 10:04 AM - Eric: what time do you open?
12/01/24, 10:05 AM - Fred: are you open today?
12/01/24, 10:06 AM - Gina: do you deliver to ntinda?
12/01/24, 10:07 AM - Hank: home delivery available?
12/01/24, 10:08 AM - Eric: do you have pipe?
12/01/24, 10:09 AM - Fred: is padlock in stock?
12/01/24, 10:10 AM - Gina: cement price per bag?
12/01/24, 10:11 AM - Hank: can i pay bank transfer?
12/01/24, 10:12 AM - Eric: cash ok?
12/01/24, 10:13 AM - Fred: nails and hammer please
12/01/24, 10:14 AM - Gina: paint available?
TXT;
        return [
            'name'   => 'BuildMart Hardware',
            'owner'  => ['Owner'],
            'export' => $export,
            'orders' => self::orders(['Cement', 'Nails', 'Paint', 'Hammer', 'Pipe', 'Padlock', 'Cement', 'Paint']),
            'actual' => [
                'products'       => ['Cement', 'Nails', 'Paint', 'Hammer', 'Pipe', 'Padlock'],
                'faqs'           => ['hours', 'delivery', 'price', 'payment', 'availability'],
                'delivery_areas' => ['Industrial Area', 'Ntinda'],
                'languages'      => ['English'],
                'offers'         => 1,
                'readiness'      => 76,
            ],
        ];
    }

    // -------------------------------------------------------------------- grocery
    private static function grocery(): array
    {
        $export = <<<TXT
12/01/24, 9:00 AM - Owner: Welcome to FreshMart Grocery. Open 8am to 9pm daily
12/01/24, 9:01 AM - Owner: Free delivery above 40000. Delivery fee 2500
12/01/24, 9:02 AM - Owner: We deliver to Kira, Najjera and Kisaasi
12/01/24, 9:03 AM - Owner: We accept mpesa, cash and airtel money
12/01/24, 9:04 AM - Owner: Weekend offer 10% off on rice
12/01/24, 10:00 AM - Lily: how much is rice?
12/01/24, 10:01 AM - Max: price of cooking oil?
12/01/24, 10:02 AM - Nina: bei ya sukari?
12/01/24, 10:03 AM - Omar: do you have milk?
12/01/24, 10:04 AM - Lily: what time do you open?
12/01/24, 10:05 AM - Max: are you open now?
12/01/24, 10:06 AM - Nina: do you deliver to kira?
12/01/24, 10:07 AM - Omar: home delivery available?
12/01/24, 10:08 AM - Lily: do you have bread?
12/01/24, 10:09 AM - Max: are eggs available?
12/01/24, 10:10 AM - Nina: rice kitla che
12/01/24, 10:11 AM - Omar: can i pay mpesa?
12/01/24, 10:12 AM - Lily: cash ok?
12/01/24, 10:13 AM - Max: sugar and cooking oil please
12/01/24, 10:14 AM - Nina: milk and bread available?
TXT;
        return [
            'name'   => 'FreshMart Grocery',
            'owner'  => ['Owner'],
            'export' => $export,
            'orders' => self::orders(['Rice', 'Sugar', 'Cooking Oil', 'Milk', 'Bread', 'Eggs', 'Rice', 'Sugar']),
            'actual' => [
                'products'       => ['Rice', 'Sugar', 'Cooking Oil', 'Milk', 'Bread', 'Eggs'],
                'faqs'           => ['hours', 'delivery', 'price', 'payment', 'availability'],
                'delivery_areas' => ['Kira', 'Najjera', 'Kisaasi'],
                'languages'      => ['English', 'Swahili', 'Gujlish'],
                'offers'         => 1,
                'readiness'      => 80,
            ],
        ];
    }
}
