# Temporal OpenTelemetry Interceptors

## Introduction

The `temporal/open-telemetry-interceptors` package provides OpenTelemetry interceptors for tracing workflows and activities within the Temporal system using the [OpenTelemetry SDK](https://opentelemetry.io/docs/instrumentation/php/).

These interceptors capture and trace various actions and events, such as handling activities, starting workflows, sending signals, and executing workflow events. By integrating OpenTelemetry tracing, you gain visibility into the behavior and performance of your Temporal applications.

![OpenTelemetry Tracing Example](https://github.com/temporalio/sdk-php-interceptors-opentelemetry/assets/67324318/615dd335-39df-4526-af71-7f422e39bfa9)

## Get Started

### Installation

Install the package using Composer:

```bash
composer require temporal/open-telemetry-interceptors
```

### Basic Setup

1. **Create a Pipeline Provider with Interceptors**

```php
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\SDK\Trace;
use Temporal\OpenTelemetry\Interceptor\OpenTelemetryActivityInboundInterceptor;
use Temporal\OpenTelemetry\Interceptor\OpenTelemetryWorkflowClientCallsInterceptor;
use Temporal\OpenTelemetry\Interceptor\OpenTelemetryWorkflowOutboundRequestInterceptor;
use Temporal\Interceptor\SimplePipelineProvider;

// Create a span processor
$spanProcessor = (new Trace\SpanProcessorFactory())->create(
    (new Trace\ExporterFactory())->create(),
);

// Create a tracer provider
$tracerProvider = new Trace\TracerProvider($spanProcessor);

// Create a tracer which wraps the OpenTelemetry tracer
$tracer = new Temporal\OpenTelemetry\Tracer(
    // Pass a unique name for your application
    $tracerProvider->getTracer('My super app'),
    TraceContextPropagator::getInstance(),
);

// Configure the interceptor pipeline
$provider = new SimplePipelineProvider([
    new OpenTelemetryActivityInboundInterceptor($tracer),
    new OpenTelemetryWorkflowClientCallsInterceptor($tracer),
    new OpenTelemetryWorkflowOutboundRequestInterceptor($tracer),
]);
```

2. **Apply Interceptors to Workflow Client and Worker**

```php
// Add interceptors to the workflow client
$client = new Temporal\Client\WorkflowClient(
    ..., 
    interceptorProvider: $provider
);

// Add interceptors to the worker
$worker = new WorkerFactory(
   ...,
   pipelineProvider: $provider
);
```

## Available Interceptors

This package provides three specialized interceptors:

### OpenTelemetryActivityInboundInterceptor

Traces the execution of activities. This interceptor creates spans when an activity is handled.

### OpenTelemetryWorkflowClientCallsInterceptor

Focuses on tracing client-side workflow operations.
This interceptor creates spans when calling `start()`, `signalWithStart()`, or `updateWithStart()` methods
and propagates the context to the workflow execution.

### OpenTelemetryWorkflowOutboundRequestInterceptor

Captures outbound requests made by workflows.
This includes spans for activities execution, child workflows, timers, signals to external workflows,
and other outbound operations.
It provides comprehensive tracing of how workflows interact with other components in the system.
