@profilefield @profilefield_repeatable @customfield_pattern @javascript
Feature: Pattern columns of a repeatable profile field
  In order to collect values in a fixed format, such as a CPF
  As an admin
  I need a column that masks what users type and refuses a wrong value

  # Pattern columns take their rules from customfield_pattern, which installs on Moodle 5.1 and
  # later only: every scenario is skipped where it is absent.
  Background:
    Given the "customfield_pattern" plugin is installed
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Sam       | Student  | student1@example.com |
    And the following "custom profile fields" exist:
      | datatype   | shortname | name      | param1                                                                                                 |
      | repeatable | languages | Languages | {"version":2,"columns":[{"key":"spoken_language","label":"Spoken language","mode":"free","title":true}]} |

  Scenario: Admin adds a CPF column, the user sees the mask and a wrong check digit is refused
    Given I log in as "admin"
    And I navigate to "Users > Accounts > User profile fields" in site administration
    And I click on "Create a new profile field" "link"
    And I click on "Repeatable set" "link"
    And I set the following fields to these values:
      | Short name | documents |
      | Name       | Documents |
    And I press "Add column"
    And I set the field "Column name" to "CPF"
    And I click on "Pattern" "radio"
    And I set the field "Preset" to "CPF"
    And the field "Mask" matches value "999.999.999-99"
    And I set the field "Store as" to "Raw (12345678909)"
    And I click on "Save changes" "button"
    And I should see "Documents"
    And I log out
    And I log in as "student1"
    And I open my profile in edit mode
    And I expand all fieldsets
    When I set the field "CPF" in the "Testing" "fieldset" to "52998224724"
    Then the field "CPF" in the "Testing" "fieldset" matches value "529.982.247-24"
    And I press "Update profile"
    And I should see "Set 1, CPF: This is not a valid CPF."
    And I set the field "CPF" in the "Testing" "fieldset" to "52998224725"
    And I press "Update profile"
    And I should not see "This is not a valid CPF."
    And I follow "Profile" in the user menu
    And I should see "529.982.247-25"
