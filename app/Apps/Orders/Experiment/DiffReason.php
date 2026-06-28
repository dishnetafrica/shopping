<?php
namespace App\Apps\Orders\Experiment;

/** Why a live decision and a shadow decision disagreed — so a week of data points to where to invest. */
final class DiffReason
{
    public const PRODUCT = 'PRODUCT';
    public const ADDRESS = 'ADDRESS';
    public const PAYMENT = 'PAYMENT';
    public const TIME = 'TIME';
    public const QUANTITY = 'QUANTITY';
    public const WEIGHT = 'WEIGHT';
    public const VARIANT = 'VARIANT';
    public const ALIAS = 'ALIAS';
    public const STATE = 'STATE';
    public const UNKNOWN = 'UNKNOWN';
}
