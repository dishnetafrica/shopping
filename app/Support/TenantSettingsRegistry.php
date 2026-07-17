<?php

namespace App\Support;

/**
 * Declarative schema for the self-service tenant Settings screen (v3 → Settings). Each configurable
 * key is declared ONCE here — its tab, group, type, default, and validation — instead of having its
 * default inlined at every call site. The Settings UI is generated from this schema, and the write API
 * validates against it, so adding a new setting later is a one-line change here (no new UI/API code).
 *
 * DESIGN RULE: every field's `default` MUST equal today's hardcoded behaviour, so turning the registry
 * on changes nothing until a tenant edits a value. Reads elsewhere stay `$t->setting($key, <default>)`
 * with the SAME default, so an unset key resolves identically whether or not it's in the registry.
 *
 * Stage 1 ships the Documents tab. Later stages add tabs (Money, Localization, Sales, Notifications)
 * by appending to schema() — nothing else needs to change.
 */
final class TenantSettingsRegistry
{
    public static function schema(): array
    {
        return [
            'documents' => [
                'label' => 'Documents',
                'icon'  => 'file',
                'groups' => [
                    'pdf' => [
                        'label' => 'PDF page setup',
                        'help'  => 'Page size and layout for the PDFs your customers receive. Defaults match the current documents.',
                        'fields' => [
                            'invoice_page_size'   => ['type' => 'enum', 'label' => 'Invoice page size',   'default' => 'A4', 'options' => ['A4', 'A5', 'Letter']],
                            'invoice_orientation' => ['type' => 'enum', 'label' => 'Invoice orientation', 'default' => 'portrait', 'options' => ['portrait', 'landscape']],
                            'receipt_page_size'   => ['type' => 'enum', 'label' => 'Receipt page size',   'default' => 'A5', 'options' => ['A4', 'A5']],
                            'receipt_orientation' => ['type' => 'enum', 'label' => 'Receipt orientation', 'default' => 'landscape', 'options' => ['portrait', 'landscape']],
                        ],
                    ],
                    'branding' => [
                        'label' => 'Invoice titling & numbering',
                        'help'  => 'How invoices are titled, numbered, and dated.',
                        'fields' => [
                            'invoice_title'      => ['type' => 'string', 'label' => 'Invoice title',         'default' => 'INVOICE', 'max' => 40, 'placeholder' => 'INVOICE'],
                            'invoice_prefix'     => ['type' => 'string', 'label' => 'Invoice number prefix', 'default' => 'INV', 'max' => 10, 'placeholder' => 'INV'],
                            'invoice_terms_days' => ['type' => 'int',    'label' => 'Payment terms',         'default' => 0, 'unit' => 'days', 'min' => 0, 'max' => 365, 'help' => '0 = due on receipt'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** Flat map of key => field spec, for validation and value merging. */
    public static function fields(): array
    {
        $out = [];
        foreach (self::schema() as $tab) {
            foreach (($tab['groups'] ?? []) as $group) {
                foreach (($group['fields'] ?? []) as $key => $spec) {
                    $out[$key] = $spec;
                }
            }
        }

        return $out;
    }

    /** Coerce + validate one incoming value against its spec. Returns [ok, value|null, error|null]. */
    public static function coerce(array $spec, $v): array
    {
        $type = $spec['type'] ?? 'string';

        if ($type === 'enum') {
            $v = (string) $v;
            return in_array($v, $spec['options'] ?? [], true) ? [true, $v, null] : [false, null, 'invalid option'];
        }
        if ($type === 'int') {
            if (! is_numeric($v)) return [false, null, 'must be a number'];
            $n = (int) $v;
            if (isset($spec['min'])) $n = max((int) $spec['min'], $n);
            if (isset($spec['max'])) $n = min((int) $spec['max'], $n);
            return [true, $n, null];
        }
        if ($type === 'bool') {
            return [true, filter_var($v, FILTER_VALIDATE_BOOLEAN), null];
        }
        // string
        $v = trim((string) $v);
        if (isset($spec['max'])) $v = mb_substr($v, 0, (int) $spec['max']);
        return [true, $v, null];
    }
}
