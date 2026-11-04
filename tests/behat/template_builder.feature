@local @local_appraisal
Feature: Build and publish appraisal templates
  In order to appraise people against an agreed form
  As an administrator
  I need to build a template with every kind of field, organise it and publish it

  Background:
    Given the following "local_appraisal > scales" exist:
      | name        | levels                                                     |
      | Performance | Needs improvement;Meets expectations;Exceeds expectations |
    # Axe never finishes on a page with TinyMCE's iframe, so the plain editor lets it check the plugin's own markup.
    And the following config values are set as admin:
      | texteditors | textarea |

  @javascript @accessibility
  Scenario: Build a template with every field type, a section and a reorder, then publish it
    Given I am on the "local_appraisal > Templates" page logged in as "admin"
    And I should see "There are no templates yet."
    And the page should meet accessibility standards
    When I click on "New template" "link"
    Then the page should meet accessibility standards
    And I set the field "Template name" to "Annual review"
    And I press "Save changes"
    Then I should see "Version 1"
    And I should see "This version has no fields yet."
    And I should see "Add at least one field to publish."
    # One field of each type.
    When I click on "Add field" "button"
    Then the field "Completed by" matches value "Appraiser"
    And I set the following fields in the "Add field" "dialogue" to these values:
      | Label        | Key achievement |
      | Field type   | Short text      |
      | Completed by | Appraisee       |
    And I click on "Save changes" "button" in the "Add field" "dialogue"
    Then I should see "Key achievement"
    When I click on "Add field" "button"
    And I set the following fields in the "Add field" "dialogue" to these values:
      | Label        | What went well this year? |
      | Field type   | Long text                 |
      | Completed by | Both                      |
    And I click on "Save changes" "button" in the "Add field" "dialogue"
    Then I should see "What went well this year?"
    When I click on "Add field" "button"
    And I set the following fields in the "Add field" "dialogue" to these values:
      | Label        | Where did you work? |
      | Field type   | Single choice       |
      | Display as   | Radio buttons       |
    And I set the field "Options" to multiline:
      """
      Office
      Home
      On site
      """
    And I click on "Save changes" "button" in the "Add field" "dialogue"
    Then I should see "Office · Home · On site"
    When I click on "Add field" "button"
    And I set the following fields in the "Add field" "dialogue" to these values:
      | Label      | Which strengths showed? |
      | Field type | Multiple choice         |
    And I set the field "Options" to multiline:
      """
      Teamwork
      Initiative
      Care
      """
    And I click on "Save changes" "button" in the "Add field" "dialogue"
    Then I should see "Teamwork · Initiative · Care"
    When I click on "Add field" "button"
    And I set the following fields in the "Add field" "dialogue" to these values:
      | Label         | Delivery        |
      | Field type    | Graded rating   |
      | Grading scale | Performance     |
      | Completed by  | Both            |
    And I click on "Save changes" "button" in the "Add field" "dialogue"
    Then I should see "Delivery"
    When I click on "Add field" "button"
    And I set the following fields in the "Add field" "dialogue" to these values:
      | Label          | Projects completed |
      | Field type     | Number             |
      | Minimum        | 0                  |
      | Maximum        | 50                 |
      | Decimal places | 0                  |
    And I click on "Save changes" "button" in the "Add field" "dialogue"
    Then I should see "Projects completed"
    When I click on "Add field" "button"
    And I set the following fields in the "Add field" "dialogue" to these values:
      | Label      | Review date |
      | Field type | Date        |
      | Required   | 0           |
    And I click on "Save changes" "button" in the "Add field" "dialogue"
    Then I should see "Review date"
    And "Key achievement" "text" should appear before "What went well this year?" "text"
    And "Review date" "text" should appear after "Projects completed" "text"
    # The first section takes every field; reorder one with Move up.
    When I click on "Add section" "button"
    And I set the field "Section name" to "The year"
    And I click on "Save changes" "button" in the "Add section" "dialogue"
    Then I should see "Review date" in the "//section[.//h4[normalize-space(.)='The year']]" "xpath_element"
    When I click on "Move up" "button" in the "Review date" "list_item"
    And I reload the page
    Then "Review date" "text" should appear before "Projects completed" "text"
    # Version settings: overall comments, an overall grade on a scale and a reminder.
    When I set the following fields to these values:
      | Overall comments           | Required    |
      | Overall grade              | Required    |
      | Overall grade scale        | Performance |
      | Send a due date reminder   | 1           |
      | Days before the due date   | 7           |
    And I press "Save settings"
    Then I should see "Settings saved."
    And the page should meet accessibility standards
    # Publish.
    When I click on "Publish" "button"
    Then I should see "Publish version 1 of \"Annual review\"?"
    And the page should meet accessibility standards
    When I press "Continue"
    Then I should see "Version 1 published."
    And I should see "Published"
    And I should see "Reminder 7 day(s) before the due date"
    And I should see "Required (Performance)"
    And "Add field" "button" should not exist
    And "Review date" "text" should appear before "Projects completed" "text"
    And the page should meet accessibility standards
    When I am on the "local_appraisal > Templates" page
    Then I should see "Version 1" in the "Annual review" "table_row"

  @javascript
  Scenario: Publishing is refused until the draft has a field, then a new draft copies the published version
    Given I am on the "local_appraisal > Templates" page logged in as "admin"
    When I click on "New template" "link"
    And I set the field "Template name" to "Probation review"
    And I press "Save changes"
    Then I should see "Version 1"
    And I should see "Add at least one field to publish."
    And "Publish" "button" should not be visible
    When I click on "Add field" "button"
    And I set the field "Label" to "Settled in"
    And I click on "Save changes" "button" in the "Add field" "dialogue"
    Then I should see "Settled in"
    And I should not see "Add at least one field to publish."
    When I click on "Publish" "button"
    And I press "Continue"
    Then I should see "Version 1 published."
    When I click on "Create new draft" "link"
    Then I should see "Version 2"
    And I should see "based on version 1"
    And I should see "Settled in"
    And I should see "Add field"
    When I am on the "local_appraisal > Templates" page
    Then I should see "Version 1" in the "Probation review" "table_row"
    And I should see "Draft in progress" in the "Probation review" "table_row"

  Scenario: A template name over 255 characters is refused
    Given I am on the "local_appraisal > Templates" page logged in as "admin"
    When I click on "New template" "link"
    And I set the field "Template name" to "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
    And I press "Save changes"
    Then I should see "Maximum of 255 characters"
    And I am on the "local_appraisal > Templates" page
    And I should see "There are no templates yet."
