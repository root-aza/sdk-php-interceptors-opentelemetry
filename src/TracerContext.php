<?php

declare(strict_types=1);

namespace Temporal\OpenTelemetry;

use Temporal\Interceptor\HeaderInterface;

/**
 * Provides common functionality for handling tracer context in Temporal headers.
 *
 * This trait encapsulates the operations needed to store, retrieve, and manipulate
 * OpenTelemetry context data within Temporal message headers.
 */
trait TracerContext
{
    /**
     * Gets the header key used for storing tracer context data.
     *
     * @return non-empty-string The header key for tracer data
     */
    protected function getTracerHeader(): string
    {
        return '_tracer-data';
    }

    /**
     * Creates a tracer with context extracted from the provided header.
     *
     * If the header contains tracer data, the data is used to create a context-aware tracer.
     * Otherwise, returns the original tracer.
     *
     * @param HeaderInterface $header The header potentially containing tracer context
     * @return Tracer A tracer with the appropriate context
     */
    private function getTracerWithContext(HeaderInterface $header): Tracer
    {
        $tracerData = $header->getValue($this->getTracerHeader(), 'array');

        return $tracerData === null ? $this->tracer : $this->tracer->fromContext($tracerData);
    }

    /**
     * Sets the tracer context in the provided header.
     *
     * @param HeaderInterface $header The header to update
     * @param array<non-empty-string, mixed> $context The context data to store
     * @return HeaderInterface A new header instance with the updated context
     */
    private function setContext(HeaderInterface $header, array $context): HeaderInterface
    {
        return $header->withValue($this->getTracerHeader(), (object) $context);
    }
}
