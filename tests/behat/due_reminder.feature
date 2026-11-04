@local @local_appraisal
Feature: Remind people before an appraisal is due
  In order to finish appraisals on time
  As an appraiser and the person appraised
  We need a reminder before the due date, once, and only when it is due

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
    And the following "local_appraisal > templates" exist:
      | name          | appraiseefields | fields         | reminderdays |
      | Annual review | What went well? | Manager's view | 7            |
      | Probation     | What went well? | Manager's view | 7            |

  @javascript
  Scenario: A reminder goes to both people when the window has opened, and not before
    Given the following "local_appraisal > appraisals" exist:
      | template      | appraisee | appraiser | label       | duedate  |
      | Annual review | ellie     | alice     | 2026 review | +3 days  |
      | Probation     | ellie     | alice     | Far away    | +30 days |
    When I run the scheduled task "\local_appraisal\task\send_due_reminders"
    And I log in as "alice"
    And I am on the "local_appraisal > Notifications" page
    Then I should see "Appraisal: Annual review for Ellie Employee is due on"
    And I should not see "Appraisal: Probation for Ellie Employee"
    When I log out
    And I log in as "ellie"
    And I am on the "local_appraisal > Notifications" page
    Then I should see "Appraisal: your Annual review appraisal is due on"
    And I should not see "Appraisal: your Probation appraisal is due on"

  @javascript
  Scenario: A reminder is sent once however often the task runs
    Given the following "local_appraisal > appraisals" exist:
      | template      | appraisee | appraiser | label       | duedate  |
      | Annual review | ellie     | alice     | 2026 review | +3 days  |
    When I run the scheduled task "\local_appraisal\task\send_due_reminders"
    And I run the scheduled task "\local_appraisal\task\send_due_reminders"
    And I log in as "alice"
    And I am on the "local_appraisal > Notifications" page
    Then I should see "1" occurrences of "Appraisal: Annual review for Ellie Employee is due on" in the "body" "css_element"
