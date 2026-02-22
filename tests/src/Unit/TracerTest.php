<?php

declare(strict_types=1);

namespace Temporal\OpenTelemetry\Tests\Unit;

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\SDK\Common\Future\CancellationInterface;
use OpenTelemetry\SDK\Trace\ReadableSpanInterface;
use OpenTelemetry\SDK\Trace\ReadWriteSpanInterface;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use Temporal\OpenTelemetry\Tracer;

final class TracerTest extends TestCase
{
    public static function contextDataProvider(): \Traversable
    {
        yield [[], []];
        yield [['foo' => 'bar'], []];
        yield [['traceparent' => 'foo', 'tracestate' => 'bar'], ['traceparent' => 'foo', 'tracestate' => 'bar']];
        yield [
            ['traceparent' => 'foo', 'tracestate' => 'bar', 'shouldBeRemoved' => 'value'],
            ['traceparent' => 'foo', 'tracestate' => 'bar'],
        ];
    }

    public static function normalizeAttributesProvider(): iterable
    {
        yield [['test' => new \stdClass()], ['test' => '{}']];
        yield [['test' => new Foo()], ['test' => 'Temporal\OpenTelemetry\Tests\Unit\Foo']];
        yield [['test' => new class implements \Stringable {
            public function __toString(): string
            {
                return 'foo';
            }
        }], ['test' => 'foo']];

        yield [['test' => \fopen('php://stdout', 'r')], ['test' => 'resource (stream)']];

        yield [['test' => new class implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return ['foo' => 'bar'];
            }
        }], ['test' => '{"foo":"bar"}']];


        yield [['test' => [
            new class implements \Stringable {
                public function __toString(): string
                {
                    return 'foo';
                }
            },
            new class implements \JsonSerializable {
                public function jsonSerialize(): mixed
                {
                    return ['foo' => 'bar'];
                }
            },
            new Foo(),
        ]], ['test' => ['foo', '{"foo":"bar"}', 'Temporal\OpenTelemetry\Tests\Unit\Foo']]];
    }

    #[DataProvider('contextDataProvider')]
    public function testFromContext(array $context, array $expected): void
    {
        $tracer = new Tracer($this->createMock(TracerInterface::class), new TraceContextPropagator());

        $this->assertSame($expected, $tracer->fromContext($context)->getContext());
    }

    public function testTrace(): void
    {
        $tracer = $this->configureTracer();

        $this->assertSame(
            $this->span,
            $tracer->trace('foo', static fn(SpanInterface $span): SpanInterface => $span),
        );
    }

    public function testTraceWithAttributes(): void
    {
        $tracer = $this->configureTracer(attributes: ['foo' => 'bar']);

        $this->assertSame(
            $this->span,
            $tracer->trace(
                name: 'foo',
                callback: static fn(SpanInterface $span): SpanInterface => $span,
                attributes: ['foo' => 'bar'],
            ),
        );
    }

    public function testTraceScoped(): void
    {
        $tracer = $this->configureTracer(scoped: true);

        $this->assertSame(
            $this->span,
            $tracer->trace(
                name: 'foo',
                callback: static fn(SpanInterface $span): SpanInterface => $span,
                scoped: true,
            ),
        );
    }

    public function testTraceWithSpanKind(): void
    {
        $tracer = $this->configureTracer(spanKind: 5);

        $this->assertSame(
            $this->span,
            $tracer->trace(
                name: 'foo',
                callback: static fn(SpanInterface $span): SpanInterface => $span,
                spanKind: 5,
            ),
        );
    }

    public function testTraceWithStartTime(): void
    {
        $tracer = $this->configureTracer(startTime: 10);

        $this->assertSame(
            $this->span,
            $tracer->trace(
                name: 'foo',
                callback: static fn(SpanInterface $span): SpanInterface => $span,
                startTime: 10,
            ),
        );
    }

    public function testTraceWithException(): void
    {
        $span = $this->createMock(SpanInterface::class);
        $span
            ->expects($this->never())
            ->method('activate');
        $span
            ->expects($this->never())
            ->method('updateName');
        $span
            ->expects($this->never())
            ->method('setAttributes');
        $span
            ->expects($this->once())
            ->method('end');

        $spanBuilder = $this->createMock(SpanBuilderInterface::class);
        $spanBuilder
            ->expects($this->never())
            ->method('setSpanKind');
        $spanBuilder
            ->expects($this->never())
            ->method('setStartTimestamp');
        $spanBuilder
            ->expects($this->never())
            ->method('setParent');
        $spanBuilder
            ->expects($this->once())
            ->method('startSpan')
            ->willReturn($span);

        $otelTracer = $this->createMock(TracerInterface::class);
        $otelTracer
            ->expects($this->once())
            ->method('spanBuilder')
            ->with('foo')
            ->willReturn($spanBuilder);

        $tracer = new Tracer($otelTracer, new TraceContextPropagator());

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Some error');
        $tracer->trace(
            name: 'foo',
            callback: static fn(SpanInterface $span): SpanInterface => throw new \Exception('Some error'),
        );
    }

    public function testGetPropagator(): void
    {
        $propagator = new TraceContextPropagator();
        $tracer = new Tracer($this->createMock(TracerInterface::class), $propagator);

        $this->assertSame($propagator, $tracer->getPropagator());
    }

    /**
     * @param array{} $attributes
     * @param array{} $normalizedAttributes
     *
     * @throws \Throwable
     */
    #[DataProvider('normalizeAttributesProvider')]
    public function testNormalizeAttributes(array $attributes, array $normalizedAttributes): void
    {
        $actualAttributes = [];

        $tracerProvider = TracerProvider::builder()
            ->addSpanProcessor(
                new class($actualAttributes) implements SpanProcessorInterface {
                    /**
                     * @param array<never, never> $actualAttributes
                     */
                    public function __construct(
                        private array &$actualAttributes,
                    ) {}

                    #[\Override]
                    public function onStart(ReadWriteSpanInterface $span, ContextInterface $parentContext): void {}

                    #[\Override]
                    public function onEnd(ReadableSpanInterface $span): void
                    {
                        $this->actualAttributes = $span->toSpanData()->getAttributes()->toArray();
                    }

                    #[\Override]
                    public function forceFlush(?CancellationInterface $cancellation = null): bool
                    {
                        return true;
                    }

                    #[\Override]
                    public function shutdown(?CancellationInterface $cancellation = null): bool
                    {
                        return true;
                    }
                },
            )
            ->build()
        ;

        $tracer = new Tracer($tracerProvider->getTracer('temporal'), TraceContextPropagator::getInstance());

        $tracer->trace(
            name: 'foo',
            callback: static fn(SpanInterface $span): SpanInterface => $span,
            attributes: $attributes,
        );

        $tracerProvider->forceFlush();

        $this->assertEquals($normalizedAttributes, $actualAttributes);
    }
}


final class Foo {}
