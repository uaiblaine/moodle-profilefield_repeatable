@profilefield @profilefield_repeatable @local_dictionaries @javascript
Feature: Dictionary columns are picked through an autocomplete
  In order to store dictionary codes without knowing them
  As a user
  I need to find an item by its name, or type a new code where the column accepts one

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Sam       | Student  | student1@example.com |
    And the following "custom profile fields" exist:
      | datatype   | shortname | name      | param1                                                                                                                                                                                                                          |
      | repeatable | languages | Languages | {"version":2,"columns":[{"key":"spoken","label":"Spoken language","mode":"dictionary","domain":"langs","title":true},{"key":"other","label":"Other code","mode":"dictionary_open","domain":"langs","subline":true}]} |
    And I log in as "admin"
    And I navigate to "Plugins > Local plugins > Manage reference dictionaries" in site administration
    And I expand all fieldsets
    And I set the field "Domain shortname" in the "Create or update domain" "fieldset" to "langs"
    And I set the field "Domain name" in the "Create or update domain" "fieldset" to "Languages"
    And I set the field "Who can look up items" in the "Create or update domain" "fieldset" to "Any logged-in user except guests"
    And I press "Save domain"
    And I expand all fieldsets
    And I set the field "Domain shortname" in the "Import CSV" "fieldset" to "langs"
    And I set the field "CSV content (optional)" to multiline:
      """
      code;label
      pt;Portuguese
      es;Spanish
      """
    And I press "Import items"
    And I log out

  Scenario: A user picks a strict code by typing part of its name, and types a new code in an open column
    Given I log in as "student1"
    And I open my profile in edit mode
    And I expand all fieldsets
    When I set the field "Spoken language" in the "Testing" "fieldset" to "Portu"
    And I set the field "Other code" in the "Testing" "fieldset" to "zz-new"
    And I press "Update profile"
    And I follow "Profile" in the user menu
    Then I should see "Portuguese"
    And I should see "zz-new"
    And I open my profile in edit mode
    And I expand all fieldsets
    And I should see "Portuguese (pt)" in the "Testing" "fieldset"
    And I should see "New code, no name yet" in the "Testing" "fieldset"
