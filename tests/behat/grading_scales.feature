@local @local_appraisal
Feature: Build and maintain grading scales
  In order to rate people on a shared set of levels
  As an administrator
  I need to build a scale, reorder its levels, copy it and archive it

  @javascript @accessibility
  Scenario: Build a scale, reorder its levels and duplicate it
    Given I am on the "local_appraisal > Grading scales" page logged in as "admin"
    And I should see "There are no grading scales yet."
    And the page should meet accessibility standards
    When I click on "New grading scale" "link"
    And the page should meet accessibility standards
    And I set the following fields to these values:
      | Name        | Performance rating        |
      | Description | How the year went overall |
    And I press "Save changes"
    Then I should see "Grading scale saved."
    And I should see "This grading scale has no levels yet."
    And I should see "A grading scale needs at least two levels before it can be chosen"
    # Levels are added from the top, each with a value higher than the one before.
    When I click on "Add level" "button"
    And I set the following fields in the "Add level" "dialogue" to these values:
      | Label       | Needs improvement        |
      | Value       | 1                        |
      | Description | Below what the role asks |
    And I click on "Save changes" "button" in the "Add level" "dialogue"
    Then I should see "Needs improvement"
    When I click on "Add level" "button"
    And I set the following fields in the "Add level" "dialogue" to these values:
      | Label | Meets expectations |
      | Value | 2                  |
    And I click on "Save changes" "button" in the "Add level" "dialogue"
    Then I should see "Meets expectations"
    And I should not see "A grading scale needs at least two levels before it can be chosen"
    When I click on "Add level" "button"
    And I set the following fields in the "Add level" "dialogue" to these values:
      | Label | Too low |
      | Value | 2       |
    And I click on "Save changes" "button" in the "Add level" "dialogue"
    Then I should see "Level values must be strictly ascending"
    When I set the following fields in the "Add level" "dialogue" to these values:
      | Label | Exceeds expectations |
      | Value | 3                    |
    And I click on "Save changes" "button" in the "Add level" "dialogue"
    Then I should see "Exceeds expectations"
    And "Needs improvement" "text" should appear before "Meets expectations" "text"
    And "Meets expectations" "text" should appear before "Exceeds expectations" "text"
    And the page should meet accessibility standards
    # Moving a level re-deals the values, so the swapped pair trade places and numbers.
    When I click on "Move up" "button" in the "Exceeds expectations" "list_item"
    Then "Exceeds expectations" "text" should appear before "Meets expectations" "text"
    And I should see "2.00" in the "Exceeds expectations" "list_item"
    And I should see "3.00" in the "Meets expectations" "list_item"
    # The order survives a reload.
    When I reload the page
    Then "Needs improvement" "text" should appear before "Exceeds expectations" "text"
    And "Exceeds expectations" "text" should appear before "Meets expectations" "text"
    # Duplicate from the list.
    When I am on the "local_appraisal > Grading scales" page
    Then I should see "3" in the "Performance rating" "table_row"
    And the page should meet accessibility standards
    When I open the action menu in "Performance rating" "table_row"
    And I choose "Duplicate" in the open action menu
    Then I should see "Grading scale duplicated."
    And I should see "Performance rating (copy)"
    And I should see "Exceeds expectations"
    And "Exceeds expectations" "text" should appear before "Meets expectations" "text"

  @javascript @accessibility
  Scenario: Archive, unarchive and delete a scale from the list
    Given the following "local_appraisal > scales" exist:
      | name        | levels                |
      | Performance | Low;Medium;High       |
      | Potential   | Limited;Growing;Ready |
    And I am on the "local_appraisal > Grading scales" page logged in as "admin"
    When I open the action menu in "Performance" "table_row"
    And I choose "Archive" in the open action menu
    Then I should see "Grading scale archived."
    And I should see "Archived" in the "Performance" "table_row"
    And I should not see "Archived" in the "Potential" "table_row"
    And "Potential" "text" should appear before "Performance" "text"
    When I open the action menu in "Performance" "table_row"
    And I choose "Unarchive" in the open action menu
    Then I should see "Grading scale unarchived."
    And I should not see "Archived" in the "Performance" "table_row"
    When I open the action menu in "Potential" "table_row"
    And I choose "Delete" in the open action menu
    Then I should see "Delete the grading scale \"Potential\"? This cannot be undone."
    And the page should meet accessibility standards
    When I press "Continue"
    Then I should see "Grading scale deleted."
    And I should not see "Potential"
    And I should see "Performance"

  @javascript
  Scenario: A scale used by a published template is locked
    Given the following "local_appraisal > scales" exist:
      | name        | levels          |
      | Performance | Low;Medium;High |
    And the following "local_appraisal > templates" exist:
      | name          | fields          | scale       |
      | Annual review | Key achievement | Performance |
    When I am on the "Performance" "local_appraisal > Scale" page logged in as "admin"
    Then I should see "A published or archived template version uses this grading scale"
    And "Add level" "button" should not exist
    And "Move up" "button" should not exist
    And "Delete" "link" should not exist in the "Medium" "list_item"
    And I should see "Medium"
    When I am on the "local_appraisal > Grading scales" page
    Then I should see "1" in the "Performance" "table_row"
    And "Delete" "link" should not exist in the "Performance" "table_row"

  Scenario: A scale name over 255 characters is refused
    Given I am on the "local_appraisal > Grading scales" page logged in as "admin"
    When I click on "New grading scale" "link"
    And I set the field "Name" to "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
    And I press "Save changes"
    Then I should see "Maximum of 255 characters"
    And I am on the "local_appraisal > Grading scales" page
    And I should see "There are no grading scales yet."
