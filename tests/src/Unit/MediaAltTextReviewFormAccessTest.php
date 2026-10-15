<?php

namespace Drupal\Tests\pdf_alt_text\Unit;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\media\MediaInterface;
use Drupal\media\MediaSourceInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\file\FileInterface;
use Drupal\pdf_alt_text\Form\MediaAltTextReviewForm;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for the review form access check.
 *
 * @group pdf_alt_text
 * @coversClass \Drupal\pdf_alt_text\Form\MediaAltTextReviewForm
 */
class MediaAltTextReviewFormAccessTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The helper lives in pdf_alt_text.module.
    require_once dirname(__DIR__, 4) . '/pdf_alt_text.module';
    $container = new ContainerBuilder();
    \Drupal::setContainer($container);
  }

  /**
   * Builds a media mock wrapping the given file (or no file).
   */
  protected function buildMedia(?FileInterface $file): MediaInterface {
    $media = $this->createMock(MediaInterface::class);

    $field_definition = $this->createMock(FieldDefinitionInterface::class);
    $field_definition->method('getName')->willReturn('field_media_document');

    $source = $this->createMock(MediaSourceInterface::class);
    $source->method('getSourceFieldDefinition')->willReturn($field_definition);

    $media->method('getSource')->willReturn($source);
    $media->method('bundle')->willReturn('document');
    $media->method('hasField')->willReturn(TRUE);

    $items = $this->createMock(FieldItemListInterface::class);
    $items->method('isEmpty')->willReturn($file === NULL);
    // Entity reference field: ->entity magic property.
    $items->entity = $file;
    $media->method('get')->willReturn($items);

    return $media;
  }

  /**
   * Builds a PDF file mock.
   */
  protected function buildPdfFile(): FileInterface {
    $file = $this->createMock(FileInterface::class);
    $file->method('getMimeType')->willReturn('application/pdf');
    $file->method('getFilename')->willReturn('example.pdf');
    return $file;
  }

  /**
   * Non-PDF media must be forbidden (and cacheable on the entity).
   */
  public function testNonPdfForbidden(): void {
    $text_file = $this->createMock(FileInterface::class);
    $text_file->method('getMimeType')->willReturn('text/plain');
    $text_file->method('getFilename')->willReturn('notes.txt');

    $route_match = $this->createMock(RouteMatchInterface::class);
    $route_match->method('getParameter')->with('media')->willReturn($this->buildMedia($text_file));
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->with('use pdf alt text')->willReturn(TRUE);

    $result = MediaAltTextReviewForm::checkAccess($route_match, $account);
    $this->assertInstanceOf(AccessResultInterface::class, $result);
    $this->assertTrue($result->isForbidden());
  }

  /**
   * Missing route parameter must be forbidden, not fatal.
   */
  public function testMissingMediaForbidden(): void {
    $route_match = $this->createMock(RouteMatchInterface::class);
    $route_match->method('getParameter')->with('media')->willReturn(NULL);
    $account = $this->createMock(AccountInterface::class);

    $result = MediaAltTextReviewForm::checkAccess($route_match, $account);
    $this->assertTrue($result->isForbidden());
  }

  /**
   * PDF media + permission must be allowed.
   */
  public function testPdfAllowed(): void {
    $route_match = $this->createMock(RouteMatchInterface::class);
    $route_match->method('getParameter')->with('media')->willReturn($this->buildMedia($this->buildPdfFile()));
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->with('use pdf alt text')->willReturn(TRUE);
    // Anonymous/stub account needs an id for allowedIfHasPermission cacheability.
    $account->method('id')->willReturn(1);
    $account->method('getRoles')->willReturn(['authenticated']);

    $result = MediaAltTextReviewForm::checkAccess($route_match, $account);
    $this->assertTrue($result->isAllowed());
  }

  /**
   * PDF media without permission must be forbidden.
   */
  public function testPdfWithoutPermissionForbidden(): void {
    $route_match = $this->createMock(RouteMatchInterface::class);
    $route_match->method('getParameter')->with('media')->willReturn($this->buildMedia($this->buildPdfFile()));
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->with('use pdf alt text')->willReturn(FALSE);
    $account->method('id')->willReturn(0);
    $account->method('getRoles')->willReturn(['anonymous']);

    $result = MediaAltTextReviewForm::checkAccess($route_match, $account);
    $this->assertTrue($result->isForbidden());
  }

}
