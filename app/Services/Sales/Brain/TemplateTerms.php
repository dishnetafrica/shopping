<?php

namespace App\Services\Sales\Brain;

/**
 * The terms and conditions exactly as written in DishNet's UISP "Smart Quote Template v5", supplied by
 * DishNet on 16 Nov 2026. They are imported into the Sales Brain as DRAFTS ONLY (never published by
 * import) with the questions management must settle before approving. Nothing here prints on a
 * quotation or reaches the bot until a manager approves and publishes it.
 */
final class TemplateTerms
{
    public const SOURCE = 'UISP Smart Quote Template v5 (supplied 16 Nov 2026) — draft, not approved';

    private const Q_EXCISE = 'Confirm the "Includes 26% Excise Duty" statement: is 26% the current South Sudan excise rate, and are all quoted prices really inclusive of it?';
    private const Q_UPTIME = 'Confirm the "99.5% guarantee" of uptime: can DishNet guarantee this (Starlink itself gives no uptime guarantee), and what does the customer get if it is missed?';
    private const Q_SUPPORT = 'Confirm the 24/7 support number +211 921 443 002 written into this term.';
    private const Q_LEASE = 'Term 4 says all devices are DishNet property (leased), but term 5 describes transferring the kit, which implies the customer owns it. Which is it — leased, sold, or different per package?';

    /** key => [title, items, open questions] */
    public static function all(): array
    {
        return [
            'starlink' => [
                'title' => 'Starlink service — terms and conditions',
                'items' => [
                    ['label' => 'Currency & Pricing', 'text' => 'All prices USD. Includes 26% Excise Duty. Subject to change with 30 days notice.'],
                    ['label' => 'Payment', 'text' => 'Monthly fees due 1st. Late = 5% penalty. Suspended after 7 days. Reconnection: $25.'],
                    ['label' => 'Installation', 'text' => 'Kit setup included. Roof/pole extra. Clear sky needed. 2-5 days after payment.'],
                    ['label' => 'Equipment', 'text' => 'All devices DishNet property (leased). Return within 7 days of termination.'],
                    ['label' => 'Kit Transfer', 'text' => "Subject to Starlink eligibility: min. 120 days from kit purchase OR 90 days from activation (whichever applies). Service must be cancelled prior to transfer initiation. DishNet fee: \$150. Processing: 14–30 days. DishNet is not liable for delays or rejections imposed by Starlink's platform."],
                    ['label' => 'Uptime', 'text' => '99.5% guarantee. 24/7 support: +211 921 443 002.'],
                    ['label' => 'Fair Use', 'text' => 'Personal/business only. No illegal content or reselling.'],
                    ['label' => 'Contract', 'text' => '6-month (180 days) min. Early exit: 30 days notice + fee.'],
                    ['label' => 'Refunds', 'text' => 'Install fees non-refundable once begun. Deposits refundable on equipment return.'],
                    ['label' => 'Relocation', 'text' => '$75 fee. 7 days advance notice required.'],
                    ['label' => 'Liability', 'text' => 'Not liable for indirect damages exceeding 3 months of fees.'],
                    ['label' => 'Force Majeure', 'text' => 'Not liable for disruptions from natural disasters, war, or infrastructure failures.'],
                    ['label' => 'Customer Duty', 'text' => 'Accurate info, safe environment, protect equipment, report faults within 24hrs.'],
                    ['label' => 'Governing Law', 'text' => 'Republic of South Sudan law and jurisdiction.'],
                ],
                'questions' => [self::Q_LEASE, self::Q_UPTIME, self::Q_EXCISE, self::Q_SUPPORT],
            ],
            'fiber' => [
                'title' => 'FTTH service — terms and conditions',
                'items' => [
                    ['label' => 'Currency & Pricing', 'text' => 'All prices USD. Includes 26% Excise Duty.'],
                    ['label' => 'Payment', 'text' => 'Monthly subscription in advance. Late = 5% penalty + auto disconnection.'],
                    ['label' => 'Installation', 'text' => 'Standard area. Non-standard (extra wiring/poles) extra. Customer provides ONT location.'],
                    ['label' => 'Equipment', 'text' => 'Routers/ONTs are DishNet property. Return in working condition within 7 days.'],
                    ['label' => 'Uptime', 'text' => '99.5% guarantee. 24/7 support: +211 921 443 002.'],
                    ['label' => 'Fair Use', 'text' => 'Personal/business only. No illegal content or reselling.'],
                    ['label' => 'Contract', 'text' => '3-month min. Early exit: 30 days notice + fee.'],
                    ['label' => 'Refunds', 'text' => 'Install fees non-refundable once begun. Deposits refundable on return.'],
                    ['label' => 'Relocation', 'text' => '$75 fee. 7 days advance notice.'],
                    ['label' => 'Liability', 'text' => 'Not liable for indirect damages exceeding 3 months of fees.'],
                    ['label' => 'Force Majeure', 'text' => 'Not liable for disruptions from natural disasters, war, or infrastructure failures.'],
                    ['label' => 'Customer Duty', 'text' => 'Accurate info, safe environment, protect equipment, report faults within 24hrs.'],
                    ['label' => 'Governing Law', 'text' => 'Republic of South Sudan law and jurisdiction.'],
                ],
                'questions' => [self::Q_UPTIME, self::Q_EXCISE, self::Q_SUPPORT],
            ],
            'general' => [
                'title' => 'Terms and conditions',
                'items' => [
                    ['label' => 'Currency & Pricing', 'text' => 'All prices USD. Includes 26% Excise Duty. Subject to change with 30 days notice.'],
                    ['label' => 'Payment', 'text' => 'Monthly fees due 1st. Late = 5% penalty. Suspended after 7 days. Reconnection: $25.'],
                    ['label' => 'Installation', 'text' => 'Standard area included. Non-standard may incur additional charges.'],
                    ['label' => 'Equipment', 'text' => 'All devices DishNet property (leased). Return within 7 days of termination.'],
                    ['label' => 'Uptime', 'text' => '99.5% guarantee. 24/7 support: +211 921 443 002.'],
                    ['label' => 'Fair Use', 'text' => 'Personal/business only. No illegal content or reselling.'],
                    ['label' => 'Contract', 'text' => 'Minimum commitment applies. Early exit: 30 days notice + fee.'],
                    ['label' => 'Refunds', 'text' => 'Install fees non-refundable once begun. Deposits refundable on equipment return.'],
                    ['label' => 'Liability', 'text' => 'Not liable for indirect damages exceeding 3 months of fees.'],
                    ['label' => 'Force Majeure', 'text' => 'Not liable for disruptions from natural disasters, war, or infrastructure failures.'],
                    ['label' => 'Customer Duty', 'text' => 'Accurate info, safe environment, protect equipment, report faults within 24hrs.'],
                    ['label' => 'Governing Law', 'text' => 'Republic of South Sudan law and jurisdiction.'],
                ],
                'questions' => [self::Q_UPTIME, self::Q_EXCISE, self::Q_SUPPORT],
            ],
        ];
    }
}
