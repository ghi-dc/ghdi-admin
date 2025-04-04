<?php

namespace App\Form\Type;

use App\Entity\GeoCoordinates;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\CountryType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class GeoCoordinatesType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('latitude', TextType::class, [
                'label' => 'Latitude',
                'required' => false,
            ])
            ->add('longitude', TextType::class, [
                'label' => 'Longitude',
                'required' => false,
            ])
            ->add('addressCountry', CountryType::class, [
                'label' => 'Belongs to',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => GeoCoordinates::class,
        ]);
    }
}
