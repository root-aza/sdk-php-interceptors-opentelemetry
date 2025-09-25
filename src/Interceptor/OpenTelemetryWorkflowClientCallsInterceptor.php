<?php

declare(strict_types=1);

namespace Temporal\OpenTelemetry\Interceptor;

use OpenTelemetry\API\Trace\SpanKind;
use Temporal\Interceptor\Trait\WorkflowClientCallsInterceptorTrait;
use Temporal\Interceptor\WorkflowClient\SignalWithStartInput;
use Temporal\Interceptor\WorkflowClient\StartInput;
use Temporal\Interceptor\WorkflowClient\UpdateWithStartInput;
use Temporal\Interceptor\WorkflowClient\UpdateWithStartOutput;
use Temporal\Interceptor\WorkflowClientCallsInterceptor;
use Temporal\OpenTelemetry\Enum\SpanName;
use Temporal\OpenTelemetry\Enum\WorkflowAttribute;
use Temporal\OpenTelemetry\Tracer;
use Temporal\OpenTelemetry\TracerContext;
use Temporal\Workflow\WorkflowExecution;

/**
 * Interceptor for workflow client calls with OpenTelemetry tracing.
 *
 * Creates spans for workflow operations like start, signalWithStart, and updateWithStart,
 * collecting relevant workflow attributes and propagating the tracing context.
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
 *  // Create and register the interceptor with workflow client
 *  $interceptor = new OpenTelemetryWorkflowClientCallsInterceptor($tracer);
 *  $client = WorkflowClient::create(
 *      interceptorProvider: new SimplePipelineProvider([$interceptor])
 *  );
 * ```
 */
final class OpenTelemetryWorkflowClientCallsInterceptor implements WorkflowClientCallsInterceptor
{
    use WorkflowClientCallsInterceptorTrait;
    use TracerContext;

    /**
     * @param Tracer $tracer The tracer instance to use for workflow operation spans
     */
    public function __construct(
        private readonly Tracer $tracer,
    ) {}

    /**
     * Traces workflow start operations.
     *
     * Creates a span for the workflow start operation with relevant attributes
     * and propagates the tracing context through the header.
     *
     * @param StartInput $input The workflow start input with headers
     * @param callable $next The next handler in the interceptor chain
     * @return WorkflowExecution The resulting workflow execution
     * @throws \Throwable If an error occurs during operation
     */
    #[\Override]
    public function start(StartInput $input, callable $next): WorkflowExecution
    {
        $tracer = $this->getTracerWithContext($input->header);

        return $tracer->trace(
            name: SpanName::StartWorkflow->value . SpanName::SpanDelimiter->value . $input->workflowType,
            callback: fn(): mixed => $next(
                $input->with(
                    header: $this->setContext($input->header, $this->tracer->getContext()),
                ),
            ),
            attributes: $this->buildWorkflowAttributes($input),
            scoped: true,
            spanKind: SpanKind::KIND_CLIENT,
        );
    }

    /**
     * Traces workflow signalWithStart operations.
     *
     * Creates a span for the signalWithStart operation with relevant attributes
     * and propagates the tracing context through the header.
     *
     * @param SignalWithStartInput $input The signal with start input containing headers
     * @param callable $next The next handler in the interceptor chain
     * @return WorkflowExecution The resulting workflow execution
     * @throws \Throwable If an error occurs during operation
     */
    #[\Override]
    public function signalWithStart(SignalWithStartInput $input, callable $next): WorkflowExecution
    {
        $startInput = $input->workflowStartInput;

        $tracer = $this->getTracerWithContext($startInput->header);

        return $tracer->trace(
            name: SpanName::SignalWithStartWorkflow->value . SpanName::SpanDelimiter->value . $startInput->workflowType,
            callback: fn(): mixed => $next(
                $input->with(
                    workflowStartInput: $startInput->with(
                        header: $this->setContext($startInput->header, $this->tracer->getContext()),
                    ),
                ),
            ),
            attributes: $this->buildWorkflowAttributes($startInput),
            scoped: true,
            spanKind: SpanKind::KIND_CLIENT,
        );
    }

    /**
     * Traces workflow updateWithStart operations.
     *
     * Creates a span for the updateWithStart operation with relevant attributes
     * and propagates the tracing context through the header.
     *
     * @param UpdateWithStartInput $input The update with start input containing headers
     * @param callable $next The next handler in the interceptor chain
     * @return UpdateWithStartOutput The resulting update with start output
     * @throws \Throwable If an error occurs during operation
     */
    #[\Override]
    public function updateWithStart(UpdateWithStartInput $input, callable $next): UpdateWithStartOutput
    {
        $startInput = $input->workflowStartInput;
        $tracer = $this->getTracerWithContext($startInput->header);

        return $tracer->trace(
            name: SpanName::UpdateWithStartWorkflow->value . SpanName::SpanDelimiter->value . $startInput->workflowType,
            callback: fn(): mixed => $next(
                $input->with(
                    workflowStartInput: $startInput->with(
                        header: $this->setContext($startInput->header, $this->tracer->getContext()),
                    ),
                ),
            ),
            attributes: $this->buildWorkflowAttributes($startInput),
            scoped: true,
            spanKind: SpanKind::KIND_CLIENT,
        );
    }

    /**
     * Builds common workflow attributes for spans.
     *
     * @param StartInput $input The workflow start input
     * @return array<non-empty-string, mixed> The workflow attributes for the span
     */
    private function buildWorkflowAttributes(StartInput $input): array
    {
        return [
            WorkflowAttribute::Type->value => $input->workflowType,
            WorkflowAttribute::RunId->value => $input->workflowId,
            WorkflowAttribute::Header->value => \iterator_to_array($input->header->getIterator()),
        ];
    }
}
