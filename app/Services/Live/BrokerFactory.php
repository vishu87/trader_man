<?php

namespace App\Services\Live;

use App\Services\Live\Brokers\Broker;
use App\Services\Live\Brokers\DryRunBroker;
use App\Services\Live\Brokers\KiteBroker;

class BrokerFactory
{
    /** The broker the saved config asks for. Only mode 'live' ever returns one that sends real orders. */
    public static function make(?array $config = null): Broker
    {
        $config = $config ?? LiveConfig::get();
        return $config['mode'] === 'live' ? new KiteBroker() : new DryRunBroker();
    }
}
