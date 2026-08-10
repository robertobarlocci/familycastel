<?php

declare(strict_types=1);

namespace FamilyCastel\Core;

final class View
{
    /**
     * Names a view may not be handed. `__*` are renderFile()'s own locals;
     * `this` is always present in method scope, so extract() would skip it.
     */
    private const RESERVED = ['__view', '__data', '__file', 'this'];

    public function __construct(private readonly string $viewsDir)
    {
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = [], ?string $layout = null): string
    {
        $content = $this->renderFile($template, $data);

        if ($layout === null) {
            return $content;
        }

        // `$data + [...]` keeps the LEFT side on a key clash, so a caller-supplied
        // 'content' would have replaced the rendered body — the same silent-drop
        // family as issue #12, just in the other direction. The rendered content
        // wins, and a caller trying to set it is told rather than ignored.
        if (array_key_exists('content', $data)) {
            throw new \RuntimeException("View data may not define 'content': it holds the rendered body for a layout.");
        }

        return $this->renderFile($layout, ['content' => $content] + $data);
    }

    /**
     * Locals are `__`-prefixed on purpose. They used to be `$template`, `$data`
     * and `$file`, and `extract(..., EXTR_SKIP)` silently discarded any view
     * variable sharing one of those names — so a controller passing
     * `['template' => …]` saw the VIEW NAME instead of its record, and the
     * template died on a string offset far from the cause (issue #12). A
     * dropped variable must be impossible here, not merely unlikely.
     *
     * @param array<string, mixed> $__data
     */
    private function renderFile(string $__view, array $__data): string
    {
        // Validated in ANOTHER scope on purpose: a loop variable declared here
        // would itself become a name extract() could silently skip — the very
        // trap being fixed. renderFile() therefore owns exactly three locals,
        // all of them listed in RESERVED.
        self::assertUsableKeys($__data);

        $__file = $this->viewsDir . '/' . str_replace(['..', "\0"], '', $__view) . '.php';
        if (!is_file($__file)) {
            throw new \RuntimeException('View not found: ' . $__view);
        }

        extract($__data, EXTR_SKIP);
        ob_start();
        require $__file;

        return (string) ob_get_clean();
    }

    /**
     * Refuse anything extract() would quietly drop: a reserved name, a numeric
     * key, or any string that is not a valid PHP variable name. Each of those
     * is a variable the view asked for and would not receive.
     *
     * @param array<array-key, mixed> $data
     */
    private static function assertUsableKeys(array $data): void
    {
        foreach ($data as $key => $ignored) {
            if (!is_string($key) || preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/', $key) !== 1) {
                throw new \RuntimeException('View data key is not a usable variable name: ' . var_export($key, true));
            }
            if (in_array($key, self::RESERVED, true)) {
                // Fail loudly: silently skipping is what made #12 invisible.
                throw new \RuntimeException('Reserved view variable name: $' . $key);
            }
        }
    }
}
