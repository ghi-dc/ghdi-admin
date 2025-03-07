<?php

namespace App\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

class PlaceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
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
            ->add('geo', GeoCoordinatesType::class)
            ->add(
                $builder
                ->create('group-identifiers', FormType::class, [
                    'label' => 'Identifiers',
                    'inherit_data' => true,
                ])
                ->add('tgn', TextType::class, [
                    'label' => 'TGN',
                    'required' => false,
                ])
                ->add('gnd', TextType::class, [
                    'label' => 'GND',
                    'required' => false,
                ])
                ->add('wikidata', TextType::class, [
                    'label' => 'Wikidata',
                    'required' => false,
                ])
                ->add('geonames', TextType::class, [
                    'label' => 'Geonames',
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
        return 'placetype';
    }
}
