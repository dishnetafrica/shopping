<?php

namespace App\Services\Travel\Conversation;

/**
 * Reads the hidden `<<TRIP {json}>>` line the AI model adds when the customer states or changes a
 * trip detail, and removes it from the text the customer sees. PURE.
 *
 * Same protocol as the brain's other markers (<<QUOTE>>, <<SEND>>, <<SOURCING>>): the line is the
 * model reporting, the code deciding. A malformed or cut-off marker is stripped to the end of the
 * message so it can never leak to a customer, and is reported so it can be counted.
 */
final class TripMarker
{
    /**
     * @return array{reply:string,patch:?array,new_trip:bool,malformed:bool}
     */
    public static function extract(string $reply): array
    {
        $patch = null;
        $newTrip = false;
        $malformed = false;

        if (preg_match_all('/<<TRIP\s*(\{.*?\})\s*>>/s', $reply, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $reply = str_replace($hit[0], '', $reply);
                $json = json_decode($hit[1], true);
                if (! is_array($json)) {
                    $malformed = true;
                    continue;
                }
                if (! empty($json['new_trip'])) {
                    $newTrip = true;
                }
                unset($json['new_trip']);
                // Two markers in one reply: later fields win, earlier ones are kept.
                $patch = array_merge($patch ?? [], $json);
            }
        }

        // Anything still starting a marker is cut off or broken: drop it to the end of the message.
        $pos = stripos($reply, '<<TRIP');
        if ($pos !== false) {
            $reply = substr($reply, 0, $pos);
            $malformed = true;
        }

        return [
            'reply'     => trim(preg_replace("/\n{3,}/", "\n\n", $reply) ?? $reply),
            'patch'     => $patch,
            'new_trip'  => $newTrip,
            'malformed' => $malformed,
        ];
    }
}
