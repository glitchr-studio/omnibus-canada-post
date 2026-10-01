<?php

namespace Omnibus\CanadaPost\Action;

use Omnibus\Action\ActionInterface;
use Omnibus\Action\ApiAwareInterface;
use Omnibus\Action\ApiAwareTrait;
use Omnibus\CanadaPost\Api;
use Omnibus\CanadaPost\Mapping;
use Omnibus\Model\Tracking as TrackingModel;
use Omnibus\Model\TrackingEvent;
use Omnibus\Model\TrackingStatus;
use Omnibus\Request\Request;
use Omnibus\Request\Tracking;

/** Get Tracking Detail (GET /vis/track/pin/{pin}/detail): the significant events, oldest first. */
final class TrackingAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Tracking;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Tracking);
        $data = $this->api->call('GET', '/vis/track/pin/'.rawurlencode($request->trackingNumber).'/detail', null, 'application/vnd.cpc.track-v2+xml', null, str_starts_with($request->locale, 'fr') ? 'fr-CA' : 'en-CA');
        $events = [];
        foreach ($data->{'significant-events'}->occurrence ?? [] as $o) {
            $code = (string) $o->{'event-identifier'};
            $events[] = new TrackingEvent(new \DateTimeImmutable((string) $o->{'event-date'}.' '.(string) $o->{'event-time'}), Mapping::status($code, (string) $o->{'event-description'}), (string) $o->{'event-description'}, trim((string) $o->{'event-site'}.' '.(string) $o->{'event-province'}) ?: null, $code);
        }
        usort($events, static fn (TrackingEvent $a, TrackingEvent $b) => $a->at <=> $b->at);
        $status = $events ? $events[array_key_last($events)]->status : TrackingStatus::UNKNOWN;
        if (!empty($data->{'actual-delivery-date'})) {
            $status = TrackingStatus::DELIVERED;
        }
        $request->setResult(new TrackingModel('canada-post', $request->trackingNumber, $status, $events));
    }
}
