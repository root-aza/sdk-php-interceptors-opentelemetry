<?php

declare(strict_types=1);

namespace Temporal\OpenTelemetry\Interceptor;

use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Trace\SpanKind;
use React\Promise\PromiseInterface;
use Temporal\Interceptor\WorkflowOutboundRequestInterceptor;
use Temporal\OpenTelemetry\Enum\RequestAttribute;
use Temporal\OpenTelemetry\Enum\SpanName;
use Temporal\OpenTelemetry\Enum\WorkflowAttribute;
use Temporal\OpenTelemetry\Tracer;
use Temporal\OpenTelemetry\TracerContext;
use Temporal\Worker\Transport\Command\RequestInterface;
use Temporal\Workflow;

/**
 * Interceptor for workflow outbound requests with OpenTelemetry tracing.
 *
 * Creates spans for outbound requests from workflows, capturing request type, name, ID, and workflow type.
 * Traces are only created when not in replay mode to prevent duplicate spans.
 *
 * @note This interceptor operates in blocking mode when sending telemetry, which may impact
 * Workflow Worker bandwidth. Using a local collector is recommended to minimize network latency impact.
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
 *  $interceptor = new OpenTelemetryWorkflowOutboundRequestInterceptor($tracer);
 *  $worker = WorkerFactory::create(pipelineProvider: new SimplePipelineProvider([$interceptor]));
 * ```
 */
final class OpenTelemetryWorkflowOutboundRequestInterceptor implements WorkflowOutboundRequestInterceptor
{
    use TracerContext;

    /**
     * @param Tracer $tracer The tracer instance to use for outbound request spans
     */
    public function __construct(
        private readonly Tracer $tracer,
    ) {}

    /**
     * Handles workflow outbound requests with OpenTelemetry tracing.
     *
     * Creates a span for the outbound request if not in replay mode and tracer context exists.
     * The span is created with SERVER kind to represent an outbound workflow request.
     *
     * This includes spans for activities execution, child workflows, timers, signals to external workflows,
     * and other outbound operations.
     *
     * @param RequestInterface $request The outbound request containing headers
     * @param callable $next The next handler in the interceptor chain
     * @return PromiseInterface The promise that will resolve to the request result
     * @throws \Throwable If an error occurs during the request processing
     */
    #[\Override]
    public function handleOutboundRequest(RequestInterface $request, callable $next): PromiseInterface
    {
        $header = $request->getHeader();
        if ($header->getValue($this->getTracerHeader(), 'array') === null || Workflow::isReplaying()) {
            return $next($request);
        }

        $tracer = $this->getTracerWithContext($header);

        /** @var PromiseInterface $result */
        $result = $next($request);

        return $result->then(
            fn(mixed $value): mixed => $this->trace($tracer, $request, static fn(): mixed => $value),
            fn(\Throwable $error): mixed => $this->trace($tracer, $request, static function () use ($error): never {
                throw $error;
            }),
        );
    }

    /**
     * Creates a trace span for an outbound request.
     *
     * Captures the request type, name, ID, and workflow type as span attributes.
     *
     * @param Tracer $tracer The configured tracer instance with context
     * @param RequestInterface $request The outbound request
     * @param \Closure $handler The handler to execute within the trace span
     * @return mixed The result of the handler execution
     * @throws \Throwable If the handler throws an exception
     */
    private function trace(Tracer $tracer, RequestInterface $request, \Closure $handler): mixed
    {
        $now = Clock::getDefault()->now();
        $type = Workflow::getInfo()->type;

        return $tracer->trace(
            name: SpanName::WorkflowOutboundRequest->value . SpanName::SpanDelimiter->value . $request->getName(),
            callback: $handler,
            attributes: [
                RequestAttribute::Type->value => $request::class,
                RequestAttribute::Name->value => $request->getName(),
                RequestAttribute::Id->value => $request->getID(),
                WorkflowAttribute::Type->value => $type->name,
            ],
            scoped: true,
            spanKind: SpanKind::KIND_SERVER,
            startTime: $now,
        );
    }
}
