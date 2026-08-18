<?php

declare(strict_types=1);

namespace Kanon\View;

/**
 * Renders a plain-PHP template.
 *
 * Templates call $this->e() for every value they print; there is no automatic
 * escaping, so a template that interpolates a variable directly is a defect.
 */
final class View
{
    public function __construct(private readonly string $templateDir)
    {
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = []): string
    {
        $file = $this->templateDir . '/' . $template . '.php';

        if (!is_file($file)) {
            throw new \RuntimeException("Template not found: {$template}");
        }

        extract($data, EXTR_SKIP);
        ob_start();

        try {
            require $file;

            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
    }

    public function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
