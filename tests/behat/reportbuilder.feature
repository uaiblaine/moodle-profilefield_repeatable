@profilefield @profilefield_repeatable @core_reportbuilder @javascript
Feature: Repeatable profile field sets in Report Builder
  In order to analyse repeatable profile field data
  As an administrator
  I need each stored set to appear as its own report row

  Background:
    Given the following "custom profile fields" exist:
      | datatype   | shortname | name    | param1    |
      | repeatable | lotacao   | Lotacao | diretoria |
    And the following "users" exist:
      | username | firstname | lastname | email                | profile_field_lotacao                                    |
      | teacher1 | Teacher   | One      | teacher1@example.com | {"@@ID#1":{"diretoria":"16"},"@@ID#2":{"diretoria":"99"}} |
    And the following "core_reportbuilder > Reports" exist:
      | name    | source                                                          | default |
      | My sets | profilefield_repeatable\reportbuilder\datasource\repeatable_sets | 0       |
    And the following "core_reportbuilder > Columns" exist:
      | report  | uniqueidentifier             |
      | My sets | user:fullname                |
      | My sets | repeatable_lotacao:setnumber |
      | My sets | repeatable_lotacao:subitem1  |

  Scenario: Each repeatable set is shown as its own report row
    When I am on the "My sets" "reportbuilder > Editor" page logged in as "admin"
    And I change window size to "large"
    And I press "Switch to preview mode"
    Then I should see "16" in the "reportbuilder-table" "table"
    And I should see "99" in the "reportbuilder-table" "table"
    And I should see "Teacher One" in the "reportbuilder-table" "table"
