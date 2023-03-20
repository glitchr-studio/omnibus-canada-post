<?php

namespace Omnibus\CanadaPost;

use Omnibus\Core\GatewayFactory;

class CanadaPostGatewayFactory extends GatewayFactory
{
    protected function populateConfig(ArrayObject $config)
    {
        $config->defaults([
            'omnibus.factory_name' => 'canada-post',
            'omnibus.factory_title' => 'Canada Post',
        ]);
    }
}