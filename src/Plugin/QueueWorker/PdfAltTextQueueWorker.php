<?php

namespace Drupal\pdf_alt_text\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\media\MediaInterface;
use Drupal\pdf_alt_text\Service\PdfAltTextService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes PDF media entities in the background.
 *
 * @QueueWorker(
 *   id = "pdf_alt_text_processor",
 *   title = @Translation("PDF Alt-Text Background Processor"),
 *   cron = {"time" = 60}
 * )
 */
#[QueueWorker(
  id: 'pdf_alt_text_processor',
  title: new TranslatableMarkup('PDF Alt-Text Background Processor'),
  cron: ['time' => 60]
)]
class PdfAltTextQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The alt-text service.
   *
   * @var \Drupal\pdf_alt_text\Service\PdfAltTextService
   */
  protected $pdfAltTextService;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->pdfAltTextService = $container->get('pdf_alt_text.client');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    if (empty($data['media_id'])) {
      return;
    }

    /** @var \Drupal\media\MediaInterface|null $media */
    $media = $this->entityTypeManager->getStorage('media')->load($data['media_id']);
    if ($media instanceof MediaInterface) {
      $this->pdfAltTextService->processMediaDirectly($media, $data['options'] ?? []);
    }
  }

}
