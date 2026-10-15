<?php

namespace Drupal\Tests\pdf_alt_text\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Smoke tests for settings and review routes.
 *
 * @group pdf_alt_text
 */
class PdfAltTextAccessTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'media',
    'file',
    'pdf_alt_text',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Settings form requires the administer permission.
   */
  public function testSettingsAccess(): void {
    $admin = $this->drupalCreateUser(['administer pdf alt text']);
    $this->drupalLogin($admin);
    $this->drupalGet('/admin/config/media/pdf-alt-text');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldExists('llm_api_url');
  }

  /**
   * Anonymous users are denied the settings form.
   */
  public function testSettingsDeniedAnonymous(): void {
    $this->drupalGet('/admin/config/media/pdf-alt-text');
    $this->assertSession()->statusCodeEquals(403);
  }

}
