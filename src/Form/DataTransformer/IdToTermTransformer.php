<?php

namespace App\Form\DataTransformer;

use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;

/**
 * See https://symfonycasts.com/screencast/symfony-forms/data-transformer
 */
class IdToTermTransformer
implements DataTransformerInterface
{
    public function transform($value)
    {
        if (null === $value) {
            return '';
        }

        if (!$value instanceof \App\Entity\Term) {
            throw new \LogicException('The TermSelectTextType can only be used with Term objects');
        }

        return $value->getId();
    }

    public function reverseTransform($value)
    {
        if (empty($value)) {
            return null;
        }

        $term = new \App\Entity\Term();
        $term->setId($value);

        if (!$term) {
            throw new TransformationFailedException(sprintf('No term found with id "%s"', $value));
        }

        return $term;
    }
}