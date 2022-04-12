<?php

namespace Drupal\api_proxy_pbs\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Airnet Proxy Schedule settings for this site.
 */
class ScheduleSettingsForm extends ConfigFormBase
{
    /**
     * Config settings.
     *
     * @var string
     */
    const SETTINGS = 'api_proxy_pbs.settings';

    /**
     * {@inheritdoc}
     */
    public function getFormId()
    {
        return 'pbs_schedule_settings';
    }

    /**
     * {@inheritdoc}
     */
    protected function getEditableConfigNames()
    {
        return [static::SETTINGS];
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(array $form, FormStateInterface $form_state)
    {
        $config = $this->config(static::SETTINGS);

        $form['insomnia_lookup'] = [
            '#type' => 'textarea',
            '#rows' => 20,
            '#title' => $this->t('Insomnia lookup dictionary:'),
            '#default_value' => $config->get('insomnia_lookup'),
            '#description' => $this->t(
                'Enter valid JSON dictionary with insomnia lookups'
            ),
        ];

        return parent::buildForm($form, $form_state);
    }

    /**
     * {@inheritdoc}
     */
    public function validateForm(array &$form, FormStateInterface $form_state)
    {
        $body = $form_state->getValue('insomnia_lookup');
        $insomnia_lookup = json_decode($body, true);

        if ($insomnia_lookup == null) {
            $form_state->setErrorByName(
                'insomnia_lookup',
                $this->t('The source test is not valid JSON.')
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state)
    {
        // Retrieve the configuration.
        $this->configFactory
            ->getEditable(static::SETTINGS)
            ->set('insomnia_lookup', $form_state->getValue('insomnia_lookup'))
            ->save();

        parent::submitForm($form, $form_state);
    }
}
