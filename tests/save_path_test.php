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

use local_repeatable\local\bridge\dictionaries;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/user/profile/lib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/user/profile/field/repeatable/field.class.php');

/**
 * Every save path through edit_save_data() runs the form's code checks.
 *
 * Core's web services (core_user_create_users, core_user_update_users) call profile_save_data()
 * without edit_validate_field(): an operator with moodle/user:update who cannot read a restricted
 * dictionary must not store one of its codes, or the stored code would resolve to its label.
 * Metadata stays in docblocks: the plugin supports Moodle 4.5, whose moodle-cs cannot read
 * PHPUnit attributes.
 *
 * @package    profilefield_repeatable
 * @covers     \profile_field_repeatable
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class save_path_test extends \advanced_testcase {
    /** @var \stdClass The profile field record. */
    private \stdClass $record;

    /**
     * Skip without the companion, then create the restricted dictionary and the field on it.
     *
     * 'cities' is restricted to local/dictionaries:view, which the operator lacks: 3550308 and 3304557.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        if (!dictionaries::lookup_available()) {
            $this->markTestSkipped('local_dictionaries with api::lookup() is not installed.');
        }
        $this->resetAfterTest();

        /** @var \local_dictionaries_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('local_dictionaries');
        $generator->create_domain(['shortname' => 'cities', 'visibility' => 0]);
        $generator->create_item(['domain' => 'cities', 'code' => '3550308', 'label' => 'Secretville']);
        $generator->create_item(['domain' => 'cities', 'code' => '3304557', 'label' => 'Hiddenburg']);

        $this->setAdminUser();
        /** @var \profilefield_repeatable_generator $fieldgenerator */
        $fieldgenerator = $this->getDataGenerator()->get_plugin_generator('profilefield_repeatable');
        $this->record = $fieldgenerator->create_field(
            [['key' => 'city', 'label' => 'City', 'mode' => 'dictionary', 'domain' => 'cities', 'title' => true]],
            ['shortname' => 'places', 'name' => 'Places']
        );
    }

    /**
     * A payload of sets, one city code each.
     *
     * @param string ...$codes
     * @return string
     */
    private function payload(string ...$codes): string {
        $payload = [];
        foreach (array_values($codes) as $index => $code) {
            $payload['@@ID#' . ($index + 1)] = ['city' => $code];
        }
        return json_encode($payload);
    }

    /**
     * A user who may update users (moodle/user:update in the system context) but cannot read the dictionary.
     *
     * @return \stdClass
     */
    private function operator(): \stdClass {
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('moodle/user:update', CAP_ALLOW, $roleid, \context_system::instance()->id);
        $operator = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign($roleid, $operator->id, \context_system::instance()->id);
        return $operator;
    }

    /**
     * What is stored for a user: the payload text and the projection row count.
     *
     * @param int $userid
     * @return array [payload or null, number of projection rows]
     */
    private function stored(int $userid): array {
        global $DB;
        $value = $DB->get_field('user_info_data', 'data', ['userid' => $userid, 'fieldid' => $this->record->id]);
        $rows = $DB->count_records('profilefield_repeatable_data', ['userid' => $userid, 'fieldid' => $this->record->id]);
        return [$value === false ? null : $value, $rows];
    }

    /**
     * Save a value the way core_user_update_users does after user_update_user().
     *
     * @param int $userid
     * @param string $payload
     * @return void
     */
    private function save(int $userid, string $payload): void {
        profile_save_data((object)['id' => $userid, 'profile_field_places' => $payload]);
    }

    /**
     * Assert a callable throws the uniform rejection, naming the field and nothing submitted.
     *
     * @param callable $save
     * @return void
     */
    private function assert_rejected(callable $save): void {
        try {
            $save();
            $this->fail('The save was not refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorsaverejected', $e->errorcode);
            $this->assertSame(get_string('errorsaverejected', 'profilefield_repeatable', 'Places'), $e->getMessage());
            foreach (['3304557', '3550308', 'Hiddenburg', 'Secretville', 'cities'] as $secret) {
                $this->assertStringNotContainsString($secret, $e->getMessage());
            }
        }
    }

    /**
     * An operator cannot store an unstored code of a restricted dictionary through profile_save_data().
     */
    public function test_operator_cannot_save_an_unreadable_code(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($this->operator());

        $this->assert_rejected(fn() => $this->save((int)$user->id, $this->payload('3304557')));
        $this->assertSame([null, 0], $this->stored((int)$user->id));

        // Control 1: the admin, who may read the dictionary, stores the code.
        $this->setAdminUser();
        $this->save((int)$user->id, $this->payload('3304557'));
        $this->assertSame(1, $this->stored((int)$user->id)[1]);
        $this->assertNotNull($this->stored((int)$user->id)[0]);
    }

    /**
     * The user update web service refuses the same value with a message that leaks nothing, and stores nothing.
     */
    public function test_user_update_web_service_refuses_an_unreadable_code(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($this->operator());
        $_POST['sesskey'] = sesskey();

        $result = \core_external\external_api::call_external_function('core_user_update_users', [
            'users' => [['id' => $user->id, 'customfields' => [['type' => 'places', 'value' => $this->payload('3304557')]]]],
        ], false);

        // Like the course web service, the user one reports a failed save as a warning.
        $this->assertFalse($result['error'], json_encode($result));
        $this->assertCount(1, $result['data']['warnings']);
        $warning = $result['data']['warnings'][0];
        $this->assertSame('errorsaverejected', $warning['warningcode']);
        $this->assertSame(get_string('errorsaverejected', 'profilefield_repeatable', 'Places'), $warning['message']);
        $this->assertStringNotContainsString('3304557', json_encode($result));
        $this->assertStringNotContainsString('Hiddenburg', json_encode($result));
        $this->assertSame([null, 0], $this->stored((int)$user->id));
    }

    /**
     * An operator keeps a code the user already has stored, and cannot swap it for another through a save.
     */
    public function test_operator_keeps_an_already_stored_code(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->save((int)$user->id, $this->payload('3550308'));
        $this->setUser($this->operator());

        // Control 2: the stored code is exempt from the read check on the save path, as on the form.
        $this->save((int)$user->id, $this->payload('3550308', '3550308'));
        $this->assertSame(2, $this->stored((int)$user->id)[1]);

        // The exemption covers only the stored code.
        $before = $this->stored((int)$user->id);
        $this->assert_rejected(fn() => $this->save((int)$user->id, $this->payload('3550308', '3304557')));
        $this->assertSame($before, $this->stored((int)$user->id));
    }

    /**
     * The validator is built with the stored payload, never with the submitted one: another user's
     * stored code does not open the column.
     */
    public function test_another_users_stored_code_does_not_open_the_column(): void {
        $holder = $this->getDataGenerator()->create_user();
        $user = $this->getDataGenerator()->create_user();
        $this->save((int)$holder->id, $this->payload('3550308'));
        $this->setUser($this->operator());

        $this->assert_rejected(fn() => $this->save((int)$user->id, $this->payload('3550308')));
        $this->assertSame([null, 0], $this->stored((int)$user->id));
    }
}
