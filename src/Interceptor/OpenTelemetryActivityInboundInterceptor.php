<?php

declare(strict_types=1);

namespace Temporal\OpenTelemetry\Interceptor;

use OpenTelemetry\API\Trace\SpanKind;
use Temporal\Activity;
use Temporal\Interceptor\ActivityInbound\ActivityInput;
use Temporal\Interceptor\ActivityInboundInterceptor;
use Temporal\Interceptor\Trait\ActivityInboundInterceptorTrait;
use Temporal\OpenTelemetry\Enum\ActivityAttribute;
use Temporal\OpenTelemetry\Enum\SpanName;
use Temporal\OpenTelemetry\Tracer;
use Temporal\OpenTelemetry\TracerContext;

/**
 * Interceptor for activity inbound requests with OpenTelemetry tracing.
 *
 * Creates spans for activity executions and collects relevant attributes like activity ID, type,
 * workflow type, and other contextual information.
 *
 * Usage example:
 * ```php
 *  // Create a tracer
 *  $tracerProvider = new Trace\TracerProvider($spanProcessor);
 *  $tracer = new Temporal\OpenTelemetry\Tracer(
 *      $tracerProvider->getTracer('MyApp'),
 *      TraceContextPropagator::getInstance()
 *  );
 *
 *  // Create and register the interceptor
 *  $interceptor = new OpenTelemetryActivityInboundInterceptor($tracer);
 *  $worker = WorkerFactory::create(pipelineProvider: new SimplePipelineProvider([$interceptor]));
 * ```
 */
final class OpenTelemetryActivityInboundInterceptor implements ActivityInboundInterceptor
{
    use ActivityInboundInterceptorTrait;
    use TracerContext;

    /**
     * @param Tracer $tracer The tracer instance to use for activity span creation
     */
    public function __construct(
        private readonly Tracer $tracer,
    ) {}

    /**
     * Handles activity execution with OpenTelemetry tracing.
     *
     * Creates a span for the activity execution with relevant attributes and proper context propagation.
     * The span is created with SERVER kind to represent an inbound activity request.
     *
     * @param ActivityInput $input The activity input with headers containing tracing context
     * @param callable $next The next handler in the interceptor chain
     * @return mixed The result of the activity execution
     */
    #[\Override]
    public function handleActivityInbound(ActivityInput $input, callable $next): mixed
    {
        return $this->getTracerWithContext($input->header)->trace(
            name: SpanName::ActivityHandle->value,
            callback: static fn(): mixed => $next($input),
            attributes: [
                ActivityAttribute::Id->value => Activity::getInfo()->id,
                ActivityAttribute::Attempt->value => Activity::getInfo()->attempt,
                ActivityAttribute::Type->value => Activity::getInfo()->type->name,
                ActivityAttribute::TaskQueue->value => Activity::getInfo()->taskQueue,
                ActivityAttribute::WorkflowType->value => Activity::getInfo()->workflowType?->name,
                ActivityAttribute::WorkflowNamespace->value => Activity::getInfo()->workflowNamespace,
                ActivityAttribute::Header->value => \iterator_to_array($input->header->getIterator()),
            ],
            scoped: true,
            spanKind: SpanKind::KIND_SERVER,
        );
    }
}
