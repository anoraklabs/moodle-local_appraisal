@local @local_appraisal
Feature: Reach another person's appraisals through each of the three access gates
  In order to keep appraisals private to the right people
  As an appraiser, an administrator and the person appraised
  We need each access gate to open the right pages to the right people and to nobody else

  # Behat fails any step that loads an error page, so a refusal is shown by the link that is missing from the profile
  # page. The refusals themselves are covered by PHPUnit (access_test, cycles_test).
  Background:
    Given the following "users" exist:
      | username | firstname | lastname  |
      | alice    | Alice     | Appraiser |
      | ellie    | Ellie     | Employee  |
      | uma      | Uma       | Unrelated |
    And the following "cohorts" exist:
      | name       | idnumber   |
      | Appraisers | appraisers |
      | Team       | team       |
    And the following "cohort members" exist:
      | user  | cohort     |
      | alice | appraisers |
      | ellie | team       |
    And the following "local_appraisal > templates" exist:
      | name          |
      | Annual review |

  @javascript @accessibility
  Scenario: A cohort link lets appraisers reach the people in the linked cohort, one way only
    Given the following "local_appraisal > cohortlinks" exist:
      | appraisercohort | appraiseecohort |
      | appraisers      | team            |
    When I am on the "alice" "user > Profile" page logged in as "alice"
    Then I should see "People I appraise"
    When I am on the "local_appraisal > People I appraise" page
    Then I should see "Ellie Employee"
    When I click on "Ellie Employee" "link"
    Then I should see "Assign template"
    And the page should meet accessibility standards
    # The link works one way, and someone in neither cohort appraises nobody.
    When I am on the "ellie" "user > Profile" page logged in as "ellie"
    Then I should see "My appraisals"
    And I should not see "People I appraise"
    When I am on the "uma" "user > Profile" page logged in as "uma"
    Then I should not see "People I appraise"
    # Deleting the link closes the gate at once.
    When I log in as "admin"
    And I navigate to "Plugins > Local plugins > Appraisals > Appraiser cohorts" in site administration
    Then I should see "Appraisers" in the "Team" "table_row"
    And the page should meet accessibility standards
    When I open the action menu in "Team" "table_row"
    And I choose "Delete" in the open action menu
    Then the page should meet accessibility standards
    And I press "Continue"
    Then I should see "Link deleted."
    When I am on the "alice" "user > Profile" page logged in as "alice"
    Then I should not see "People I appraise"

  @javascript
  Scenario: The appraiser profile field lets a named person reach the person whose profile names them
    Given the following "custom profile fields" exist:
      | datatype | shortname | name               |
      | text     | appraiser | Appraiser username |
    And the following "users" exist:
      | username | firstname | lastname | profile_field_appraiser |
      | pat      | Pat       | Profile  | alice                   |
    And I log in as "admin"
    And I navigate to "Plugins > Local plugins > Appraisals > Settings" in site administration
    And I set the following fields to these values:
      | Enable profile-based access | 1                  |
      | Appraiser profile field     | Appraiser username |
    And I press "Save changes"
    When I am on the "alice" "user > Profile" page logged in as "alice"
    Then I should see "People I appraise"
    When I am on the "local_appraisal > People I appraise" page
    Then I should see "Pat Profile"
    When I am on the "pat" "local_appraisal > Dashboard" page
    Then I should see "Assign template"
    # The field names Alice only. Switching the setting off closes the gate.
    When I am on the "uma" "user > Profile" page logged in as "uma"
    Then I should not see "People I appraise"
    When I log in as "admin"
    And I navigate to "Plugins > Local plugins > Appraisals > Settings" in site administration
    And I click on "Enable profile-based access" "checkbox"
    And I press "Save changes"
    And the field "Enable profile-based access" matches value ""
    When I am on the "alice" "user > Profile" page logged in as "alice"
    Then I should not see "People I appraise"

  Scenario: The Appraisal administrator role reaches everyone and can find any user
    Given the following "role assigns" exist:
      | user | role           | contextlevel | reference |
      | uma  | appraisaladmin | System       |           |
    When I am on the "ellie" "local_appraisal > Dashboard" page logged in as "uma"
    Then I should see "Ellie Employee"
    And I should see "Assign template"
    When I am on the "local_appraisal > People I appraise" page
    Then I should see "Find a user"
    # Someone with no gate is not an appraiser.
    When I am on the "alice" "user > Profile" page logged in as "alice"
    Then I should not see "People I appraise"
