<?php

namespace Drupal\pdf_alt_text\Plugin\Action;

use Drupal\Core\Action\ActionBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Session\AccountInterface;
use Drupal\media\MediaInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Bulk action to enqueue PDF media entities for alt-text processing.
 *
 * @Action(
 *   id = "pdf_alt_text_bulk_process",
 *   label = @Translation("Generate PDF Alt-Texts (Background Queue)"),
 *   type = "media"
 * )
 */
class GeneratePdfAltTextAction extends ActionBase implements ContainerFactoryPluginInterface {

  /**
   * The queue factory.
   *
   * @var \Drupal\Core\Queue\QueueInterface
   */
  protected $queue;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    /** @var \Drupal\Core\Queue\QueueFactory $queue_factory */
    $queue_factory = $container->get('queue');
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->queue = $queue_factory->get('pdf_alt_text_processor');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {
    if ($entity instanceof MediaInterface && _pdf_alt_text_media_has_pdf($entity)) {
      $this->queue->createItem([
        'media_id' => $entity->id(),
        'options' => [],
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    /** @var \Drupal\media\MediaInterface $object */
    $access = $object->access('update', $account, TRUE)
      ->andIf(\Drupal\Core\Access\AccessResult::allowedIfHasPermission($account, 'use pdf alt text'));

    return $return_as_object ? $access : $access->isAllowed();
  }

}
