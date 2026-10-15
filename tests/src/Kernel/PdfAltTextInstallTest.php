<?php

namespace Drupal\Tests\pdf_alt_text\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Verifies install, default config, schema, and services.
 *
 * @group pdf_alt_text
 */
class PdfAltTextInstallTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'file',
    'media',
    'pdf_alt_text',
  ];

  /**
   * Default config must install and validate against schema.
   */
  public function testInstallConfigAndServices(): void {
    $this->installConfig(['pdf_alt_text']);

    $config = $this->config('pdf_alt_text.settings');
    $this->assertNotEmpty($config->get('llm_api_url'));
    $this->assertNotEmpty($config->get('api_endpoint'));

    // Service must be instantiable from the container.
    $service = $this->container->get('pdf_alt_text.client');
    $this->assertInstanceOf('Drupal\pdf_alt_text\Service\PdfAltTextService', $service);

    // Queue and action plugins must be discoverable.
    $this->assertTrue($this->container->get('plugin.manager.queue_worker')->hasDefinition('pdf_alt_text_processor'));
    $this->assertTrue($this->container->get('plugin.manager.action')->hasDefinition('pdf_alt_text_bulk_process'));
  }

}
