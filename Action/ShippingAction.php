<?php

namespace Omnibus\CanadaPost\Action;

use Omnibus\Action\ActionInterface;
use Omnibus\Action\ApiAwareInterface;
use Omnibus\Action\ApiAwareTrait;
use Omnibus\CanadaPost\Api;
use Omnibus\CanadaPost\Mapping;
use Omnibus\Exception\CarrierException;
use Omnibus\Model\Label;
use Omnibus\Request\Request;
use Omnibus\Request\Shipping;

/**
 * A non-contract shipment (POST /rs/{customer}/ncshipment, paid by the card on
 * file) or, with a contract, a contract shipment (POST /rs/{customer}/{customer}/shipment),
 * then its label downloaded from the artifact link. Options: sender_province and
 * recipient_province (two letters, Canadian addresses need them), label_format (PDF or ZPL).
 */
final class ShippingAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Shipping;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Shipping);
        $s = $request->shipment;
        $contract = $this->api->contract();
        $customer = rawurlencode($this->api->customerNumber);
        $parcel = $s->parcels[0];
        $international = 'CA' !== strtoupper($s->recipient->country);
        $root = $contract ? 'shipment' : 'non-contract-shipment';
        $ns = $contract ? 'http://www.canadapost.ca/ws/shipment-v8' : 'http://www.canadapost.ca/ws/ncshipment-v4';
        $xml = '<?xml version="1.0" encoding="UTF-8"?><'.$root.' xmlns="'.$ns.'">'
            .($contract ? '<group-id>'.date('Ymd').'</group-id><requested-shipping-point>'.Mapping::postcode($s->sender).'</requested-shipping-point>' : '<requested-shipping-point>'.Mapping::postcode($s->sender).'</requested-shipping-point>')
            .'<delivery-spec><service-code>'.Mapping::e($s->service ?? ($international ? 'INT.XP' : 'DOM.EP')).'</service-code>'
            .'<sender>'.Mapping::addressXml($s->sender, $s->option('sender_province')).'</sender>'
            .'<destination>'.Mapping::addressXml($s->recipient, $s->option('recipient_province')).'</destination>'
            .'<options>'.($s->option('signature') ? '<option><option-code>SO</option-code></option>' : '').'</options>'
            .'<parcel-characteristics><weight>'.number_format(max(0.001, $s->weight() / 1000), 3, '.', '').'</weight>'
            .($parcel->length && $parcel->width && $parcel->height ? '<dimensions><length>'.$parcel->length.'</length><width>'.$parcel->width.'</width><height>'.$parcel->height.'</height></dimensions>' : '')
            .'</parcel-characteristics>'
            .'<print-preferences><output-format>'.('ZPL' === strtoupper((string) $s->option('label_format', 'PDF')) ? '4x6' : '8.5x11').'</output-format><encoding>'.('ZPL' === strtoupper((string) $s->option('label_format', 'PDF')) ? 'ZPL' : 'PDF').'</encoding></print-preferences>'
            .'<preferences><show-packing-instructions>false</show-packing-instructions></preferences>'
            .($s->reference ? '<references><customer-ref-1>'.Mapping::e(mb_substr($s->reference, 0, 35)).'</customer-ref-1></references>' : '')
            .($international ? '<customs><currency>'.Mapping::e($parcel->currency).'</currency><reason-for-export>SOG</reason-for-export><sku-list><sku><customs-description>'.Mapping::e((string) $s->option('description', 'Merchandise')).'</customs-description><unit-weight>'.number_format(max(0.001, $s->weight() / 1000), 3, '.', '').'</unit-weight><customs-value-per-unit>'.number_format(($parcel->value ?? 100) / 100, 2, '.', '').'</customs-value-per-unit><customs-number-of-units>1</customs-number-of-units></sku></sku-list></customs>' : '')
            .'</delivery-spec>'
            .($contract ? '<settlement-info><contract-id>'.Mapping::e((string) $this->api->contractId).'</contract-id><intended-method-of-payment>Account</intended-method-of-payment></settlement-info>' : '')
            .'</'.$root.'>';
        $type = $contract ? 'application/vnd.cpc.shipment-v8+xml' : 'application/vnd.cpc.ncshipment-v4+xml';
        $data = $this->api->call('POST', $contract ? "/rs/$customer/$customer/shipment" : "/rs/$customer/ncshipment", $xml, $type);
        $number = (string) ($data->{'tracking-pin'} ?? '');
        if ('' === $number) {
            throw new CarrierException('canada-post', 'Canada Post booked no shipment.');
        }
        $labelUrl = null;
        $content = null;
        foreach ($data->links->link ?? [] as $link) {
            if ('label' === (string) $link['rel']) {
                $labelUrl = (string) $link['href'];
                try {
                    $document = $this->api->call('GET', $labelUrl, null, (string) ($link['media-type'] ?: 'application/pdf'));
                    $content = \is_string($document) ? $document : null;
                } catch (CarrierException) {
                    // The label is ready a moment later: GetSlip fetches it from the link.
                }
            }
        }
        $request->setResult(new Label('canada-post', $number, $content, 'ZPL' === strtoupper((string) $s->option('label_format', 'PDF')) ? Label::ZPL : Label::PDF, $labelUrl, 'https://www.canadapost-postescanada.ca/track-reperage/en#/search?searchFor='.rawurlencode($number)));
    }
}
