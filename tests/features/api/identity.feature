@api
Feature: Marketo REST API identity features
  In order to prove that the identity and access token methods function properly
  I need all of these tests to run successfully

  Background: Modules are clean and users are ready to test
    Given all Marketo REST modules are clean and using "marketo_rest_test_settings"

  @api @identity
  Scenario: Ensure we request, receive and store access token correctly
    Given I have instantiated the Marketo rest client using "marketo_rest_test_settings"
    And I request an access token
    Then I should have generated a valid Access Token Request URL
    And the response should contain json:
    """
    {
    "access_token":@string@,
    "token_type":"bearer",
    "expires_in":@integer@,
    "scope":@string@
    }
    """
    Then I have stored the access token: "88888888-4444-4444-4444-121212121212:ab"
    And I have stored a valid token expiry timestamp
