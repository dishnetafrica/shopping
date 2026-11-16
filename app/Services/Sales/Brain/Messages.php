<?php

namespace App\Services\Sales\Brain;

/** Plain-language messages for Sales Brain refusals (shared by the list and the entry screen). */
final class Messages
{
    public static function error(array $r): string
    {
        return (string) ($r['message'] ?? match ($r['error'] ?? '') {
            'forbidden'                 => 'Only the owner or a manager can change sales knowledge.',
            'unknown_topic'             => 'Choose a topic from the list.',
            'title_and_key_required'    => 'A title is needed.',
            'body_required'             => 'Write the content.',
            'too_long'                  => 'The content is too long.',
            'terms_need_items'          => 'Write at least one term, one per line ("Label: text").',
            'coverage_needs_areas'      => 'Write at least one area, one per line.',
            'coverage_area_invalid'     => 'Area "' . ($r['area'] ?? '') . '": the service must be starlink or fiber and the answer covered, survey or none.',
            'coverage_area_needs_place' => 'Area "' . ($r['area'] ?? '') . '": give at least one place name (3+ letters) to recognise it by.',
            'version_in_progress'       => 'This entry already has a version waiting. Finish or reject it first.',
            'open_questions'            => 'Answer every open question before approving.',
            'reason_required'           => 'Please give a reason.',
            'answer_required'           => 'Please write the answer.',
            'not_a_draft'               => 'Only a draft can be changed.',
            'not_found'                 => 'Not found.',
            default                     => 'That step is not allowed for this version.',
        });
    }
}
