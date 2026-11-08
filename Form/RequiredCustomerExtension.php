<?php

namespace KimaiPlugin\CustomerGroupsBundle\Form;

use App\Form\TimesheetAdminEditForm;
use App\Form\TimesheetEditForm;
use App\Form\TimesheetMultiUserEditForm;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Makes the customer a required field of the timesheet forms of the web pages (start a timer, create or edit an entry,
 * and the admin forms). Kimai shows it as an optional filter of the project list, with an empty choice: the field is not
 * saved ("mapped" false), an entry takes the customer of its project. Required, the field gets the asterisk, Kimai's
 * TomSelect drops the empty choice from the list (as for the project), and a form sent without customer gets an error.
 * The API form (TimesheetApiEditForm, used by the apps) is not changed.
 */
final class RequiredCustomerExtension extends AbstractTypeExtension
{
    private const MESSAGE = 'This value should not be blank.';

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public static function getExtendedTypes(): iterable
    {
        // the admin forms extend TimesheetEditForm as PHP classes, not as form parents
        return [TimesheetEditForm::class, TimesheetAdminEditForm::class, TimesheetMultiUserEditForm::class];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $form = $event->getForm();
            if (!$form->has('customer')) {
                return;
            }
            $customer = $form->get('customer');
            if ($customer->isDisabled() || $customer->getData() !== null) {
                return;
            }
            $customer->addError(new FormError($this->translator->trans(self::MESSAGE, [], 'validators'), self::MESSAGE));
        });
    }

    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        if (isset($view['customer']) && $form->has('customer') && !$form->get('customer')->isDisabled()) {
            $view['customer']->vars['required'] = true;
        }
    }
}
