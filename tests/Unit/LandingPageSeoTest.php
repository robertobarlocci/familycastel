<?php

declare(strict_types=1);

namespace FamilyCastel\Tests\Unit;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\TestCase;

/**
 * The public landing page's contract — `site/`, published to GitHub Pages.
 *
 * A marketing page fails silently: a dropped meta tag, a renamed screenshot or a
 * stylesheet that never loads all still "work" in the sense that a browser renders
 * something. So the properties that matter are asserted here rather than eyeballed,
 * and the Pages workflow will not deploy unless this file is green.
 *
 * Three groups of property:
 *  - FOUND: title/description/canonical/OG/Twitter/JSON-LD/robots/sitemap, each unique
 *    and within its SEO limits, with the structured data matching the visible page.
 *  - HONEST: the product is spelled "Family Castel" (INV-004), quests are "Sidequests",
 *    the page advertises the version the repo actually is, and the claims the research
 *    pass proved false cannot creep back in.
 *  - SELF-CONTAINED: every asset resolves on disk, and the page makes no third-party
 *    request by any mechanism — no remote URL, no script, no inline handler, no embed.
 */
final class LandingPageSeoTest extends TestCase
{
    /** The canonical origin + path. A project site, so the path prefix is load-bearing. */
    private const SITE_URL = 'https://robertobarlocci.github.io/familycastel/';

    /** Absolute URLs are legal here and nowhere else. */
    private const ABSOLUTE_META = ['canonical', 'og:url', 'og:image', 'twitter:image'];

    /** Claims the research pass proved untrue of this product. Matched case-insensitively. */
    private const FALSE_CLAIMS = [
        // Notifications are in-app only: no push, no email, no cron (README "no cron").
        'push notification',
        // It is an installable PWA. There is no native app and no store listing.
        'app store',
        // Apache + .htaccess specifically; the installer says not to proceed without it.
        'works on any host',
        // LevelService::MAX_LEVEL is 500.
        'unlimited levels',
    ];

    private string $siteDir;
    private string $screenshotDir;
    private string $html;
    private string $css;
    private DOMDocument $dom;
    private DOMXPath $xpath;

    protected function setUp(): void
    {
        $this->siteDir = FC_ROOT . '/site';
        $this->screenshotDir = FC_ROOT . '/docs/screenshots';

        $index = $this->siteDir . '/index.html';
        self::assertFileExists($index, 'the landing page must exist');
        $this->html = (string) file_get_contents($index);

        $stylesheet = $this->siteDir . '/styles.css';
        self::assertFileExists($stylesheet, 'the landing page stylesheet must exist');
        $this->css = (string) file_get_contents($stylesheet);

        // libxml is not an HTML5 validator; it is used to read structure, not to bless it.
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $this->dom = new DOMDocument();
        $this->dom->loadHTML(
            '<?xml encoding="utf-8"?>' . $this->html,
            LIBXML_NOWARNING | LIBXML_NOERROR
        );
        $fatal = array_filter(libxml_get_errors(), static fn ($e) => $e->level === LIBXML_ERR_FATAL);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        self::assertSame([], $fatal, 'index.html has fatal parse errors');
        $this->xpath = new DOMXPath($this->dom);
    }

    // ---------------------------------------------------------------- structure

    public function testTheDocumentDeclaresHtml5AndItsLanguage(): void
    {
        self::assertMatchesRegularExpression(
            '/^\s*<!doctype html>/i',
            $this->html,
            'the page must open with an HTML5 doctype'
        );

        $html = $this->dom->getElementsByTagName('html')->item(0);
        self::assertInstanceOf(DOMElement::class, $html);
        self::assertSame('en', $html->getAttribute('lang'), '<html> must declare its language');
    }

    public function testThereIsExactlyOneH1AndHeadingLevelsNeverSkip(): void
    {
        self::assertCount(1, iterator_to_array($this->dom->getElementsByTagName('h1')));

        $levels = [];
        foreach ($this->xpath->query('//h1|//h2|//h3|//h4|//h5|//h6') as $heading) {
            $levels[] = (int) substr($heading->nodeName, 1);
        }

        self::assertNotSame([], $levels);
        self::assertSame(1, $levels[0], 'the first heading must be the h1');

        $previous = $levels[0];
        foreach ($levels as $level) {
            self::assertLessThanOrEqual(
                $previous + 1,
                $level,
                'heading levels must not skip (h' . $previous . ' -> h' . $level . ')'
            );
            $previous = $level;
        }
    }

    // ---------------------------------------------------------------- metadata

    public function testTitleAndDescriptionArePresentAndWithinSeoLimits(): void
    {
        $titles = $this->dom->getElementsByTagName('title');
        self::assertCount(1, iterator_to_array($titles), 'exactly one <title>');
        $title = trim((string) $titles->item(0)?->textContent);
        self::assertNotSame('', $title);
        self::assertLessThanOrEqual(60, mb_strlen($title), 'title is truncated in results beyond 60 chars');

        $description = $this->metaContent('name', 'description');
        self::assertGreaterThanOrEqual(50, mb_strlen($description), 'description is too thin to be useful');
        self::assertLessThanOrEqual(160, mb_strlen($description), 'description is truncated beyond 160 chars');
    }

    public function testCanonicalIsTheAbsoluteSiteUrl(): void
    {
        $canonical = $this->xpath->query('//link[@rel="canonical"]');
        self::assertCount(1, $canonical, 'exactly one canonical link');
        self::assertSame(self::SITE_URL, $canonical->item(0)?->attributes?->getNamedItem('href')?->nodeValue);
    }

    public function testSocialMetadataIsCompleteAndNeverDuplicated(): void
    {
        foreach (['og:type', 'og:title', 'og:description', 'og:url', 'og:image', 'og:image:alt',
                  'og:site_name', 'og:locale'] as $property) {
            self::assertNotSame('', $this->metaContent('property', $property), $property . ' is empty');
        }

        foreach (['twitter:card', 'twitter:title', 'twitter:description', 'twitter:image',
                  'twitter:image:alt'] as $name) {
            self::assertNotSame('', $this->metaContent('name', $name), $name . ' is empty');
        }

        self::assertSame('summary_large_image', $this->metaContent('name', 'twitter:card'));

        // Duplicated metadata is a real SEO defect: crawlers pick one arbitrarily.
        foreach (self::ABSOLUTE_META as $key) {
            $absolute = $key === 'canonical'
                ? (string) $this->xpath->query('//link[@rel="canonical"]')->item(0)?->attributes
                    ?->getNamedItem('href')?->nodeValue
                : $this->metaContent(str_starts_with($key, 'og:') ? 'property' : 'name', $key);

            self::assertStringStartsWith(
                self::SITE_URL,
                $absolute,
                $key . ' must point at this site, not a third party'
            );
        }
    }

    // ---------------------------------------------------------------- structured data

    public function testSoftwareApplicationJsonLdIsCompleteAndHonestAboutTheVersion(): void
    {
        $block = $this->jsonLd('SoftwareApplication');

        foreach (['name', 'description', 'applicationCategory', 'operatingSystem',
                  'license', 'codeRepository', 'softwareVersion', 'author'] as $field) {
            self::assertArrayHasKey($field, $block, $field . ' is missing from the JSON-LD');
            self::assertNotEmpty($block[$field], $field . ' is empty');
        }

        self::assertSame('0', (string) ($block['offers']['price'] ?? null), 'the product is free');

        $version = trim((string) file_get_contents(FC_ROOT . '/VERSION'));
        self::assertSame(
            $version,
            (string) $block['softwareVersion'],
            'the page must advertise the version this repo actually is'
        );
    }

    public function testFaqJsonLdMatchesTheRenderedFaqInBothDirections(): void
    {
        $block = $this->jsonLd('FAQPage');
        $entities = $block['mainEntity'] ?? [];

        $rendered = [];
        foreach ($this->xpath->query('//details[summary]') as $details) {
            $summary = $details->getElementsByTagName('summary')->item(0);
            $question = $this->normalise((string) $summary?->textContent);

            $answer = '';
            foreach ($details->childNodes as $child) {
                if ($child->nodeName !== 'summary') {
                    $answer .= $child->textContent;
                }
            }
            $rendered[$question] = $this->normalise($answer);
        }

        // Without a floor the whole test is vacuous: zero entries satisfies every
        // comparison below perfectly.
        self::assertGreaterThanOrEqual(4, count($rendered), 'the FAQ needs real content');
        self::assertCount(
            count($rendered),
            $entities,
            'the JSON-LD and the rendered FAQ have different numbers of questions'
        );

        foreach ($entities as $entity) {
            $question = $this->normalise((string) ($entity['name'] ?? ''));
            self::assertArrayHasKey($question, $rendered, 'JSON-LD question is not on the page: ' . $question);
            self::assertSame(
                $rendered[$question],
                $this->normalise(strip_tags((string) ($entity['acceptedAnswer']['text'] ?? ''))),
                'the structured answer disagrees with the visible one: ' . $question
            );
            unset($rendered[$question]);
        }

        self::assertSame([], $rendered, 'these FAQ entries are missing from the JSON-LD');
    }

    // ---------------------------------------------------------------- the name (INV-004)

    public function testTheCastelSpellingIsExplainedAndNeverWrong(): void
    {
        $section = $this->dom->getElementById('castel');
        self::assertInstanceOf(DOMElement::class, $section, 'the name explainer must live at #castel');

        $heading = $section->nodeName === 'h2'
            ? $section
            : $section->getElementsByTagName('h2')->item(0);
        self::assertInstanceOf(DOMElement::class, $heading, '#castel must be, or contain, an <h2>');

        $text = $section->textContent;
        self::assertStringContainsString('Castel', $text);
        self::assertStringContainsString('Castle', $text, 'the section is about the contrast with "Castle"');
        self::assertStringContainsString('cas-TEL', $text, 'the pronunciation is the whole joke');

        $questions = [];
        foreach ($this->dom->getElementsByTagName('summary') as $summary) {
            $questions[] = $summary->textContent;
        }
        self::assertNotSame(
            [],
            array_filter($questions, static fn (string $q): bool
                => preg_match('/castel.*castle|castle.*castel/i', $q) === 1),
            'the name question belongs in the FAQ too, so it can win a rich result'
        );

        // INV-004, mechanically. Case-insensitive: "family castle" mid-sentence counts.
        self::assertSame(
            0,
            preg_match_all('/family\s+castle/i', $this->html),
            'INV-004: the product is spelled "Family Castel", never "Family Castle"'
        );
        self::assertGreaterThanOrEqual(5, substr_count($this->html, 'Family&nbsp;Castel')
            + substr_count($this->html, 'Family Castel'));

        // INV-004's second clause: the feature is called Sidequests.
        self::assertStringContainsString('Sidequest', $this->html);
        foreach (['chore', 'task list'] as $wrongName) {
            self::assertSame(
                0,
                preg_match_all('/\b' . preg_quote($wrongName, '/') . '\b/i', strip_tags($this->html)),
                'INV-004: quests are "Sidequests", never "' . $wrongName . '"'
            );
        }

        // One word. "side quest" and "side-quest" are the same violation as "Family Castle"
        // — the invariant is about the spelling, and a headline is exactly where a writer
        // reaches for the ordinary English form without noticing.
        self::assertSame(
            0,
            preg_match_all('/side[-\s]quest/i', $this->html),
            'INV-004: it is "Sidequest", one word — never "side quest" or "side-quest"'
        );
    }

    public function testTheClaimsWeKnowToBeFalseCannotCreepBackIn(): void
    {
        $text = strip_tags($this->html);

        foreach (self::FALSE_CLAIMS as $claim) {
            self::assertSame(
                0,
                preg_match_all('/' . preg_quote($claim, '/') . '/i', $text),
                'this claim is not true of this product: "' . $claim . '"'
            );
        }
    }

    // ---------------------------------------------------------------- self-contained

    public function testThePageRunsNoJavaScriptAndEmbedsNothing(): void
    {
        foreach ($this->dom->getElementsByTagName('script') as $script) {
            self::assertSame(
                'application/ld+json',
                $script->getAttribute('type'),
                'the only scripts allowed are the JSON-LD blocks'
            );
            self::assertSame('', $script->getAttribute('src'), 'no external script');
        }

        foreach (['iframe', 'object', 'embed', 'frame', 'frameset'] as $tag) {
            self::assertCount(
                0,
                iterator_to_array($this->dom->getElementsByTagName($tag)),
                '<' . $tag . '> can carry a whole nested document; the page needs none'
            );
        }

        // Walk EVERY attribute: enumerating executable sinks is a losing game.
        foreach ($this->xpath->query('//@*') as $attribute) {
            self::assertInstanceOf(DOMAttr::class, $attribute);
            $name = strtolower($attribute->nodeName);

            self::assertStringStartsNotWith('on', $name, 'inline handlers run without any <script>');

            $value = strtolower(rawurldecode(preg_replace('/[\x00-\x20]/', '', $attribute->nodeValue) ?? ''));
            self::assertStringNotContainsString('javascript:', $value, $name . ' carries a javascript: URL');
        }
    }

    public function testNothingIsRequestedFromAThirdParty(): void
    {
        self::assertDoesNotMatchRegularExpression(
            '/@import/i',
            $this->css,
            'one stylesheet, no indirection: an imported sheet could pull a remote font'
        );

        foreach ($this->requestUrls() as $context => $urls) {
            foreach ($urls as $url) {
                if ($this->isExempt($url)) {
                    continue;
                }

                self::assertDoesNotMatchRegularExpression(
                    '#^(?:[a-z][a-z0-9+.-]*:)?//#i',
                    $url,
                    'third-party or protocol-relative request in ' . $context . ': ' . $url
                );
                self::assertStringStartsNotWith(
                    '/',
                    $url,
                    'root-relative breaks a project site under /familycastel/: ' . $url
                );
            }
        }
    }

    public function testEveryLocalUrlResolvesToARealFile(): void
    {
        $stylesheets = $this->xpath->query('//link[@rel="stylesheet"]');
        self::assertCount(1, $stylesheets, 'exactly one stylesheet');
        self::assertSame(
            'styles.css',
            $stylesheets->item(0)?->attributes?->getNamedItem('href')?->nodeValue
        );

        foreach ($this->requestUrls() as $context => $urls) {
            foreach ($urls as $url) {
                if ($this->isExempt($url) || preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $url) === 1) {
                    continue;
                }

                $this->assertResolves($url, $context);
            }
        }

        // In-page anchors live on <a href>, which requestUrls() deliberately skips, so
        // they are checked here: a dangling #target is a real defect nothing else sees.
        $anchors = $this->inPageAnchors();
        self::assertNotSame([], $anchors, 'the page navigation is anchor-based');
        foreach ($anchors as $anchor) {
            self::assertInstanceOf(
                DOMElement::class,
                $this->dom->getElementById(substr($anchor, 1)),
                'dangling in-page anchor: ' . $anchor
            );
        }

        foreach (['fredoka-600', 'fredoka-700', 'nunito-400', 'nunito-700', 'nunito-800'] as $face) {
            self::assertFileExists($this->siteDir . '/fonts/' . $face . '.woff2');
        }
        foreach (['icon.svg', 'icon-192.png', 'icon-512.png'] as $icon) {
            self::assertFileExists($this->siteDir . '/icons/' . $icon);
        }
    }

    public function testEveryImageHasAltTextAndTheScreenshotsAreReal(): void
    {
        $images = iterator_to_array($this->dom->getElementsByTagName('img'));
        self::assertGreaterThanOrEqual(3, count($images), 'a page with no images is not the deliverable');

        $screenshots = 0;
        foreach ($images as $image) {
            self::assertNotSame(
                '',
                trim($image->getAttribute('alt')),
                'every image needs alt text: ' . $image->getAttribute('src')
            );

            $src = $image->getAttribute('src');
            if (str_starts_with($src, 'screens/')) {
                $screenshots++;
            }
            $this->assertResolves($src, 'img');
        }

        self::assertGreaterThanOrEqual(2, $screenshots, 'show the real product');
    }

    // ---------------------------------------------------------------- crawlability

    public function testRobotsTxtInvitesCrawlingAndNamesTheSitemap(): void
    {
        $robots = $this->siteDir . '/robots.txt';
        self::assertFileExists($robots);
        $contents = (string) file_get_contents($robots);

        self::assertMatchesRegularExpression('/^\s*User-agent:\s*\*/mi', $contents);
        self::assertSame(
            0,
            preg_match_all('/^\s*Disallow:\s*\/\s*$/mi', $contents),
            'Disallow: / would de-index the page this whole change exists to publish'
        );
        self::assertStringContainsString(self::SITE_URL . 'sitemap.xml', $contents);

        // The other way a page de-indexes itself, and it lives in the HTML.
        $robotsMeta = strtolower($this->metaContent('name', 'robots', false));
        self::assertStringNotContainsString('noindex', $robotsMeta);
        self::assertStringNotContainsString('nofollow', $robotsMeta);
    }

    public function testSitemapListsExactlyThisPage(): void
    {
        $path = $this->siteDir . '/sitemap.xml';
        self::assertFileExists($path);

        $sitemap = simplexml_load_string((string) file_get_contents($path));
        self::assertNotFalse($sitemap, 'sitemap.xml must be valid XML');
        self::assertCount(1, $sitemap->url);
        self::assertSame(self::SITE_URL, (string) $sitemap->url[0]->loc);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $sitemap->url[0]->lastmod);
    }

    public function testNojekyllStopsGitHubRewritingTheSite(): void
    {
        self::assertFileExists(
            $this->siteDir . '/.nojekyll',
            'without it GitHub runs Jekyll and drops files beginning with _'
        );
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Every URL the browser would REQUEST, by context.
     *
     * `<a href>` is deliberately excluded: a link is a navigation the user chooses, not a
     * request the page makes, and outbound links to GitHub are the point. Excluding it
     * here — rather than exempting values that happen to equal some anchor's href — is
     * what stops a remote asset from hiding behind a link that shares its URL.
     * `<a ping>` IS collected: that one is a real request.
     *
     * @return array<string, list<string>> context => urls
     */
    private function requestUrls(): array
    {
        $collected = ['html' => [], 'styles.css' => []];

        $attributes = ['src', 'href', 'poster', 'data-src', 'data', 'ping', 'xlink:href'];
        foreach ($this->xpath->query('//@*') as $attribute) {
            $name = strtolower($attribute->nodeName);
            $owner = $attribute->ownerElement;

            if ($name === 'href' && $owner instanceof DOMElement && $owner->nodeName === 'a') {
                continue;
            }

            if (in_array($name, $attributes, true)) {
                $collected['html'][] = trim($attribute->nodeValue ?? '');
            }
            if ($name === 'srcset' || $name === 'imagesrcset') {
                foreach (explode(',', (string) $attribute->nodeValue) as $candidate) {
                    $collected['html'][] = trim(explode(' ', trim($candidate))[0]);
                }
            }
            if ($name === 'style') {
                $collected['html'] = array_merge($collected['html'], $this->cssUrls((string) $attribute->nodeValue));
            }
        }

        // <meta http-equiv="refresh" content="0; url=..."> navigates on its own.
        foreach ($this->xpath->query('//meta[translate(@http-equiv,"REFSH","refsh")="refresh"]') as $meta) {
            if (preg_match('/url\s*=\s*[\'"]?([^\'";]+)/i', (string) $meta->attributes?->getNamedItem('content')?->nodeValue, $m) === 1) {
                $collected['html'][] = trim($m[1]);
            }
        }

        foreach ($this->dom->getElementsByTagName('style') as $style) {
            $collected['html'] = array_merge($collected['html'], $this->cssUrls($style->textContent));
        }
        $collected['styles.css'] = $this->cssUrls($this->css);

        return array_map(static fn (array $u): array => array_values(array_filter($u)), $collected);
    }

    /**
     * URL tokens in CSS: `url(...)` plus the bare-string form of `image-set()`, which
     * takes `image-set("a.png" 1x, "b.png" 2x)` — quoted strings with no `url()` wrapper,
     * and therefore invisible to a `url(` scan.
     *
     * @return list<string>
     */
    private function cssUrls(string $css): array
    {
        preg_match_all('/url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/i', $css, $urlMatches);
        $urls = array_map('trim', $urlMatches[1]);

        preg_match_all('/image-set\(([^)]*)\)/i', $css, $setMatches);
        foreach ($setMatches[1] as $arguments) {
            preg_match_all('/[\'"]([^\'"]+)[\'"]/', $arguments, $strings);
            foreach ($strings[1] as $candidate) {
                $urls[] = trim($candidate);
            }
        }

        return $urls;
    }

    /** @return list<string> in-page anchor targets, e.g. `#castel` */
    private function inPageAnchors(): array
    {
        $anchors = [];
        foreach ($this->dom->getElementsByTagName('a') as $anchor) {
            $href = trim($anchor->getAttribute('href'));
            if (str_starts_with($href, '#')) {
                $anchors[] = $href;
            }
        }

        return $anchors;
    }

    private function isExempt(string $url): bool
    {
        return $url === ''
            || str_starts_with($url, 'data:')
            || str_starts_with($url, 'mailto:')
            || str_starts_with($url, 'tel:')
            || in_array($url, $this->absoluteMetaValues(), true);
    }

    /** @return list<string> */
    private function absoluteMetaValues(): array
    {
        $values = [];
        foreach (self::ABSOLUTE_META as $key) {
            $values[] = $key === 'canonical'
                ? (string) $this->xpath->query('//link[@rel="canonical"]')->item(0)?->attributes
                    ?->getNamedItem('href')?->nodeValue
                : $this->metaContent(str_starts_with($key, 'og:') ? 'property' : 'name', $key, false);
        }

        return array_values(array_filter($values));
    }

    private function assertResolves(string $url, string $context): void
    {
        $path = rawurldecode(strtok(strtok($url, '#') ?: '', '?') ?: '');
        self::assertStringNotContainsString('..', $path, 'traversal in ' . $context . ': ' . $url);

        $isScreenshot = str_starts_with($path, 'screens/');
        $base = $isScreenshot
            ? $this->screenshotDir . '/' . substr($path, strlen('screens/'))
            : $this->siteDir . '/' . $path;

        $real = realpath($base);
        self::assertNotFalse($real, 'unresolvable local URL in ' . $context . ': ' . $url);

        // A directory "resolves" too, and would serve a 403 or an index listing rather
        // than the asset the page asked for.
        self::assertFileExists($real);
        self::assertTrue(is_file($real), 'resolves to a directory, not a file: ' . $url);
        self::assertGreaterThan(0, (int) filesize($real), 'empty file behind ' . $url);

        // The separator matters: without it, `/…/site-other/x` passes a `/…/site` prefix.
        $root = (string) realpath($isScreenshot ? $this->screenshotDir : $this->siteDir);
        self::assertStringStartsWith(
            rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR,
            $real,
            'escapes its root: ' . $url
        );
    }

    private function metaContent(string $attribute, string $key, bool $required = true): string
    {
        $nodes = $this->xpath->query('//meta[@' . $attribute . '="' . $key . '"]');

        if (!$required && $nodes->count() === 0) {
            return '';
        }

        self::assertCount(1, $nodes, 'exactly one ' . $key . ' meta tag');

        return trim((string) $nodes->item(0)?->attributes?->getNamedItem('content')?->nodeValue);
    }

    /** @return array<string, mixed> */
    private function jsonLd(string $type): array
    {
        foreach ($this->dom->getElementsByTagName('script') as $script) {
            if ($script->getAttribute('type') !== 'application/ld+json') {
                continue;
            }

            $decoded = json_decode($script->textContent, true);
            self::assertIsArray($decoded, 'JSON-LD must parse');
            self::assertArrayHasKey('@context', $decoded);

            if (($decoded['@type'] ?? null) === $type) {
                return $decoded;
            }
        }

        self::fail('no ' . $type . ' JSON-LD block on the page');
    }

    private function normalise(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($text)));
    }
}
