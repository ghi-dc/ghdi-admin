<?php

// src/Security/User/ExistDbUser.php

namespace App\Security\User;

use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\EquatableInterface;

class ExistDbUser implements UserInterface, EquatableInterface
{
    private $username;
    private $password;
    private $salt = '';
    private $roles;

    public function __construct($username, $password, $salt, array $roles)
    {
        $this->username = $username;
        $this->password = $password;
        $this->salt = $salt;
        $this->roles = $roles;
    }

    public function getRoles(): array
    {
        $roles = ['ROLE_USER'];
        // TODO: set according to $this->roles;

        return $roles;
    }

    public function getPassword()
    {
        return $this->password;
    }

    /* since we can't get this from getAccount() */
    public function setPassword($password)
    {
        $this->password = $password;
    }

    public function getSalt()
    {
        return $this->salt;
    }

    /**
     * Legacy.
     */
    public function getUsername()
    {
        return $this->getUserIdentifier();
    }

    public function getUserIdentifier(): string
    {
        return $this->username;
    }

    public function eraseCredentials() {}

    public function isEqualTo(UserInterface $user): bool
    {
        if (!$user instanceof ExistDbUser) {
            return false;
        }

        if ($this->password !== $user->getPassword()) {
            return false;
        }

        /*
        if ($this->getSalt() !== $user->getSalt()) {
            return false;
        }
        */

        if ($this->username !== $user->getUsername()) {
            return false;
        }

        return true;
    }
}
