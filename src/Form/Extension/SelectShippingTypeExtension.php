<?php

namespace App\Form\Extension;

use Sylius\Bundle\CoreBundle\Form\Type\Checkout\SelectShippingType;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;

class SelectShippingTypeExtension extends AbstractTypeExtension
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('notes', TextareaType::class, [
            'label' => 'sylius.form.checkout.notes',
            'required' => false,
        ]);
    }

    public static function getExtendedTypes(): iterable
    {
        return [SelectShippingType::class];
    }
}
