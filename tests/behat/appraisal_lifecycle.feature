@local @local_appraisal
Feature: Run an appraisal from assignment to sign-off
  In order to review someone's year and agree the outcome
  As an appraiser and the person appraised
  We need to assign a template, each complete our part, hand back for changes and sign off

  Background:
    Given the following "users" exist:
      | username | firstname | lastname  |
      | alice    | Alice     | Appraiser |
      | ellie    | Ellie     | Employee  |
    And the following "cohorts" exist:
      | name       | idnumber   |
      | Appraisers | appraisers |
      | Team       | team       |
    And the following "cohort members" exist:
      | user  | cohort     |
      | alice | appraisers |
      | ellie | team       |
    And the following "local_appraisal > cohortlinks" exist:
      | appraisercohort | appraiseecohort |
      | appraisers      | team            |
    And the following "local_appraisal > scales" exist:
      | name        | levels                                                     |
      | Performance | Needs improvement;Meets expectations;Exceeds expectations |
    And the following "local_appraisal > templates" exist:
      | name          | appraiseefields | fields         | comments | overallgrade | overallscale |
      | Annual review | What went well? | Manager's view | required | required     | Performance  |

  @javascript @accessibility
  Scenario: An appraiser assigns a template to someone they appraise
    Given I am on the "local_appraisal > People I appraise" page logged in as "alice"
    Then I should see "Ellie Employee"
    And the page should meet accessibility standards
    When I click on "Ellie Employee" "link"
    Then I should see "No templates have been assigned yet."
    And the page should meet accessibility standards
    When I click on "Assign template" "link"
    Then I should see "Assign a template to Ellie Employee"
    And the page should meet accessibility standards
    When I set the following fields to these values:
      | Template    | Annual review |
      | Cycle label | 2026 review   |
    And I press "Assign template"
    Then I should see "The template was assigned to Ellie Employee."
    And I should see "2026 review · Not started" in the "Annual review" "table_row"
    # The person appraised sees the same cycle on their own dashboard.
    When I am on the "ellie" "local_appraisal > Dashboard" page logged in as "ellie"
    Then I should see "2026 review · Not started" in the "Annual review" "table_row"
    And I should not see "Assign template"
    And the page should meet accessibility standards

  @javascript @accessibility
  Scenario: Both people complete their parts, the appraiser hands back, and the appraisal is signed off
    Given the following "local_appraisal > appraisals" exist:
      | template      | appraisee | appraiser | label       |
      | Annual review | ellie     | alice     | 2026 review |
    # The person appraised answers first. Their answer is saved as they type.
    When I am on the "ellie" "local_appraisal > Dashboard" page logged in as "ellie"
    And I click on "Open" "link" in the "Annual review" "table_row"
    Then I should see "Not started" in the "[data-region='record-status']" "css_element"
    And I should see "0 of 1 of your required fields complete"
    And I should see "Required fields still to complete: 1"
    And the "Submit my part" "button" should be disabled
    And I should see "Completed by the appraiser. Their answer will appear here once you have submitted your part."
    And the page should meet accessibility standards
    When I set the field with xpath "//div[@data-region='field'][contains(., 'What went well?')]//input[@data-region='control']" to "Shipped the new portal"
    Then I should see "1 of 1 of your required fields complete"
    And I should see "In progress" in the "[data-region='record-status']" "css_element"
    And the "Submit my part" "button" should be enabled
    # Reloading shows the saved answer.
    When I reload the page
    Then the field with xpath "//div[@data-region='field'][contains(., 'What went well?')]//input[@data-region='control']" matches value "Shipped the new portal"
    # The appraiser sees that the answer exists but not what it says until it is submitted.
    When I am on the "ellie" "local_appraisal > Dashboard" page logged in as "alice"
    And I click on "Open" "link" in the "Annual review" "table_row"
    Then I should see "Completed by the appraisee. Their answer will appear here once they have submitted their part."
    And I should not see "Shipped the new portal"
    And the "Sign off" "button" should be disabled
    When I set the field with xpath "//div[@data-region='field'][contains(., 'Manager')]//input[@data-region='control']" to "Reliable and thorough"
    Then I should see "Saved" in the "//div[@data-region='field'][contains(., 'Manager')]" "xpath_element"
    # The person appraised submits, which locks their answers.
    When I am on the "ellie" "local_appraisal > Dashboard" page logged in as "ellie"
    And I click on "Open" "link" in the "Annual review" "table_row"
    Then I should not see "Reliable and thorough"
    When I press "Submit my part"
    Then I should see "Submit your part of the Annual review appraisal?"
    And the page should meet accessibility standards
    When I press "Continue"
    Then I should see "Your part has been submitted."
    And I should see "Submitted" in the "[data-region='record-status']" "css_element"
    And "Submit my part" "button" should not exist
    And "//input[@data-region='control']" "xpath_element" should not exist
    And I should see "Shipped the new portal"
    And I should see "Reliable and thorough"
    # The appraiser can now read the answer, and hands the appraisal back with a note.
    When I am on the "ellie" "local_appraisal > Dashboard" page logged in as "alice"
    And I click on "Open" "link" in the "Annual review" "table_row"
    Then I should see "Shipped the new portal"
    And I should see "Submitted" in the "[data-region='record-status']" "css_element"
    And the page should meet accessibility standards
    When I press "Hand back"
    Then the page should meet accessibility standards
    And I set the field "Note to the appraisee" to "Please add some detail."
    And I press "Hand back"
    Then I should see "The appraisal was handed back to Ellie Employee."
    And I should see "In progress" in the "[data-region='record-status']" "css_element"
    # The person appraised can change their answer again, and submits a second time.
    When I am on the "ellie" "local_appraisal > Dashboard" page logged in as "ellie"
    And I click on "Open" "link" in the "Annual review" "table_row"
    Then I should see "In progress" in the "[data-region='record-status']" "css_element"
    And "Submit my part" "button" should exist
    When I set the field with xpath "//div[@data-region='field'][contains(., 'What went well?')]//input[@data-region='control']" to "Shipped the new portal and trained the team"
    Then I should see "Saved" in the "//div[@data-region='field'][contains(., 'What went well?')]//div[@data-region='save-status']" "xpath_element"
    When I press "Submit my part"
    And I press "Continue"
    Then I should see "Your part has been submitted."
    And I should see "Shipped the new portal and trained the team"
    # The appraiser writes the overall comments and signs off with the overall grade.
    When I am on the "ellie" "local_appraisal > Dashboard" page logged in as "alice"
    And I click on "Open" "link" in the "Annual review" "table_row"
    Then I should see "Shipped the new portal and trained the team"
    And the "Sign off" "button" should be disabled
    And I should see "Overall comments are required."
    When I set the field with xpath "//textarea[@data-region='comment-input']" to "A strong year."
    Then I should see "Saved" in the "[data-region='comment-status']" "css_element"
    And the "Sign off" "button" should be enabled
    When I press "Sign off"
    Then the page should meet accessibility standards
    And I set the field "Overall grade" to "Meets expectations"
    And I press "Sign off"
    Then I should see "The appraisal was signed off."
    And I should see "Completed" in the "[data-region='record-status']" "css_element"
    And I should see "A strong year."
    And I should see "Meets expectations" in the "[data-region='overall-grade']" "css_element"
    And "Sign off" "button" should not exist
    And "Hand back" "button" should not exist
    And the page should meet accessibility standards
    # The person appraised sees the outcome, and the dashboard shows the last completed cycle with its grade.
    When I am on the "ellie" "local_appraisal > Dashboard" page logged in as "ellie"
    Then I should see "Meets expectations" in the "Annual review" "table_row"
    And I should not see "2026 review · " in the "Annual review" "table_row"
    When I click on "View last completed" "link" in the "Annual review" "table_row"
    Then I should see "Completed" in the "[data-region='record-status']" "css_element"
    And I should see "A strong year."
    And I should see "Meets expectations" in the "[data-region='overall-grade']" "css_element"
