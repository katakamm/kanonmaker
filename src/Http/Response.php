<?php

declare(strict_types=1);

namespace Kanon\Http;

final class Response
{
    /** @param array<string, string> $headers */
    private function __construct(
        public readonly string $body,
        public readonly int $status,
        public readonly array $headers,
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function redirect(string $to): self
    {
        return new self('', 302, ['Location' => $to]);
    }

    public static function json(array $data): self
    {
        return new self(
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            200,
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    public static function download(string $body, string $filename, string $contentType): self
    {
        return new self($body, 200, [
            'Content-Type'        => $contentType,
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length'      => (string) strlen($body),
        ]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }
}
