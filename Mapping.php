<?php

namespace Omnibus\CanadaPost;

use Omnibus\Model\Address;
use Omnibus\Model\TrackingStatus;

/** Canada Post's shapes for ours: kilograms, centimetres, postal codes without the space. */
final class Mapping
{
    public const SERVICES = ['DOM.RP' => 'Regular Parcel', 'DOM.EP' => 'Expedited Parcel', 'DOM.XP' => 'Xpresspost', 'DOM.PC' => 'Priority', 'USA.EP' => 'Expedited Parcel USA', 'USA.XP' => 'Xpresspost USA', 'USA.TP' => 'Tracked Packet USA', 'USA.SP.AIR' => 'Small Packet USA Air', 'INT.XP' => 'Xpresspost International', 'INT.IP.AIR' => 'International Parcel Air', 'INT.TP' => 'Tracked Packet International', 'INT.SP.AIR' => 'Small Packet International Air'];

    public static function postcode(Address $a): string
    {
        return strtoupper(str_replace(' ', '', $a->postcode));
    }

    public static function addressXml(Address $a, ?string $province = null): string
    {
        $x = '<name>'.self::e($a->name).'</name>';
        if ($a->company) {
            $x .= '<company>'.self::e($a->company).'</company>';
        }
        if ($a->phone) {
            $x .= '<client-voice-number>'.self::e($a->phone).'</client-voice-number>';
        }
        $x .= '<address-details><address-line-1>'.self::e($a->line(0)).'</address-line-1>';
        if ('' !== $a->line(1)) {
            $x .= '<address-line-2>'.self::e($a->line(1)).'</address-line-2>';
        }
        $x .= '<city>'.self::e($a->city).'</city>';
        if ($province) {
            $x .= '<prov-state>'.self::e($province).'</prov-state>';
        }
        $x .= '<country-code>'.strtoupper($a->country).'</country-code><postal-zip-code>'.self::postcode($a).'</postal-zip-code></address-details>';

        return $x;
    }

    public static function e(string $s): string
    {
        return htmlspecialchars($s, \ENT_XML1 | \ENT_QUOTES, 'UTF-8');
    }

    public static function status(?string $code, ?string $description = null): TrackingStatus
    {
        $d = strtolower((string) $description);

        return match (true) {
            \in_array($code, ['1408', '1409', '1421', '1422', '1423', '1424', '1425', '1426', '1427', '1428', '1429', '1430', '1431', '1432', '1433', '1434', '1435', '1436', '1437', '1441', '1442', '1497', '1498'], true) || str_contains($d, 'delivered') => TrackingStatus::DELIVERED,
            '0500' === $code || str_contains($d, 'out for delivery') => TrackingStatus::OUT_FOR_DELIVERY,
            \in_array($code, ['1701', '1702', '1703', '1704', '1705', '1706', '1707'], true) || str_contains($d, 'available for pickup') => TrackingStatus::AVAILABLE_FOR_PICKUP,
            str_contains($d, 'return') => TrackingStatus::RETURNED,
            \in_array($code, ['2600', '2601', '2602', '2603', '2604'], true) || str_contains($d, 'attempted') || str_contains($d, 'unable') => TrackingStatus::EXCEPTION,
            \in_array($code, ['3000', '3001', '3002'], true) || str_contains($d, 'electronic information submitted') || str_contains($d, 'shipment information received') => TrackingStatus::PENDING,
            null !== $code => TrackingStatus::IN_TRANSIT,
            default => TrackingStatus::UNKNOWN,
        };
    }
}
