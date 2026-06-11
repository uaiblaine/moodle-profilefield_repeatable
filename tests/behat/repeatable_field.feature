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
      | datatype   | shortname | name      | param1          |
      | repeatable | languages | Languages | Spoken language |

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
