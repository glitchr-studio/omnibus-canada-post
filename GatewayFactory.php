<?php

namespace Omnibus\CanadaPost;

use Omnibus\Core\GatewayFactory;
use Omnibus\CanadaPost\Action\ShippingAction;
use Omnibus\CanadaPost\Action\TrackingAction;
use Omnibus\CanadaPost\Action\PackingSlipAction;

class CanadaPostGatewayFactory extends GatewayFactory
{
    protected function populateConfig(ArrayObject $config)
    {
        $config->defaults([
            'omnibus.factory_name' => 'canada',
            'omnibus.factory_title' => 'CanadaPost',
            'omnibus.action.print_slip' => new PackingSlipAction(),
            'omnibus.action.shipping' => new ShippingAction(),
            'omnibus.action.tracking' => new TrackingAction(),
        ]);
    }
}