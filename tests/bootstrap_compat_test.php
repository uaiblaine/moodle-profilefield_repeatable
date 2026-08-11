<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace profilefield_repeatable;

/**
 * This plugin may not use Bootstrap 5 utilities, because it renders on core's pages.
 *
 * The bridging between Bootstrap 4 (Moodle 4.5) and Bootstrap 5 (5.0+) is asymmetric:
 * 4.5's forward bridge, theme/boost/scss/moodle/bs5-bridge.scss, is 116 lines covering
 * only g-0, btn-close, the ms/me/ps/pe spacers and float/text/border/rounded-start and
 * -end, while 5.x's backward bridge runs past a thousand. So BS4 names resolve on both
 * branches and BS5 names resolve only on 5.x.
 *
 * A sibling plugin with pages of its own answers that with a polyfill gated on a body
 * class it sets when $CFG->branch < 500. That option does not exist here: this field type
 * renders inside core's /user/edit.php and profile pages, where the plugin controls no
 * ancestor of the markup. So the rule is stricter — do not reach for a BS5 utility at all;
 * own the declaration under a profilefield-repeatable-* class, which is correct on both
 * branches with no version test involved.
 *
 * Where a value genuinely differs between branches, read the theme's own token with a
 * fallback rather than testing the version: 5.x defines --bs-body-bg and 4.5 defines no
 * --bs-* custom property at all, so var(--bs-body-bg, #fff) resolves correctly on each.
 *
 * @package    profilefield_repeatable
 * @category   test
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class bootstrap_compat_test extends \basic_testcase {
    /**
     * Bootstrap 5 utility families that resolve to nothing on Moodle 4.5.
     *
     * Derived by diffing the compiled Boost CSS of a running 4.5 stack against a 5.2 one
     * (8905 class selectors against 9601), then keeping the families a plugin would
     * plausibly reach for. Not guesswork: guessing is what let fw-semibold and d-grid
     * through an earlier sweep of this fleet.
     *
     * @return array List of regular expressions matching a banned class token.
     */
    private function banned_utilities(): array {
        return [
            '/\bvisually-hidden\b/',
            '/\bform-select(-sm)?\b/',
            '/\bform-switch\b/',
            '/\bform-label\b/',
            '/\bgap-[0-9]\b/',
            '/\bg-[0-9]\b/',
            '/\bfw-(bold|medium|normal|semibold|light)\b/',
            '/\bfont-monospace\b/',
            '/\bd-grid\b/',
            '/\blh-[0-9]\b/',
            '/\bfs-[0-9]\b/',
            '/\bfst-italic\b/',
            '/\b(top|bottom|start|end)-(0|50|100)\b/',
            '/\bbg-body\b/',
            '/\bborder-[0-9]\b/',
            '/\bratio(-[0-9]+x[0-9]+)?\b/',
            '/\btranslate-middle\b/',
            '/\btext-bg-[a-z]+\b/',
            '/\bvr\b/',
        ];
    }

    /**
     * Backgrounds that need an explicit text colour beside them.
     *
     * Bootstrap 4's .badge sets no colour and Bootstrap 5's defaults it to white, so a
     * badge that does not state its own fails WCAG AA on one branch or the other.
     * Measured: bg-success 3.07:1 on 4.5, bg-secondary 1.49:1 on 5.2.
     *
     * @return array Background class => required text class.
     */
    private function badge_text_colours(): array {
        return [
            'bg-success' => 'text-white',
            'bg-primary' => 'text-white',
            'bg-danger' => 'text-white',
            'bg-info' => 'text-white',
            'bg-dark' => 'text-white',
            'bg-secondary' => 'text-dark',
            'bg-warning' => 'text-dark',
        ];
    }

    /**
     * Whether a line is prose rather than markup.
     *
     * @param string $line One raw source line.
     * @return bool True when the line opens with a PHP, JS or Mustache comment marker.
     */
    private function is_comment_line(string $line): bool {
        $trimmed = ltrim($line);

        return $trimmed === ''
            || str_starts_with($trimmed, '//')
            || str_starts_with($trimmed, '/*')
            || str_starts_with($trimmed, '*')
            || str_starts_with($trimmed, '{{!');
    }

    /**
     * Every file whose contents can put a class name in front of a user.
     *
     * @return array List of absolute file paths.
     */
    private function markup_files(): array {
        $root = dirname(__DIR__);
        $files = [];
        foreach (['templates', 'amd/src', 'classes'] as $dir) {
            $path = $root . '/' . $dir;
            if (!is_dir($path)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));
            foreach ($iterator as $file) {
                if ($file->isFile() && in_array($file->getExtension(), ['mustache', 'js', 'php'], true)) {
                    $files[] = $file->getPathname();
                }
            }
        }
        sort($files);
        return $files;
    }

    /**
     * No Bootstrap 5 utility may appear in markup this plugin emits.
     *
     * @return void
     */
    public function test_no_bootstrap5_only_utilities(): void {
        $offenders = [];
        $scanned = 0;
        foreach ($this->markup_files() as $path) {
            $scanned++;
            foreach (file($path) as $number => $line) {
                if ($this->is_comment_line($line)) {
                    continue;
                }
                foreach ($this->banned_utilities() as $pattern) {
                    if (preg_match($pattern, $line, $matches)) {
                        $offenders[] = basename($path) . ':' . ($number + 1) . ' uses ' . $matches[0];
                    }
                }
            }
        }
        /* Keyed on a file count so a broken scan fails loudly instead of passing empty. */
        $this->assertGreaterThan(0, $scanned, 'Found no files to scan — the scan is broken, not the code.');
        sort($offenders);
        $this->assertSame(
            [],
            $offenders,
            'These Bootstrap 5 utilities resolve to nothing on Moodle 4.5, and this plugin renders '
                . 'inside core pages where no polyfill can be gated. Own the declaration under a '
                . 'profilefield-repeatable-* class instead: ' . implode('; ', $offenders)
        );
    }

    /**
     * A badge carrying a background utility must carry a text colour beside it.
     *
     * @return void
     */
    public function test_badges_state_their_text_colour(): void {
        $offenders = [];
        foreach ($this->markup_files() as $path) {
            foreach (file($path) as $number => $line) {
                if ($this->is_comment_line($line) || !preg_match('/badge/i', $line)) {
                    continue;
                }
                if (preg_match('/preg_(match|replace|split|quote)|new RegExp/', $line)) {
                    continue;
                }
                foreach ($this->badge_text_colours() as $background => $required) {
                    if (!preg_match('/(?<!text-)\b' . preg_quote($background, '/') . '\b/', $line)) {
                        continue;
                    }
                    if (!preg_match('/\btext-(white|dark|body|muted|bg-[a-z]+)\b/', $line)) {
                        $offenders[] = basename($path) . ':' . ($number + 1) . ' needs ' . $required;
                    }
                }
            }
        }
        sort($offenders);
        $this->assertSame([], $offenders, 'Badges must state their text colour: ' . implode('; ', $offenders));
    }

    /**
     * Markup wired through Bootstrap's data-API must carry both attribute spellings.
     *
     * @return void
     */
    public function test_data_api_attributes_are_paired(): void {
        $offenders = [];
        $pairs = [
            'data-toggle' => 'data-bs-toggle',
            'data-dismiss' => 'data-bs-dismiss',
            'data-target' => 'data-bs-target',
            'data-parent' => 'data-bs-parent',
        ];
        foreach ($this->markup_files() as $path) {
            foreach (file($path) as $number => $line) {
                if ($this->is_comment_line($line)) {
                    continue;
                }
                /*
                 * data-target and data-parent are Bootstrap's only when a toggle sits beside
                 * them; alone they are ordinary custom attributes a plugin reads itself.
                 */
                $wired = preg_match('/(?<![-\w])data-(bs-)?toggle(?![-\w])/', $line);
                foreach ($pairs as $bs4 => $bs5) {
                    if (!$wired && in_array($bs4, ['data-target', 'data-parent'], true)) {
                        continue;
                    }
                    $hasbs4 = preg_match('/(?<![-\w])' . preg_quote($bs4, '/') . '(?![-\w])/', $line);
                    $hasbs5 = preg_match('/(?<![-\w])' . preg_quote($bs5, '/') . '(?![-\w])/', $line);
                    if ($hasbs4 !== $hasbs5) {
                        $offenders[] = basename($path) . ':' . ($number + 1) . ' has only ' . ($hasbs4 ? $bs4 : $bs5);
                    }
                }
            }
        }
        sort($offenders);
        $this->assertSame(
            [],
            $offenders,
            'Bootstrap 4 listens on data-toggle and Bootstrap 5 on data-bs-toggle, so markup-wired '
                . 'components need both spellings: ' . implode('; ', $offenders)
        );
    }

    /**
     * The stylesheet must not declare custom properties in core's design-system namespace.
     *
     * Moodle 5.2 ships theme/boost/scss/design-system/ with $mds-* tokens and 5.3 LTS brings
     * MDS React, so an --mds-* declaration squats a namespace core is actively expanding.
     *
     * @return void
     */
    public function test_stylesheet_declares_no_core_design_system_tokens(): void {
        $root = dirname(__DIR__);
        $offenders = [];
        foreach (array_merge([$root . '/styles.css'], glob($root . '/styles_*.css') ?: []) as $sheet) {
            if (!is_readable($sheet)) {
                continue;
            }
            foreach (file($sheet) as $number => $line) {
                if (preg_match('/--mds-[a-z0-9-]+\s*:/i', $line)) {
                    $offenders[] = basename($sheet) . ':' . ($number + 1);
                }
            }
        }
        $this->assertSame(
            [],
            $offenders,
            'These lines declare custom properties in core\'s --mds- namespace: ' . implode(', ', $offenders)
        );
    }
}
