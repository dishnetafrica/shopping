<?php

namespace Tests\Feature;

use App\Services\Bot\FaqDictionary;
use Tests\TestCase;

/** "Where is your shop?" — one setting (shop_address) feeds BOTH bot paths. */
class ShopAddressFaqTest extends TestCase
{
    public function test_where_questions_get_the_address_and_unset_falls_through(): void
    {
        $ctx = ['shop_address' => "A29 & A31, FREEDOM SQUARE CENTRE\nKonyo Konyo, Juba"];

        foreach (['where is your shop?', 'Where are you located', 'your shop address please',
                  'how can i find you', 'can i come to buy directly'] as $q) {
            $a = FaqDictionary::match($q, $ctx);
            $this->assertNotNull($a, "must answer: {$q}");
            $this->assertStringContainsString('FREEDOM SQUARE CENTRE', $a);
        }

        $this->assertStringContainsString('deliver', (string) FaqDictionary::match('where is your shop?', []),
            'no address configured → topic yields, another answer or the AI takes it');

        // website topic + website inside the address answer
        $ctx2 = ['shop_address' => 'A29, Freedom Square', 'website' => 'https://familyshoppers.net'];
        foreach (['send me your catalogue', 'do you have a price list', 'what do you sell',
                  'can i see your products', 'your website?'] as $q) {
            $this->assertStringContainsString('familyshoppers.net', (string) FaqDictionary::match($q, $ctx2), $q);
        }
        $this->assertStringContainsString('familyshoppers.net',
            (string) FaqDictionary::match('where is your shop?', $ctx2),
            'the address answer advertises the website when configured');

        $src = file_get_contents(app_path('Services/Bot/AiBrain.php'));
        $this->assertStringContainsString('Physical shop address', $src, 'the AI path knows it too');
    }
}
