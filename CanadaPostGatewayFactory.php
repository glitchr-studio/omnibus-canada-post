<?php

namespace Omnibus\CanadaPost;

use Omnibus\CanadaPost\Action\PickupAction;
use Omnibus\CanadaPost\Action\RatingAction;
use Omnibus\CanadaPost\Action\ShippingAction;
use Omnibus\CanadaPost\Action\TrackingAction;
use Omnibus\Config;
use Omnibus\Exception\InvalidConfigException;
use Omnibus\GatewayFactory;
use Symfony\Component\HttpClient\HttpClient;

/**
 *   options:
 *     username: '%env(CANADA_POST_USERNAME)%'      # API key's username and password (Developer Program)
 *     password: '%env(CANADA_POST_PASSWORD)%'
 *     customer_number: '%env(CANADA_POST_CUSTOMER)%'
 *     contract_id: null                            # a commercial contract, for contract shipping and rates
 *     sandbox: true                                # ct.soa-gw.canadapost.ca
 *     rates: [...]                                 # optional: configured prices instead of Get Rates
 */
final class CanadaPostGatewayFactory extends GatewayFactory
{
    protected function populateConfig(Config $config): void
    {
        $config->defaults([
            'omnibus.factory_name' => 'canada_post',
            'omnibus.factory_title' => 'Canada Post',
            'omnibus.required_options' => ['username', 'password', 'customer_number'],
            'contract_id' => null,
            'sandbox' => false,
            'omnibus.api' => function (Config $c) {
                $http = $this->http ?? (class_exists(HttpClient::class) ? HttpClient::create() : throw new InvalidConfigException('The "canada-post" gateway needs symfony/http-client.'));

                return new Api($http, (string) $c['username'], (string) $c['password'], (string) $c['customer_number'], $c['contract_id'] ?: null, (bool) $c['sandbox']);
            },
            'omnibus.action.rating' => static fn (Config $c) => $c->get('rates') ? null : new RatingAction(),
            'omnibus.action.shipping' => new ShippingAction(),
            'omnibus.action.tracking' => new TrackingAction(),
            'omnibus.action.pickup' => new PickupAction(),
        ]);
    }
}
