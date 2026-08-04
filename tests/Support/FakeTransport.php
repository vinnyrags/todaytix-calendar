<?php

declare(strict_types=1);

namespace TodayTixCalendar\Tests\Support;

use TodayTixCalendar\Engine\Http\HttpTransport;
use TodayTixCalendar\Engine\Http\TransportException;

/**
 * Canned HTTP transport for engine tests — returns a fixed body (or throws) without
 * touching the network. Records the requested URL for assertion.
 */
final class FakeTransport implements HttpTransport
{
    public ?string $requestedUrl = null;

    public function __construct(
        private readonly ?string $body,
        private readonly ?TransportException $throw = null,
    ) {}

    public static function withBody(string $body): self
    {
        return new self($body);
    }

    public static function throwing(string $message): self
    {
        return new self(null, new TransportException($message));
    }

    public static function fromFixture(string $name): self
    {
        return new self((string) file_get_contents(__DIR__ . '/../fixtures/' . $name));
    }

    public function get(string $url): string
    {
        $this->requestedUrl = $url;

        if ($this->throw !== null) {
            throw $this->throw;
        }

        return (string) $this->body;
    }
}
