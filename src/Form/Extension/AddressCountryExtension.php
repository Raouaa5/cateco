<?php

declare(strict_types=1);

namespace App\Form\Extension;

use Sylius\Bundle\AddressingBundle\Form\Type\AddressType;
use Sylius\Component\Addressing\Model\CountryInterface;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

/**
 * Restricts the country dropdown to France (FR) only on all Sylius address forms.
 */
final class AddressCountryExtension extends AbstractTypeExtension
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $event): void {
            $form = $event->getForm();
            if (!$form->has('countryCode')) {
                return;
            }

            // Grab existing config and replace choices with France-only filter
            $countryCodeField = $form->get('countryCode');
            $fieldOptions = $countryCodeField->getConfig()->getOptions();

            // Filter to France only using the choice_filter option
            $fieldOptions['choice_filter'] = static fn (?CountryInterface $country): bool => $country !== null && $country->getCode() === 'FR';
            $fieldOptions['placeholder'] = false;

            $form->add('countryCode', get_class($countryCodeField->getConfig()->getType()->getInnerType()), $fieldOptions);
        });
    }

    public static function getExtendedTypes(): iterable
    {
        return [AddressType::class];
    }
}
