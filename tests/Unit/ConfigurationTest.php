<?php

declare(strict_types=1);

namespace Api2Convert\Tests\Unit;

use Api2Convert\Api2Convert;
use Api2Convert\Exception\Api2ConvertException;
use Api2Convert\Exception\ConfigurationException;
use PHPUnit\Framework\TestCase;

final class ConfigurationTest extends TestCase
{
    private string|false $envBackup;

    protected function setUp(): void
    {
        // Ensure the env fallback can't satisfy the empty-key check during this test.
        $this->envBackup = getenv('API2CONVERT_API_KEY');
        putenv('API2CONVERT_API_KEY');
    }

    protected function tearDown(): void
    {
        if ($this->envBackup === false) {
            putenv('API2CONVERT_API_KEY');
        } else {
            putenv('API2CONVERT_API_KEY=' . $this->envBackup);
        }
    }

    public function testEmptyApiKeyThrowsConfigurationException(): void
    {
        $this->expectException(ConfigurationException::class);
        new Api2Convert('');
    }

    public function testEmptyApiKeyIsCaughtByTheSdkBaseException(): void
    {
        // A single catch around the SDK's base exception must cover a config error.
        try {
            new Api2Convert('');
            self::fail('Expected a ConfigurationException');
        } catch (Api2ConvertException $e) {
            self::assertInstanceOf(ConfigurationException::class, $e);
        }
    }
}
