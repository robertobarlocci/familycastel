<?php

declare(strict_types=1);

namespace FamilyCastel\Core;

final class View
{
    public function __construct(private readonly string $viewsDir)
    {
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = [], ?string $layout = null): string
    {
        $content = $this->renderFile($template, $data);

        if ($layout !== null) {
            return $this->renderFile($layout, $data + ['content' => $content]);
        }

        return $content;
    }

    /** @param array<string, mixed> $data */
    private function renderFile(string $template, array $data): string
    {
        $file = $this->viewsDir . '/' . str_replace(['..', "\0"], '', $template) . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('View not found: ' . $template);
        }

        extract($data, EXTR_SKIP);
        ob_start();
        require $file;

        return (string) ob_get_clean();
    }
}
