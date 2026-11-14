<?php

/**
 * DRAFT pilot profile: Lazaros Travel and Safaris (issue #145).
 *
 * Source: the agency's public website, https://lazarostravel.com/, as read on 14 November 2026.
 * NOTHING here has been confirmed by the customer. It is a starting point for onboarding, applied
 * to a tenant only by `php artisan travel:apply-profile lazaros --tenant=<id>` and never on production.
 *
 * Deliberately NOT included:
 *   - any price, fare, package, deposit, payment or cancellation term (none is confirmed);
 *   - the staff alert numbers (the agency supplies its internal numbers at onboarding);
 *   - the public WhatsApp number shown on the website (it is the customer-facing number, and is set
 *     when the agency connects WhatsApp, never as a staff alert number);
 *   - the website's IATA accreditation statement (not independently verified);
 *   - the website's test and placeholder entries (test events, placeholder packages, duplicate
 *     services): they are not agency facts.
 */
return [
    'name'   => 'Lazaros Travel and Safaris',
    'source' => 'https://lazarostravel.com/ (read 14 November 2026)',

    // Tenant settings to apply. Each is written only when the tenant has no value for it yet.
    'settings' => [
        'vertical'              => 'travel',
        'timezone'              => 'Africa/Kampala',
        'travel_profile_status' => 'draft',
        'travel_services'       => 'air ticketing, airport transfers, car hire, courier services, hotel bookings, safaris, tours and attractions, travel insurance, visa processing, changes to existing tickets',
        'travel_profile_note'   => 'Office: Plot 6, Colville Street, opposite Christ the King Church, Airway Building, Kampala, Uganda. Office hours: Monday to Friday 8:00am to 5:00pm; Saturday 9:00am to 12:00pm; closed on Sunday. Office telephone: +256 414 660275.',
        'travel_home_airport'   => 'Entebbe',
    ],

    // Shown to whoever applies the profile, so nothing unconfirmed is mistaken for fact.
    'to_confirm_with_the_customer' => [
        'The list of services and whether every one is currently offered.',
        'Office address, opening hours and telephone number.',
        '"Entebbe" as the usual departure airport: this is an assumption from the Kampala office, not a statement on the website.',
        'Which public email address to give customers (the website shows two).',
        'Whether the IATA accreditation shown on the website may be stated by the assistant.',
        'Booking, deposit, payment and cancellation terms: none is set.',
        'The internal staff WhatsApp number or numbers for new enquiries: none is set, so nobody is alerted until they are.',
        'Languages and currencies to quote in.',
    ],
];
