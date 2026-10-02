<?php

namespace Omnibus\CanadaPost\Action;

use Omnibus\Action\ActionInterface;
use Omnibus\Action\ApiAwareInterface;
use Omnibus\Action\ApiAwareTrait;
use Omnibus\CanadaPost\Api;
use Omnibus\CanadaPost\Mapping;
use Omnibus\Model\Rate;
use Omnibus\Request\Rating;
use Omnibus\Request\Request;

/** Get Rates (POST /rs/ship/price): every service for the parcel, with the contract's prices when there is one. */
final class RatingAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Rating;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Rating);
        $s = $request->shipment;
        $to = strtoupper($s->recipient->country);
        $parcel = $s->parcels[0];
        $destination = match ($to) {
            'CA' => '<domestic><postal-code>'.Mapping::postcode($s->recipient).'</postal-code></domestic>',
            'US' => '<united-states><zip-code>'.Mapping::postcode($s->recipient).'</zip-code></united-states>',
            default => '<international><country-code>'.$to.'</country-code></international>',
        };
        $xml = '<?xml version="1.0" encoding="UTF-8"?><mailing-scenario xmlns="http://www.canadapost.ca/ws/ship/rate-v4">'
            .'<customer-number>'.Mapping::e($this->api->customerNumber).'</customer-number>'
            .($this->api->contract() ? '<contract-id>'.Mapping::e((string) $this->api->contractId).'</contract-id>' : '')
            .'<parcel-characteristics><weight>'.number_format(max(0.001, $s->weight() / 1000), 3, '.', '').'</weight>'
            .($parcel->length && $parcel->width && $parcel->height ? '<dimensions><length>'.$parcel->length.'</length><width>'.$parcel->width.'</width><height>'.$parcel->height.'</height></dimensions>' : '')
            .'</parcel-characteristics><origin-postal-code>'.Mapping::postcode($s->sender).'</origin-postal-code><destination>'.$destination.'</destination></mailing-scenario>';
        $data = $this->api->call('POST', '/rs/ship/price', $xml, 'application/vnd.cpc.ship.rate-v4+xml');
        $rates = [];
        foreach ($data->{'price-quote'} ?? [] as $quote) {
            $code = (string) $quote->{'service-code'};
            $days = isset($quote->{'service-standard'}->{'expected-transit-time'}) ? (int) $quote->{'service-standard'}->{'expected-transit-time'} : null;
            $rates[] = new Rate('canada_post', $code, (string) ($quote->{'service-name'} ?: Mapping::SERVICES[$code] ?? $code), (int) round(((float) $quote->{'price-details'}->due) * 100), 'CAD', $days);
        }
        usort($rates, static fn (Rate $a, Rate $b) => $a->amount <=> $b->amount);
        $request->setResult($rates);
    }
}
