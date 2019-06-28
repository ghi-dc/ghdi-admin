<?php

namespace App\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\Extension\Core\Type\CountryType;
use Symfony\Component\Validator\Constraints as Assert;

class TeiHeaderType
extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Title',
                'required' => true,
            ])
            /*
            ->add(
                $builder
                ->create('group-names', FormType::class, [
                    'label' => 'Localized Names',
                    'inherit_data' => true,
                ])
                ->add('name_en', TextType::class, [
                    'label' => 'English',
                    'required' => false,
                ])
                ->add('name_de', TextType::class, [
                    'label' => 'German',
                    'required' => false,
                ])
            )
            */
            ;

        $builder->add('Save', SubmitType::class);
    }

    public function getName()
    {
        return 'teiheadertype';
    }
}
