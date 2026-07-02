<?php

namespace Drupal\api_proxy_pbs\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure PBS API Proxy settings for this site.
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

        $form['jsonapi_base_url'] = [
            '#type' => 'textfield',
            '#title' => $this->t('JSON:API Base URL'),
            '#default_value' => $config->get('jsonapi_base_url') ?? 'https://nginx-php.project-migration.pbsfm.au2.amazee.io',
            '#description' => $this->t('The base URL for the PBS Drupal JSON:API backend.'),
            '#required' => TRUE,
        ];

        $form['jsonapi_auth_username'] = [
            '#type' => 'textfield',
            '#title' => $this->t('JSON:API Username'),
            '#default_value' => $config->get('jsonapi_auth_username') ?? 'pbs',
            '#description' => $this->t('Basic auth username for JSON:API.'),
        ];

        $form['jsonapi_auth_password'] = [
            '#type' => 'password',
            '#title' => $this->t('JSON:API Password'),
            '#default_value' => $config->get('jsonapi_auth_password') ?? '',
            '#description' => $this->t('Basic auth password for JSON:API. Leave empty to keep existing password.'),
        ];

        $form['slug_cache_ttl'] = [
            '#type' => 'number',
            '#title' => $this->t('Slug Cache TTL'),
            '#default_value' => $config->get('slug_cache_ttl') ?? 86400,
            '#description' => $this->t('Cache time-to-live for slug resolution in seconds (default: 86400 = 24 hours).'),
            '#min' => 0,
        ];

        return parent::buildForm($form, $form_state);
    }

    /**
     * {@inheritdoc}
     */
    public function validateForm(array &$form, FormStateInterface $form_state)
    {
        $url = $form_state->getValue('jsonapi_base_url');
        
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $form_state->setErrorByName(
                'jsonapi_base_url',
                $this->t('Please enter a valid URL.')
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state)
    {
        $config = $this->configFactory->getEditable(static::SETTINGS);
        
        $config->set('jsonapi_base_url', $form_state->getValue('jsonapi_base_url'));
        $config->set('jsonapi_auth_username', $form_state->getValue('jsonapi_auth_username'));
        $config->set('slug_cache_ttl', $form_state->getValue('slug_cache_ttl'));
        
        // Only update password if a new one was provided
        $password = $form_state->getValue('jsonapi_auth_password');
        if (!empty($password)) {
            $config->set('jsonapi_auth_password', $password);
        }
        
        $config->save();

        parent::submitForm($form, $form_state);
    }
}
