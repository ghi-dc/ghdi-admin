<?php

// src/Service/ExistDbClientService.php
namespace App\Service;

class ExistDbClientService
{
    protected $options;

    public function __construct($options)
    {
        $this->options = $options;
    }

    public function getClient($user = null, $password = null)
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
