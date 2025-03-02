<?php

namespace App\Menu;

use Knp\Menu\ItemInterface;
use Knp\Menu\Matcher\Voter\VoterInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class RequestVoter implements VoterInterface
{
    protected $requestStack;

    public function __construct(RequestStack $requestStack)
    {
        $this->requestStack = $requestStack;
    }

    public function matchItem(ItemInterface $item): ?bool
    {
        if ($item->getUri() === $this->requestStack->getCurrentRequest()->getRequestUri()) {
            // URL's completely match
            return true;
        }
        else if ($item->getUri() !== $this->requestStack->getCurrentRequest()->getBaseUrl() . '/'
          && (substr($this->requestStack->getCurrentRequest(), 0, strlen($item->getUri())) === $item->getUri())) {
            // URL isn't just "/" and the first part of the URL match
            return true;
        }

        return null;
    }
}
