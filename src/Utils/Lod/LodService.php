<?php
namespace App\Utils\Lod;

use \App\Utils\Lod\Provider\Provider;

class LodService
{
    public function __construct(Provider $provider)
    {
        $this->provider = $provider;
    }

    public function __call($method, $args)
    {
        // TODO: check if provider supports this
        return call_user_func_array([ $this->provider, $method ], $args);
    }
}
