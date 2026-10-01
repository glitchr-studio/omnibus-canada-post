<?php

namespace Omnibus\CanadaPost\Action;

use Omnibus\Action\ActionInterface;
use Omnibus\Action\ApiAwareInterface;
use Omnibus\Action\ApiAwareTrait;
use Omnibus\CanadaPost\Api;
use Omnibus\CanadaPost\Mapping;
use Omnibus\Model\Address;
use Omnibus\Model\PickupPoint;
use Omnibus\Request\Pickup;
use Omnibus\Request\Request;

/** Get Nearest Post Office (GET /rs/postoffice): post offices near a Canadian address. */
final class PickupAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Pickup;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Pickup);
        $near = $request->near;
        $data = $this->api->call('GET', '/rs/postoffice?'.http_build_query(array_filter(['d2po' => 'true', 'maximum' => min(50, max(1, $request->limit)), 'postalCode' => Mapping::postcode($near), 'city' => $near->city, 'province' => $request->near->company ? null : null])), null, 'application/vnd.cpc.postoffice+xml');
        $points = [];
        foreach ($data->{'post-office'} ?? [] as $office) {
            $a = $office->address;
            $points[] = new PickupPoint('canada-post', (string) $office->{'office-id'}, (string) $office->name,
                new Address((string) $office->name, [(string) $a->{'office-address'}], (string) $a->{'postal-code'}, (string) $a->city, 'CA'),
                isset($a->latitude) ? (float) $a->latitude : null, isset($a->longitude) ? (float) $a->longitude : null, [],
                isset($office->distance) ? (int) round(((float) $office->distance) * 1000) : null);
        }
        $request->setResult(\array_slice($points, 0, $request->limit));
    }
}
