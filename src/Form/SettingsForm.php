<?php

namespace Drupal\pdf_alt_text\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\pdf_alt_text\Service\PdfAltTextService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for PDF Alt-Text Generator settings.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * The PDF alt-text service.
   *
   * @var \Drupal\pdf_alt_text\Service\PdfAltTextService
   */
  protected $pdfAltTextService;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->pdfAltTextService = $container->get('pdf_alt_text.client');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['pdf_alt_text.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'pdf_alt_text_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('pdf_alt_text.settings');

    $health = $this->pdfAltTextService->checkHealth();
    $status_class = ($health['status'] === 'online') ? 'messages--status' : 'messages--warning';
    $status_label = ($health['status'] === 'online') ? $this->t('Connected and operational') : $this->t('Offline / Not reachable');

    $form['status_box'] = [
      '#type' => 'item',
      '#markup' => '<div class="messages ' . $status_class . '"><strong>' . $this->t('Backend status (@mode):', ['@mode' => strtoupper($health['mode'])]) . '</strong> ' . $status_label . '</div>',
    ];

    $form['general'] = [
      '#type' => 'details',
      '#title' => $this->t('General settings'),
      '#open' => TRUE,
    ];

    $form['general']['processing_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Processing mode'),
      '#options' => [
        'cli' => $this->t('Local Python CLI (executes directly on the server host)'),
        'api' => $this->t('REST API Microservice (connects to Node.js/FastAPI server)'),
      ],
      '#default_value' => $config->get('processing_mode') ?: 'cli',
      '#description' => $this->t('Select how Drupal communicates with the PDF alt-text engine.'),
    ];

    $form['general']['default_language'] = [
      '#type' => 'select',
      '#title' => $this->t('Default target language for alt-text'),
      '#options' => [
        'nl' => $this->t('Dutch (nl)'),
        'en' => $this->t('English (en)'),
        'de' => $this->t('German (de)'),
        'fr' => $this->t('French (fr)'),
      ],
      '#default_value' => $config->get('default_language') ?: 'nl',
    ];

    $form['llm'] = [
      '#type' => 'details',
      '#title' => $this->t('Vision LLM connection'),
      '#open' => TRUE,
    ];

    $form['llm']['llm_api_url'] = [
      '#type' => 'url',
      '#title' => $this->t('OpenAI-compatible endpoint URL'),
      '#default_value' => $config->get('llm_api_url') ?: 'http://localhost:8080/v1',
      '#description' => $this->t('For example: http://localhost:8080/v1 (llama-server / llama-router), http://localhost:11434/v1 (Ollama), or https://openrouter.ai/api/v1.'),
      '#required' => TRUE,
    ];

    $form['llm']['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Key (optional)'),
      '#default_value' => $config->get('api_key') ?: '',
      '#description' => $this->t('Leave empty or set to "not-needed" for local LLM servers without authentication.'),
    ];

    $form['llm']['model'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Model identifier (optional)'),
      '#default_value' => $config->get('model') ?: '',
      '#description' => $this->t('For example: qwen3.8-27b, qwen2.5-vl, gemma-4. Leave blank for auto-detection of the first active model.'),
    ];

    $form['pdf_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('PDF & Accessibility settings'),
      '#open' => TRUE,
    ];

    $form['pdf_settings']['min_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum image dimension (pt / pixels)'),
      '#default_value' => $config->get('min_size') ?: 50,
      '#description' => $this->t('Images smaller than this threshold are treated as decorative and skipped.'),
      '#min' => 1,
      '#max' => 500,
    ];

    $form['pdf_settings']['save_as_new_revision'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Save Media as new revision'),
      '#default_value' => $config->get('save_as_new_revision') ?? TRUE,
      '#description' => $this->t('Preserves the original untagged PDF in media revision history when saving.'),
    ];

    $form['pdf_settings']['create_screen_annotations'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Add interactive screen annotations (/Annot /Screen)'),
      '#default_value' => $config->get('create_screen_annotations') ?? TRUE,
      '#description' => $this->t('Displays alt-text as interactive tooltips when hovering over images in desktop PDF viewers.'),
    ];

    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced CLI & Server paths'),
      '#open' => FALSE,
    ];

    $form['advanced']['python_executable'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Python executable path'),
      '#default_value' => $config->get('python_executable') ?: 'python3',
      '#description' => $this->t('Path to Python 3 with the pdf_alt_text package installed (e.g. /var/www/.venv/bin/python3 or python3).'),
    ];

    $form['advanced']['api_endpoint'] = [
      '#type' => 'url',
      '#title' => $this->t('Microservice API endpoint (for API mode)'),
      '#default_value' => $config->get('api_endpoint') ?: 'http://localhost:3000',
      '#description' => $this->t('Applies only when processing mode is set to REST API Microservice.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('pdf_alt_text.settings')
      ->set('processing_mode', $form_state->getValue('processing_mode'))
      ->set('default_language', $form_state->getValue('default_language'))
      ->set('llm_api_url', $form_state->getValue('llm_api_url'))
      ->set('api_key', $form_state->getValue('api_key'))
      ->set('model', $form_state->getValue('model'))
      ->set('min_size', (int) $form_state->getValue('min_size'))
      ->set('save_as_new_revision', (bool) $form_state->getValue('save_as_new_revision'))
      ->set('create_screen_annotations', (bool) $form_state->getValue('create_screen_annotations'))
      ->set('python_executable', $form_state->getValue('python_executable'))
      ->set('api_endpoint', $form_state->getValue('api_endpoint'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
