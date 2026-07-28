<?php

declare(strict_types=1);

namespace Talaria\Tests;

use PHPUnit\Framework\TestCase;
use Talaria\Protocol\ExceptionPayloadBuilder;
use Talaria\Protocol\StackFrameBuilder;

final class ExceptionPayloadTest extends TestCase
{
    public function testFramesOldestToNewestWithFunctionName(): void
    {
        // PHP getTrace() order: newest first.
        $newestFirst = [
            [
                'file' => '/app/src/Checkout/PaymentService.php',
                'line' => 88,
                'function' => 'charge',
                'class' => 'App\\Checkout\\PaymentService',
                'type' => '::',
            ],
            [
                'file' => '/app/src/index.php',
                'line' => 10,
                'function' => 'main',
            ],
        ];

        $frames = StackFrameBuilder::framesFromTrace(
            $newestFirst,
            '/app/src/Checkout/PaymentService.php',
            88,
        );

        self::assertCount(2, $frames);
        self::assertSame('main', $frames[0]['functionName']);
        self::assertSame('App\\Checkout\\PaymentService::charge', $frames[1]['functionName']);
        self::assertSame('PaymentService.php', $frames[1]['filename']);
        self::assertSame('/app/src/Checkout/PaymentService.php', $frames[1]['absPath']);
        self::assertArrayNotHasKey('function', $frames[0]);
        self::assertArrayNotHasKey('function', $frames[1]);
        self::assertSame('php', $frames[0]['platform']);
        self::assertSame('StackFrameDto', $frames[0]['__className__']);
    }

    public function testVendorPathIsNotInApp(): void
    {
        self::assertFalse(StackFrameBuilder::isInApp('/var/www/vendor/foo/bar.php'));
        self::assertTrue(StackFrameBuilder::isInApp('/var/www/app/src/Checkout.php'));

        $vendorFrame = StackFrameBuilder::fromTraceFrame([
            'file' => '/project/vendor/guzzlehttp/guzzle/src/Client.php',
            'line' => 1,
            'function' => 'request',
            'class' => 'GuzzleHttp\\Client',
            'type' => '->',
        ]);
        self::assertFalse($vendorFrame['inApp']);
        self::assertSame('GuzzleHttp\\Client->request', $vendorFrame['functionName']);

        $appFrame = StackFrameBuilder::fromTraceFrame([
            'file' => '/project/src/App.php',
            'line' => 2,
            'function' => 'run',
        ]);
        self::assertTrue($appFrame['inApp']);
    }

    public function testEmptyTraceStillGetsThrowSiteFrame(): void
    {
        $frames = StackFrameBuilder::framesFromTrace([], '/app/ThrowSite.php', 42);

        self::assertCount(1, $frames);
        self::assertSame('ThrowSite.php', $frames[0]['filename']);
        self::assertSame('/app/ThrowSite.php', $frames[0]['absPath']);
        self::assertSame(42, $frames[0]['lineno']);
        self::assertTrue($frames[0]['inApp']);
        self::assertSame('StackFrameDto', $frames[0]['__className__']);
    }

    public function testThrowSiteNotDuplicatedWhenAlreadyLastFrame(): void
    {
        $frames = StackFrameBuilder::framesFromTrace(
            [
                [
                    'file' => '/app/Same.php',
                    'line' => 10,
                    'function' => 'fail',
                ],
            ],
            '/app/Same.php',
            10,
        );

        self::assertCount(1, $frames);
        self::assertSame('fail', $frames[0]['functionName']);
    }

    public function testChainedExceptionsOldestFirst(): void
    {
        $root = new \RuntimeException('root cause', 1);
        $mid = new \InvalidArgumentException('middle', 2, $root);
        $top = new \LogicException('top', 3, $mid);

        $payload = ExceptionPayloadBuilder::fromThrowable($top);

        self::assertSame('ExceptionDataDto', $payload['__className__']);
        self::assertCount(3, $payload['values']);

        self::assertSame(\RuntimeException::class, $payload['values'][0]['type']);
        self::assertSame('root cause', $payload['values'][0]['value']);
        self::assertSame('1', $payload['values'][0]['code']);

        self::assertSame(\InvalidArgumentException::class, $payload['values'][1]['type']);
        self::assertSame(\LogicException::class, $payload['values'][2]['type']);
        self::assertSame('top', $payload['values'][2]['value']);

        foreach ($payload['values'] as $value) {
            self::assertSame('ExceptionValueDto', $value['__className__']);
            self::assertSame('ExceptionMechanismDto', $value['mechanism']['__className__']);
            self::assertSame('generic', $value['mechanism']['type']);
            self::assertTrue($value['mechanism']['handled']);
            self::assertFalse($value['mechanism']['synthetic']);
            self::assertSame('StackTraceDto', $value['stacktrace']['__className__']);
            self::assertIsArray($value['stacktrace']['frames']);
        }
    }

    public function testMechanismOverrideUnhandled(): void
    {
        $payload = ExceptionPayloadBuilder::fromThrowable(
            new \RuntimeException('x'),
            ['type' => 'generic', 'handled' => false, 'synthetic' => false],
        );

        self::assertFalse($payload['values'][0]['mechanism']['handled']);
    }

    public function testShortNameStripsNamespace(): void
    {
        self::assertSame(
            'RuntimeException',
            ExceptionPayloadBuilder::shortName(new \RuntimeException('x')),
        );

        $namespaced = new class ('x') extends \RuntimeException {
        };
        // Anonymous class short name is the trailing segment after the last '\'.
        $short = ExceptionPayloadBuilder::shortName($namespaced);
        self::assertStringContainsString('ExceptionPayloadTest', $short);
        self::assertStringNotContainsString('\\', $short);
    }

    public function testErrorThrowableSupported(): void
    {
        $payload = ExceptionPayloadBuilder::fromThrowable(new \Error('fatalish'));
        self::assertSame(\Error::class, $payload['values'][0]['type']);
        self::assertSame('fatalish', $payload['values'][0]['value']);
    }

    public function testFramesFromThrowableIncludesThrowSite(): void
    {
        $exception = null;
        try {
            throw new \RuntimeException('live');
        } catch (\RuntimeException $e) {
            $exception = $e;
        }

        self::assertNotNull($exception);
        $frames = StackFrameBuilder::framesFromThrowable($exception);
        self::assertNotEmpty($frames);
        $last = $frames[array_key_last($frames)];
        self::assertSame($exception->getFile(), $last['absPath']);
        self::assertSame($exception->getLine(), $last['lineno']);
        self::assertSame('php', $last['platform']);
    }
}
