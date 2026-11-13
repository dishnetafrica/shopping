<?php

namespace App\Services\Bot;

/**
 * The delivery line for a shop that has STATED its own delivery policy.
 *
 * AiBrain's generated delivery lines were written for the first tenant: they promise free delivery
 * inside the home city and fall back to a list of Juba markets when the shop has not listed its
 * free-delivery areas. A Kampala shop that charges for every delivery was therefore told, next to its
 * quotation, "Delivery inside Kampala and its markets is free".
 *
 * When the owner has written a delivery policy (settings.delivery_note) and has NOT listed any
 * free-delivery areas of their own, the policy is the only true statement we have, so we say it and
 * nothing else. A shop that has listed its own free areas keeps the existing free / paid / unknown
 * wording, and a shop with no policy keeps the legacy default.
 *
 * Pure and static, so it can be tested without the framework.
 */
final class DeliveryWording
{
    /**
     * @return string|null the policy to state, or null to keep the existing wording
     */
    public static function statedPolicy(string $deliveryNote, string $freeDeliveryTowns): ?string
    {
        $policy = trim($deliveryNote);
        if ($policy === '' || trim($freeDeliveryTowns) !== '') {
            return null;
        }

        return $policy;
    }
}
