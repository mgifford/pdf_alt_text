<?php

namespace Drupal\pdf_alt_text\Form;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\media\MediaInterface;
use Drupal\pdf_alt_text\Service\PdfAltTextService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Review form for editors to inspect, edit, and apply PDF alt-texts.
 */
class MediaAltTextReviewForm extends FormBase {

  /**
   * The alt-text service.
   *
   * @var \Drupal\pdf_alt_text\Service\PdfAltTextService
   */
  protected $pdfAltTextService;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new MediaAltTextReviewForm.
   */
  public function __construct(PdfAltTextService $pdf_alt_text_service, EntityTypeManagerInterface $entity_type_manager) {
    $this->pdfAltTextService = $pdf_alt_text_service;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('pdf_alt_text.client'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'pdf_alt_text_media_review_form';
  }

  /**
   * Access check for the review tab on Media entities.
   */
  public static function checkAccess(RouteMatchInterface $route_match, AccountInterface $account): AccessResultInterface {
    $media = $route_match->getParameter('media');
    if (!$media instanceof MediaInterface) {
      return AccessResult::forbidden('Media entity does not contain a PDF document.');
    }
    if (!_pdf_alt_text_media_has_pdf($media)) {
      return AccessResult::forbidden('Media entity does not contain a PDF document.')->addCacheableDependency($media);
    }
    return AccessResult::allowedIfHasPermission($account, 'use pdf alt text')->addCacheableDependency($media);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?MediaInterface $media = NULL): array {
    if (!$media instanceof MediaInterface) {
      $form['error'] = [
        '#markup' => $this->t('Invalid media entity.'),
      ];
      return $form;
    }
    $form_state->set('media_id', $media->id());

    $source_field = $media->getSource()->getSourceFieldDefinition($media->bundle());
    if (!$source_field) {
      $form['error'] = [
        '#markup' => $this->t('Media entity does not contain a PDF document.'),
      ];
      return $form;
    }
    $field_name = $source_field->getName();
    /** @var \Drupal\file\FileInterface|null $file */
    $file = $media->hasField($field_name) ? $media->get($field_name)->entity : NULL;
    if (!$file) {
      $form['error'] = [
        '#markup' => $this->t('Media entity does not contain a PDF document.'),
      ];
      return $form;
    }

    $form['intro'] = [
      '#type' => 'item',
      '#markup' => '<h2>' . $this->t('Manage PDF Alt-Texts for: @title', ['@title' => $media->label()]) . '</h2>' .
                   '<p>' . $this->t('File: <strong>@file</strong> (@size KB)', [
                     '@file' => $file->getFilename(),
                     '@size' => round($file->getSize() / 1024, 1),
                   ]) . '</p>',
    ];

    $generated_images = $form_state->get('generated_images');

    if (empty($generated_images)) {
      $inspection = $this->pdfAltTextService->inspectPdf($file);
      $images = $inspection['images'] ?? [];
      $stats = $inspection['stats'] ?? [];

      $form['info_box'] = [
        '#type' => 'item',
        '#markup' => '<div class="messages messages--status">' .
          $this->t('Found in document: <strong>@total</strong> images, of which <strong>@tagged</strong> already have alt-text and <strong>@untagged</strong> are missing alt-text.', [
            '@total' => count($images),
            '@tagged' => $stats['tagged_images'] ?? 0,
            '@untagged' => $stats['untagged_images'] ?? count($images),
          ]) . '</div>',
      ];

      if (empty($images)) {
        $form['no_images'] = [
          '#type' => 'markup',
          '#markup' => '<p>' . $this->t('No images meeting minimum size requirements were found in this PDF.') . '</p>',
        ];
        return $form;
      }

      $form['actions_top'] = [
        '#type' => 'actions',
      ];
      $form['actions_top']['generate'] = [
        '#type' => 'submit',
        '#value' => $this->t('Generate Alt-Texts via Vision AI'),
        '#submit' => ['::generateAltTextsSubmit'],
        '#button_type' => 'primary',
      ];
    }
    else {
      $form['review_intro'] = [
        '#type' => 'item',
        '#markup' => '<div class="messages messages--warning">' .
          $this->t('Review and edit the AI-generated alt-texts below. Click "Apply & Tag PDF" to compile the standard-compliant PDF/UA logical structure tree.') .
          '</div>',
      ];

      $form['images'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('Page & ID'),
          $this->t('Dimensions'),
          $this->t('Surrounding Context'),
          $this->t('Generated Alt-Text'),
          $this->t('Decorative'),
        ],
        '#empty' => $this->t('No images available.'),
      ];

      foreach ($generated_images as $idx => $img) {
        $img_id = $img['image_id'] ?? 'img_' . $idx;

        $form['images'][$img_id]['info'] = [
          '#markup' => '<strong>' . $this->t('Page @page', ['@page' => $img['page'] ?? 1]) . '</strong><br><small>' . $img_id . '</small>',
        ];

        $form['images'][$img_id]['size'] = [
          '#markup' => ($img['width'] ?? 0) . ' &times; ' . ($img['height'] ?? 0) . ' pt',
        ];

        $form['images'][$img_id]['context'] = [
          '#markup' => !empty($img['context']) ? '<em>&ldquo;' . htmlspecialchars(mb_strimwidth($img['context'], 0, 140, '...')) . '&rdquo;</em>' : '<small>' . $this->t('No context available') . '</small>',
        ];

        $form['images'][$img_id]['alt_text'] = [
          '#type' => 'textarea',
          '#rows' => 2,
          '#default_value' => $img['alt_text'] ?? '',
        ];

        $form['images'][$img_id]['decorative'] = [
          '#type' => 'checkbox',
          '#default_value' => !empty($img['decorative']),
        ];
      }

      $form['actions'] = [
        '#type' => 'actions',
      ];

      $form['actions']['apply'] = [
        '#type' => 'submit',
        '#value' => $this->t('Apply & Tag PDF (PDF/UA)'),
        '#button_type' => 'primary',
      ];

      $form['actions']['regenerate'] = [
        '#type' => 'submit',
        '#value' => $this->t('Regenerate'),
        '#submit' => ['::generateAltTextsSubmit'],
      ];
    }

    return $form;
  }

  /**
   * Submit handler to run AI alt-text generation.
   */
  public function generateAltTextsSubmit(array &$form, FormStateInterface $form_state): void {
    $media_id = $form_state->get('media_id');
    /** @var \Drupal\media\MediaInterface|null $media */
    $media = $this->entityTypeManager->getStorage('media')->load($media_id);
    if (!$media instanceof MediaInterface) {
      $this->messenger()->addError($this->t('Media entity could not be loaded.'));
      return;
    }

    $source_field = $media->getSource()->getSourceFieldDefinition($media->bundle());
    if (!$source_field) {
      $this->messenger()->addError($this->t('Media entity does not contain a PDF document.'));
      return;
    }
    /** @var \Drupal\file\FileInterface|null $file */
    $file = $media->get($source_field->getName())->entity;
    if (!$file) {
      $this->messenger()->addError($this->t('Media entity does not contain a PDF document.'));
      return;
    }

    $this->messenger()->addStatus($this->t('Analyzing document and generating alt-texts using Vision LLM...'));

    $result = $this->pdfAltTextService->generateDraftAltTexts($file);

    if ($result['success'] && !empty($result['data']['images'])) {
      $form_state->set('generated_images', $result['data']['images']);
      $form_state->setRebuild(TRUE);
      $this->messenger()->addStatus($this->t('Alt-texts successfully generated! Review them below.'));
    }
    else {
      $this->messenger()->addError($this->t('An error occurred during generation: @err', [
        '@err' => $result['error'] ?? $this->t('Unknown error.'),
      ]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $media_id = $form_state->get('media_id');
    /** @var \Drupal\media\MediaInterface|null $media */
    $media = $this->entityTypeManager->getStorage('media')->load($media_id);
    if (!$media instanceof MediaInterface) {
      $this->messenger()->addError($this->t('Media entity could not be loaded.'));
      return;
    }

    $images_input = $form_state->getValue('images');
    $approved_alts = [];

    if (is_array($images_input)) {
      foreach ($images_input as $img_id => $data) {
        if (!empty($data['decorative'])) {
          $approved_alts[$img_id] = '';
        }
        else {
          $approved_alts[$img_id] = trim($data['alt_text'] ?? '');
        }
      }
    }

    $success = $this->pdfAltTextService->applyAltTextsToMedia($media, $approved_alts);

    if ($success) {
      $this->messenger()->addStatus($this->t('The PDF was successfully updated with PDF/UA logical structure tree and accessible alt-texts!'));
    }
    else {
      $this->messenger()->addError($this->t('An error occurred while tagging and saving the PDF document.'));
    }
  }

}
