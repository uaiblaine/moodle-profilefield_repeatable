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

namespace profilefield_repeatable\output;

use local_repeatable\output\sets_renderer;
use profilefield_repeatable\local\field_schema;
use profilefield_repeatable\local\source;

/**
 * Display renderer for repeatable profile fields.
 *
 * Profile adapter: it renders an accordion on /user/profile.php and /user/view.php and a plain
 * list elsewhere, names the accordion after the field and the user, and reads the user's
 * projection rows for the sync times. The sets themselves are rendered by the engine's
 * {@see sets_renderer}.
 *
 * @package    profilefield_repeatable
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class display_renderer {
    /** @var object Field configuration object. */
    private object $field;

    /** @var int User ID. */
    private int $userid;

    /** @var string Raw field data. */
    private string $data;

    /** @var \core_renderer */
    private $output;

    /** @var \moodle_page */
    private $page;

    /** @var \stdClass[]|null Projection rows already fetched by the caller; null fetches them on demand. */
    private ?array $preloadedrecords;

    /**
     * Constructor.
     *
     * @param object $field Field configuration object.
     * @param int $userid User ID.
     * @param string $data Raw field data.
     * @param \core_renderer|null $output Core renderer (optional, uses global if null).
     * @param \moodle_page|null $page Moodle page (optional, uses global if null).
     * @param \stdClass[]|null $preloadedrecords Projection rows (set_id, data, timemodified) already
     *                                           fetched by the caller; null fetches them on demand.
     *                                           They only supply each set's last-sync time.
     */
    public function __construct(
        object $field,
        int $userid,
        string $data,
        $output = null,
        $page = null,
        ?array $preloadedrecords = null
    ) {
        $this->field = $field;
        $this->userid = $userid;
        $this->data = $data;
        $this->output = $output ?? ($GLOBALS['OUTPUT'] ?? null);
        $this->page = $page ?? ($GLOBALS['PAGE'] ?? null);
        $this->preloadedrecords = $preloadedrecords;
    }

    /**
     * Render field display output.
     *
     * @return string
     */
    public function render(): string {
        $accordion = $this->is_profile_page_context();
        $renderer = new sets_renderer(
            field_schema::from_field((object)(array)$this->field),
            $this->data,
            (string)($this->field->name ?? ''),
            $accordion ? $this->get_display_accordion_id() : '',
            fn(): array => $this->get_projection_records(),
            $this->output,
            $this->page
        );

        return $renderer->render($accordion ? sets_renderer::LAYOUT_ACCORDION : sets_renderer::LAYOUT_PLAIN);
    }

    /**
     * Projection rows of this user and field, preloaded or fetched once.
     *
     * @return \stdClass[]
     */
    private function get_projection_records(): array {
        if ($this->preloadedrecords !== null) {
            return $this->preloadedrecords;
        }

        $this->preloadedrecords = [];
        if ($this->userid > 0 && !empty($this->field->id)) {
            $this->preloadedrecords = (new source())->projection_rows((int)$this->field->id, $this->userid);
        }

        return $this->preloadedrecords;
    }

    /**
     * Return unique accordion identifier.
     *
     * @return string
     */
    private function get_display_accordion_id(): string {
        $shortname = clean_param($this->field->shortname, PARAM_ALPHANUMEXT);
        return 'profilefield-repeatable-display-' . (int)$this->field->id . '-' . $this->userid . '-' . $shortname;
    }

    /**
     * Check whether current request is a profile page.
     *
     * @return bool
     */
    private function is_profile_page_context(): bool {
        $script = (string)($GLOBALS['SCRIPT'] ?? '');
        if ($script !== '') {
            return in_array($script, ['/user/profile.php', '/user/view.php'], true);
        }

        if ($this->page && $this->page->has_set_url()) {
            $path = $this->page->url->get_path();
            return in_array($path, ['/user/profile.php', '/user/view.php'], true);
        }

        return false;
    }
}
