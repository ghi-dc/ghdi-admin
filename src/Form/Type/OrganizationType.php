<?php

namespace App\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Validator\Constraints as Assert;

class OrganizationType
extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Name',
                'required' => true,
            ])
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
            ->add('url', UrlType::class, [
                'label' => 'Homepage',
                'required' => false,
            ])
            ->add('foundingDate', TextType::class, [
                'label' => 'Founding Date',
                'required' => false,
            ])
            ->add('dissolutionDate', TextType::class, [
                'label' => 'Dissolution Date',
                'required' => false,
            ])
            ->add(
                $builder
                ->create('group', FormType::class, [
                    'label' => 'Identifiers',
                    'inherit_data' => true,
                ])
                ->add('gnd', TextType::class, [
                    'label' => 'GND',
                    'required' => false,
                ])
                ->add('wikidata', TextType::class, [
                    'label' => 'Wikidata',
                    'required' => false,
                ])
            )
            ;

        $builder->add('save', SubmitType::class, [
            'label' => 'Save',
        ]);
    }

    public function getName()
    {
        return 'organizationtype';
    }
}
