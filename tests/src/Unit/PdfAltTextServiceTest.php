<?php

namespace Drupal\Tests\pdf_alt_text\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\pdf_alt_text\Service\PdfAltTextService;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

/**
 * Unit tests for PdfAltTextService health and model discovery.
 *
 * @group pdf_alt_text
 * @coversDefaultClass \Drupal\pdf_alt_text\Service\PdfAltTextService
 */
class PdfAltTextServiceTest extends UnitTestCase {

  /**
   * Builds the service with mocked dependencies.
   */
  protected function buildService(array $settings, ClientInterface $http_client): PdfAltTextService {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(function ($key) use ($settings) {
      return $settings[$key] ?? NULL;
    });

    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')->with('pdf_alt_text.settings')->willReturn($config);

    $file_system = $this->createMock(FileSystemInterface::class);
    $file_repository = $this->createMock(FileRepositoryInterface::class);
    $entity_type_manager = $this->createMock(EntityTypeManagerInterface::class);

    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')->with('pdf_alt_text')->willReturn($logger);

    return new PdfAltTextService(
      $config_factory,
      $http_client,
      $file_system,
      $file_repository,
      $entity_type_manager,
      $logger_factory
    );
  }

  /**
   * API health check reports online on HTTP 200.
   */
  public function testCheckHealthApiOnline(): void {
    $http = $this->createMock(ClientInterface::class);
    $http->method('request')->willReturn(new Response(200, [], '{"status":"ok"}'));

    $service = $this->buildService([
      'processing_mode' => 'api',
      'api_endpoint' => 'http://localhost:3000',
    ], $http);

    $health = $service->checkHealth();
    $this->assertSame('online', $health['status']);
    $this->assertSame('api', $health['mode']);
  }

  /**
   * API health check reports offline when the backend is unreachable.
   */
  public function testCheckHealthApiOffline(): void {
    $http = $this->createMock(ClientInterface::class);
    $http->method('request')->willThrowException(
      new RequestException('Connection refused', new Request('GET', 'http://localhost:3000/api/health'))
    );

    $service = $this->buildService([
      'processing_mode' => 'api',
      'api_endpoint' => 'http://localhost:3000',
    ], $http);

    $health = $service->checkHealth();
    $this->assertSame('offline', $health['status']);
    $this->assertSame('api', $health['mode']);
  }

  /**
   * Model discovery extracts IDs from an OpenAI-style payload.
   */
  public function testGetAvailableModels(): void {
    $http = $this->createMock(ClientInterface::class);
    $http->method('request')->willReturn(new Response(
      200,
      [],
      '{"data":[{"id":"qwen2.5-vl"},{"id":"gemma-4"}]}'
    ));

    $service = $this->buildService([
      'api_endpoint' => 'http://localhost:3000',
    ], $http);

    $this->assertSame(['qwen2.5-vl', 'gemma-4'], $service->getAvailableModels());
  }

  /**
   * Model discovery returns an empty list on transport failure.
   */
  public function testGetAvailableModelsFailure(): void {
    $http = $this->createMock(ClientInterface::class);
    $http->method('request')->willThrowException(
      new RequestException('Down', new Request('GET', 'http://localhost:3000/api/models'))
    );

    $service = $this->buildService([], $http);

    $this->assertSame([], $service->getAvailableModels());
  }

}
