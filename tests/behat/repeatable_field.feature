@profilefield @profilefield_repeatable @javascript
Feature: Repeatable profile field editing and display
  In order to keep structured repeatable data on my profile
  As a user
  I need to fill repeatable sets on the profile form and see them on my profile page

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Sam       | Student  | student1@example.com |
    And the following "custom profile fields" exist:
      | datatype   | shortname | name      | param1                                                                                                 |
      | repeatable | languages | Languages | {"version":2,"columns":[{"key":"spoken_language","label":"Spoken language","mode":"free","title":true}]} |

  Scenario: User fills a repeatable set and sees it on the profile page
    Given I log in as "student1"
    And I follow "Preferences" in the user menu
    And I follow "Edit profile"
    And I expand all fieldsets
    When I set the field "Spoken language" in the "Testing" "fieldset" to "Portuguese"
    And I press "Update profile"
    And I follow "Profile" in the user menu
    Then I should see "Repeatable fields"
    And I should see "Languages"
    And I should see "Portuguese"

  Scenario: Admin defines the columns in the column editor and a required column is enforced
    Given I log in as "admin"
    And I navigate to "Users > Accounts > User profile fields" in site administration
    And I click on "Create a new profile field" "link"
    And I click on "Repeatable set" "link"
    And I set the following fields to these values:
      | Short name | certificates |
      | Name       | Certificates |
    And I press "Add column"
    And I set the field "Column name" to "Certificate language"
    And I press "Add column"
    And I set the field "Column name" to "Level"
    And I click on "Required in every set" "checkbox"
    And I click on "Save changes" "button"
    And I should see "Certificates"
    And I open my profile in edit mode
    And I expand all fieldsets
    When I set the field "Certificate language" in the "Testing" "fieldset" to "Portuguese"
    And I press "Update profile"
    Then I should see "Set 1: Level is required."
    And I set the field "Level" in the "Testing" "fieldset" to "C2"
    And I press "Update profile"
    And I should not see "Set 1: Level is required."
