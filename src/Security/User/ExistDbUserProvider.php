<?php

// src/Security/User/ExistDbUserProvider.php

namespace App\Security\User;

use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;

class ExistDbUserProvider implements UserProviderInterface
{
    var $existDbClientService;

    public function __construct(\App\Service\ExistDbClientService $existDbClientService)
    {
        $this->existDbClientService = $existDbClientService;
    }

    public function getExistDbClient($user = null, $password = null)
    {
        return $this->existDbClientService->getClient($user, $password);
    }

    /**
     * Legacy.
     */
    public function loadUserByUsername($username, $password = null)
    {
        return $this->loadUserByIdentifier($username, $password);
    }

    public function loadUserByIdentifier($username, $password = null): UserInterface
    {
        if (!is_null($password)) {
            $existDbClient = $this->getExistDbClient($username, $password);
        }
        else {
            $existDbClient = $this->getExistDbClient();
        }

        try {
            $user = $existDbClient->getAccount($username);
        }
        catch (\Exception $e) {
            /*
            $msg = $e->getMessage();
            if (preg_match('/Unauthorized/', $msg)) {
                throw new CustomUserMessageAuthenticationException('Invalid username or password.');
            }

            // CAUTION: this message will be returned to the client
            // (so don't put any un-trusted messages / error strings here)
            throw new CustomUserMessageAuthenticationException(
                sprintf('Invalid username or password (%s).', $msg));
            */

            throw new UserNotFoundException($e->getMessage());
        }

        // TODO: maybe check 'enabled'
        if (!empty($user)) {
            return new ExistDbUser($username, is_null($password) ? '' : $password, '', $user['groups']);
        }

        throw new UserNotFoundException(sprintf('Username "%s" does not exist.', $username));
    }

    public function isPasswordValid($username, $password)
    {
        $existDbClient = $this->getExistDbClient($username, $password);

        try {
            $user = $existDbClient->getAccount($username);
        }
        catch (\Exception $e) {
            return false;
        }

        return true;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof ExistDbUser) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', get_class($user)));
        }

        return $this->loadUserByIdentifier($user->getUsername(), $user->getPassword());
    }

    public function supportsClass($class): bool
    {
        return ExistDbUser::class === $class;
    }
}
