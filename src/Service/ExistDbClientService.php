<?php
// src/Service/ExistDbClientService.php

namespace App\Service;

/**
 * Wrapper around \ExistDbRpc\Client
 */
class ExistDbClientService
{
    protected $options;

    /**
     * @param array $options Client settings
     */
    public function __construct($options)
    {
        $this->options = $options;
    }

    /**
     * Instantiate a client
     *
     * @param string user
     * @param string password
     * @return \ExistDbRpc\Client
     */
    public function getClient($user = null, $password = null) : \ExistDbRpc\Client
    {
        $options = $this->options;
        if (!is_null($user)) {
            $options['user'] = $user;
        }

        if (!is_null($password)) {
            $options['password'] = $password;
        }

        return new \ExistDbRpc\Client($options);
    }
}
