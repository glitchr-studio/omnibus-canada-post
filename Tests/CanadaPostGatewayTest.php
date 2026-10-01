<?php

namespace Omnibus\CanadaPost\Tests;

use Omnibus\CanadaPost\CanadaPostGatewayFactory;
use Omnibus\Model\Address;
use Omnibus\Model\Parcel;
use Omnibus\Model\Shipment;
use Omnibus\Model\TrackingStatus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CanadaPostGatewayTest extends TestCase
{
    private array $calls = [];

    private static function shipment(): Shipment
    {
        return new Shipment(new Address('Glitch Art', ['123 Rue Sainte-Catherine'], 'H2X 1K4', 'Montréal', 'CA', phone: '5145551234'), new Address('Alex Martin', ['100 Queen St W'], 'M5H 2N2', 'Toronto', 'CA'), [new Parcel(1500, 30, 20, 10)], reference: 'ORDER-1042', options: ['sender_province' => 'QC', 'recipient_province' => 'ON']);
    }

    private function gateway(?string $contract = null): \Omnibus\GatewayInterface
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertStringStartsWith('https://ct.soa-gw.canadapost.ca', $url);
            $path = (string) parse_url($url, \PHP_URL_PATH);
            $this->calls[] = [$method, $path, (string) ($options['body'] ?? ''), $options['headers']];
            $xml = ['response_headers' => ['content-type' => 'application/xml']];

            return match (true) {
                '/rs/ship/price' === $path => new MockResponse('<price-quotes xmlns="http://www.canadapost.ca/ws/ship/rate-v4"><price-quote><service-code>DOM.EP</service-code><service-name>Expedited Parcel</service-name><price-details><due>14.52</due></price-details><service-standard><expected-transit-time>2</expected-transit-time></service-standard></price-quote><price-quote><service-code>DOM.RP</service-code><service-name>Regular Parcel</service-name><price-details><due>13.80</due></price-details><service-standard><expected-transit-time>4</expected-transit-time></service-standard></price-quote></price-quotes>', $xml),
                str_ends_with($path, '/ncshipment'), str_ends_with($path, '/shipment') => new MockResponse('<non-contract-shipment-info xmlns="http://www.canadapost.ca/ws/ncshipment-v4"><shipment-id>406951321983787352</shipment-id><tracking-pin>12345678901234</tracking-pin><links><link rel="self" href="https://ct.soa-gw.canadapost.ca/rs/0001234567/ncshipment/406951321983787352" media-type="application/vnd.cpc.ncshipment-v4+xml"/><link rel="label" href="https://ct.soa-gw.canadapost.ca/rs/artifact/76108cb5192002d5/10238/0" media-type="application/pdf"/></links></non-contract-shipment-info>', $xml),
                str_contains($path, '/rs/artifact/') => new MockResponse('%PDF-1.4 cp', ['response_headers' => ['content-type' => 'application/pdf']]),
                str_contains($path, '/vis/track/pin/') => new MockResponse('<tracking-detail xmlns="http://www.canadapost.ca/ws/track-v2"><pin>12345678901234</pin><actual-delivery-date>2026-10-02</actual-delivery-date><significant-events><occurrence><event-identifier>1408</event-identifier><event-date>2026-10-02</event-date><event-time>10:15:00</event-time><event-description>Item successfully delivered</event-description><event-site>TORONTO</event-site><event-province>ON</event-province></occurrence><occurrence><event-identifier>0100</event-identifier><event-date>2026-10-01</event-date><event-time>08:00:00</event-time><event-description>Item processed</event-description><event-site>MONTREAL</event-site><event-province>QC</event-province></occurrence></significant-events></tracking-detail>', $xml),
                '/rs/postoffice' === $path => new MockResponse('<post-office-list xmlns="http://www.canadapost.ca/ws/postoffice"><post-office><office-id>0000123</office-id><name>SHOPPERS DRUG MART #0862</name><distance>0.62</distance><address><office-address>700 BAY ST</office-address><city>TORONTO</city><postal-code>M5G1Z6</postal-code><latitude>43.6565</latitude><longitude>-79.3850</longitude></address></post-office></post-office-list>', $xml),
                default => new MockResponse('<messages xmlns="http://www.canadapost.ca/ws/messages"><message><code>404</code><description>No such resource</description></message></messages>', ['http_code' => 404] + $xml),
            };
        });

        return (new CanadaPostGatewayFactory($http))->create(['username' => 'user', 'password' => 'pass', 'customer_number' => '0001234567', 'sandbox' => true, 'contract_id' => $contract]);
    }

    public function testRatesComeInCadCheapestFirst(): void
    {
        $rates = $this->gateway()->rate(self::shipment());
        self::assertSame(['DOM.RP', 'DOM.EP'], array_map(fn ($r) => $r->service, $rates));
        self::assertSame(1380, $rates[0]->amount);
        self::assertSame('CAD', $rates[0]->currency);
        self::assertSame(2, $rates[1]->days);
        self::assertStringContainsString('<weight>1.500</weight>', $this->calls[0][2]);
        self::assertStringContainsString('<postal-code>M5H2N2</postal-code>', $this->calls[0][2]);
        self::assertContains('Content-Type: application/vnd.cpc.ship.rate-v4+xml', $this->calls[0][3]);
    }

    public function testANonContractShipmentFetchesItsLabelFromTheArtifactLink(): void
    {
        $label = $this->gateway()->ship(self::shipment());
        self::assertSame('12345678901234', $label->trackingNumber);
        self::assertSame('%PDF-1.4 cp', $label->content);
        self::assertSame('/rs/0001234567/ncshipment', $this->calls[0][1]);
        self::assertStringContainsString('<prov-state>ON</prov-state>', $this->calls[0][2]);
        self::assertStringContainsString('<service-code>DOM.EP</service-code>', $this->calls[0][2]);
        self::assertStringContainsString('/rs/artifact/', $this->calls[1][1]);
    }

    public function testAContractShipmentGoesToTheContractEndpoint(): void
    {
        $this->gateway('0042708517')->ship(self::shipment());
        self::assertSame('/rs/0001234567/0001234567/shipment', $this->calls[0][1]);
        self::assertStringContainsString('<contract-id>0042708517</contract-id>', $this->calls[0][2]);
    }

    public function testTrackingAndPostOffices(): void
    {
        $tracking = $this->gateway()->track('12345678901234');
        self::assertSame(TrackingStatus::DELIVERED, $tracking->status);
        self::assertSame('Item processed', $tracking->events[0]->description);
        self::assertSame('TORONTO ON', $tracking->latest()->location);

        $points = $this->gateway()->pickupPoints(self::shipment()->recipient);
        self::assertSame('0000123', $points[0]->id);
        self::assertSame(620, $points[0]->distance);
    }
}
