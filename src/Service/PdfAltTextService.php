<?php

namespace Drupal\pdf_alt_text\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\media\MediaInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Service for communicating with the PDF Alt-Text generator (API or CLI).
 */
class PdfAltTextService {

  /**
   * The configuration object.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * The Guzzle HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The file repository service.
   *
   * @var \Drupal\file\FileRepositoryInterface
   */
  protected $fileRepository;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a new PdfAltTextService object.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    ClientInterface $http_client,
    FileSystemInterface $file_system,
    FileRepositoryInterface $file_repository,
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->config = $config_factory->get('pdf_alt_text.settings');
    $this->httpClient = $http_client;
    $this->fileSystem = $file_system;
    $this->fileRepository = $file_repository;
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('pdf_alt_text');
  }

  /**
   * Checks the health and status of the backend (API or CLI).
   *
   * @return array
   *   Associative array containing status information.
   */
  public function checkHealth(): array {
    $mode = $this->config->get('processing_mode') ?: 'api';

    if ($mode === 'api') {
      $endpoint = rtrim($this->config->get('api_endpoint') ?: 'http://localhost:3000', '/');
      try {
        $response = $this->httpClient->request('GET', $endpoint . '/api/health', [
          'timeout' => 5,
        ]);
        if ($response->getStatusCode() === 200) {
          $data = json_decode((string) $response->getBody(), TRUE);
          return [
            'status' => 'online',
            'mode' => 'api',
            'details' => $data,
          ];
        }
      }
      catch (GuzzleException $e) {
        return [
          'status' => 'offline',
          'mode' => 'api',
          'error' => $e->getMessage(),
        ];
      }
    }
    else {
      $cli_path = $this->config->get('cli_binary_path') ?: 'pdf-alt-text';
      $output = [];
      $return_var = 0;
      @exec(escapeshellcmd($cli_path) . ' --help', $output, $return_var);

      return [
        'status' => ($return_var === 0) ? 'online' : 'offline',
        'mode' => 'cli',
        'binary' => $cli_path,
        'installed' => ($return_var === 0),
      ];
    }

    return ['status' => 'unknown', 'mode' => $mode];
  }

  /**
   * Retrieves available models from the backend.
   *
   * @return array
   *   List of model IDs.
   */
  public function getAvailableModels(): array {
    $endpoint = rtrim($this->config->get('api_endpoint') ?: 'http://localhost:3000', '/');
    try {
      $response = $this->httpClient->request('GET', $endpoint . '/api/models', [
        'timeout' => 5,
      ]);
      if ($response->getStatusCode() === 200) {
        $data = json_decode((string) $response->getBody(), TRUE);
        if (!empty($data['data']) && is_array($data['data'])) {
          return array_column($data['data'], 'id');
        }
      }
    }
    catch (\Exception $e) {
      $this->logger->warning('Failed to retrieve models from backend: @err', ['@err' => $e->getMessage()]);
    }
    return [];
  }

  /**
   * Uploads a PDF to the backend service.
   *
   * @param \Drupal\file\FileInterface $file
   *   The PDF file.
   *
   * @return array|null
   *   Upload response with jobId, or NULL on error.
   */
  public function uploadPdfForProcessing(FileInterface $file): ?array {
    $endpoint = rtrim($this->config->get('api_endpoint') ?: 'http://localhost:3000', '/');
    $real_path = $this->fileSystem->realpath($file->getFileUri());

    if (!$real_path || !file_exists($real_path)) {
      $this->logger->error('PDF file @uri could not be resolved on local disk.', ['@uri' => $file->getFileUri()]);
      return NULL;
    }

    try {
      $response = $this->httpClient->request('POST', $endpoint . '/api/upload', [
        'multipart' => [
          [
            'name' => 'pdf',
            'contents' => fopen($real_path, 'r'),
            'filename' => $file->getFilename(),
          ],
        ],
        'timeout' => 30,
      ]);

      if ($response->getStatusCode() === 200) {
        return json_decode((string) $response->getBody(), TRUE);
      }
    }
    catch (GuzzleException $e) {
      $this->logger->error('Error uploading PDF to alt-text backend: @msg', ['@msg' => $e->getMessage()]);
    }

    return NULL;
  }

  /**
   * Inspects a PDF and returns metadata on images found.
   *
   * @param \Drupal\file\FileInterface $file
   *   The PDF file.
   *
   * @return array
   *   Inspection results with images and statistics.
   */
  public function inspectPdf(FileInterface $file): array {
    $real_path = $this->fileSystem->realpath($file->getFileUri());
    if (!$real_path) {
      return [];
    }

    $python = $this->config->get('python_executable') ?: 'python3';
    $cmd = sprintf('%s -m pdf_alt_text.cli inspect %s --format json', escapeshellcmd($python), escapeshellarg($real_path));

    $output = [];
    $return_var = 0;
    @exec($cmd, $output, $return_var);

    if ($return_var === 0 && !empty($output)) {
      $raw_json = implode("\n", $output);
      $decoded = json_decode($raw_json, TRUE);
      if (is_array($decoded)) {
        return $decoded;
      }
    }

    return [];
  }

  /**
   * Generates draft alt-texts for all images in a PDF.
   *
   * @param \Drupal\file\FileInterface $file
   *   The source file.
   * @param array $options
   *   Options such as language, min_size, model.
   *
   * @return array
   *   Result array with success flag, images, and alt-texts.
   */
  public function generateDraftAltTexts(FileInterface $file, array $options = []): array {
    $real_path = $this->fileSystem->realpath($file->getFileUri());
    $language = $options['language'] ?? $this->config->get('default_language') ?? 'nl';
    $min_size = $options['min_size'] ?? $this->config->get('min_size') ?? 50;
    $api_url = $this->config->get('llm_api_url') ?: 'http://localhost:8080/v1';
    $api_key = $this->config->get('api_key') ?: '';
    $model = $options['model'] ?? $this->config->get('model') ?? '';
    $python = $this->config->get('python_executable') ?: 'python3';

    $temp_out = $this->fileSystem->tempnam('temporary://', 'pdf_alt_draft_');
    $temp_out_real = $this->fileSystem->realpath($temp_out);

    $cmd_args = [
      escapeshellcmd($python),
      '-m', 'pdf_alt_text',
      escapeshellarg($real_path),
      '-o', escapeshellarg($temp_out_real),
      '--lang', escapeshellarg($language),
      '--min-size', escapeshellarg((string) $min_size),
      '--api-url', escapeshellarg($api_url),
    ];

    if (!empty($api_key)) {
      $cmd_args[] = '--api-key ' . escapeshellarg($api_key);
    }
    if (!empty($model)) {
      $cmd_args[] = '--model ' . escapeshellarg($model);
    }

    $cmd = implode(' ', $cmd_args);
    $output = [];
    $ret = 0;
    exec($cmd . ' 2>&1', $output, $ret);

    if ($ret === 0 && file_exists($temp_out_real) && filesize($temp_out_real) > 0) {
      $inspector_cmd = sprintf('%s -m pdf_alt_text.cli inspect %s --format json', escapeshellcmd($python), escapeshellarg($temp_out_real));
      $inspect_out = [];
      exec($inspector_cmd, $inspect_out);
      $data = json_decode(implode("\n", $inspect_out), TRUE);

      @unlink($temp_out_real);

      return [
        'success' => TRUE,
        'data' => $data,
      ];
    }

    $this->logger->error('Error executing PDF alt-text generation: @out', ['@out' => implode("\n", $output)]);
    @unlink($temp_out_real);

    return [
      'success' => FALSE,
      'error' => implode("\n", $output),
    ];
  }

  /**
   * Applies approved alt-texts to a Media entity and updates the PDF.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media entity.
   * @param array $approved_alts
   *   Associative array of image_id => alt text string.
   * @param array $options
   *   Additional options.
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  public function applyAltTextsToMedia(MediaInterface $media, array $approved_alts, array $options = []): bool {
    $source_field = $media->getSource()->getSourceFieldDefinition($media->bundle());
    if (!$source_field) {
      return FALSE;
    }
    $field_name = $source_field->getName();
    /** @var \Drupal\file\FileInterface $file */
    $file = $media->get($field_name)->entity;
    if (!$file) {
      return FALSE;
    }

    $real_path = $this->fileSystem->realpath($file->getFileUri());
    $language = $options['language'] ?? $this->config->get('default_language') ?? 'nl';
    $python = $this->config->get('python_executable') ?: 'python3';

    $temp_tagged = $this->fileSystem->tempnam('temporary://', 'pdf_tagged_');
    $temp_tagged_real = $this->fileSystem->realpath($temp_tagged);

    $alts_json = json_encode($approved_alts, JSON_UNESCAPED_UNICODE);

    $cmd_args = [
      escapeshellcmd($python),
      '-m', 'pdf_alt_text.web_worker',
      escapeshellarg($real_path),
      escapeshellarg($temp_tagged_real),
      '--language', escapeshellarg($language),
      '--manual-alts-json', escapeshellarg($alts_json),
      '--overwrite',
    ];

    if ($this->config->get('create_screen_annotations') === FALSE) {
      $cmd_args[] = '--no-tooltips';
    }

    $cmd = implode(' ', $cmd_args);
    $output = [];
    $return_var = 0;
    exec($cmd . ' 2>&1', $output, $return_var);

    if ($return_var !== 0 || !file_exists($temp_tagged_real) || filesize($temp_tagged_real) === 0) {
      $this->logger->error('Error tagging PDF via web_worker: @err', ['@err' => implode("\n", $output)]);
      @unlink($temp_tagged_real);
      return FALSE;
    }

    try {
      $file_contents = file_get_contents($temp_tagged_real);
      $destination = $file->getFileUri();

      $this->fileRepository->writeData($file_contents, $destination, FileExists::Replace);

      if ($this->config->get('save_as_new_revision') && $media->getEntityType()->isRevisionable()) {
        $media->setNewRevision(TRUE);
        $media->setRevisionLogMessage('PDF enriched with accessible alt-texts (WCAG 2.1 / PDF/UA compliant).');
        $media->setRevisionCreationTime(\Drupal::time()->getRequestTime());
        $media->setRevisionUserId(\Drupal::currentUser()->id());
      }

      $media->save();
      @unlink($temp_tagged_real);
      $this->logger->info('Media @id successfully updated with tagged PDF.', ['@id' => $media->id()]);
      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to save tagged PDF in Drupal: @msg', ['@msg' => $e->getMessage()]);
      @unlink($temp_tagged_real);
      return FALSE;
    }
  }

  /**
   * Processes a Media entity end-to-end in a single batch (for Queue / CLI).
   */
  public function processMediaDirectly(MediaInterface $media, array $options = []): bool {
    $source_field = $media->getSource()->getSourceFieldDefinition($media->bundle());
    if (!$source_field) {
      return FALSE;
    }
    $field_name = $source_field->getName();
    /** @var \Drupal\file\FileInterface $file */
    $file = $media->get($field_name)->entity;
    if (!$file) {
      return FALSE;
    }

    $real_path = $this->fileSystem->realpath($file->getFileUri());
    $language = $options['language'] ?? $this->config->get('default_language') ?? 'nl';
    $api_url = $this->config->get('llm_api_url') ?: 'http://localhost:8080/v1';
    $min_size = $options['min_size'] ?? $this->config->get('min_size') ?? 50;
    $python = $this->config->get('python_executable') ?: 'python3';

    $temp_tagged = $this->fileSystem->tempnam('temporary://', 'pdf_direct_');
    $temp_tagged_real = $this->fileSystem->realpath($temp_tagged);

    $cmd_args = [
      escapeshellcmd($python),
      '-m', 'pdf_alt_text',
      escapeshellarg($real_path),
      '-o', escapeshellarg($temp_tagged_real),
      '--lang', escapeshellarg($language),
      '--min-size', escapeshellarg((string) $min_size),
      '--api-url', escapeshellarg($api_url),
      '--overwrite',
    ];

    if ($api_key = $this->config->get('api_key')) {
      $cmd_args[] = '--api-key ' . escapeshellarg($api_key);
    }
    if ($model = $this->config->get('model')) {
      $cmd_args[] = '--model ' . escapeshellarg($model);
    }

    $cmd = implode(' ', $cmd_args);
    $output = [];
    $return_var = 0;
    exec($cmd . ' 2>&1', $output, $return_var);

    if ($return_var === 0 && file_exists($temp_tagged_real) && filesize($temp_tagged_real) > 0) {
      $file_contents = file_get_contents($temp_tagged_real);
      $destination = $file->getFileUri();
      $this->fileRepository->writeData($file_contents, $destination, FileExists::Replace);

      if ($this->config->get('save_as_new_revision') && $media->getEntityType()->isRevisionable()) {
        $media->setNewRevision(TRUE);
        $media->setRevisionLogMessage('PDF automatically tagged with alt-texts via background worker.');
        $media->save();
      }
      @unlink($temp_tagged_real);
      return TRUE;
    }

    @unlink($temp_tagged_real);
    return FALSE;
  }

}
