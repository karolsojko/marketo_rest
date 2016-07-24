<?php
/**
 * @file
 * Test methods for Marketo REST.
 */
use Behat\Behat\Tester\Exception\PendingException;
use Drupal\DrupalExtension\Context\RawDrupalContext;
use Drupal\DrupalExtension\Context\DrupalContext;
use Drupal\DrupalExtension\Context\DrushContext;
use Behat\Behat\Context\SnippetAcceptingContext;
use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Coduo\PHPMatcher\Factory\SimpleFactory;

class FeatureContext extends RawDrupalContext implements SnippetAcceptingContext {
  private $params = array();

  /** @var DrupalContext */
  private $drupalContext;

  /** @var DrushContext */
  private $drushContext;

  /** @var MarketoMockClient */
  private $client;

  // Persist POST data, request and response.
  private $data;
  private $request;
  private $response;

  /**
   * Keep track of fields so they can be cleaned up.
   *
   * @var array
   */
  protected $fields = array();

  /** @BeforeScenario */
  public function gatherContexts(BeforeScenarioScope $scope) {
    $environment = $scope->getEnvironment();
    $this->drupalContext = $environment->getContext('Drupal\DrupalExtension\Context\DrupalContext');
    $this->drushContext = $environment->getContext('Drupal\DrupalExtension\Context\DrushContext');
  }

  /**
   * Remove any created fields.
   *
   * @AfterScenario
   */
  public function cleanFields() {
    // Remove any fields that were created.
    foreach ($this->fields as $field) {
      $this->drushContext->assertDrushCommandWithArgument("field-delete", "$field --y");
    }
    $this->fields = array();
  }

  /**
   * Initializes context.
   *
   * Every scenario gets its own context instance.
   * You can also pass arbitrary arguments to the
   * context constructor through behat.yml.
   */
  public function __construct(array $parameters) {
    $this->params = $parameters;
  }

  /**
   * Resets all Marketo REST modules to their default enabled state.
   *
   * @Given all Marketo REST modules are clean
   * @Given all Marketo REST modules are clean and using :config
   */
  public function allMarketoRESTModulesClean($config = 'marketo_default_settings') {
    $module_list = array('marketo_rest', 'marketo_rest_user', 'marketo_rest_webform');

    foreach ($module_list as $module) {
      if (!module_exists($module)) {
        module_enable(array($module));
      }
    }

    $this->iPopulateConfigFromBehatYml($config);
    drupal_flush_all_caches();

    foreach ($module_list as $module) {
      if (!module_exists($module)) {
        $message = sprintf('Module "%s" could not be enabled.', $module);
        throw new \Exception($message);
      }
    }
  }

  /**
   * Reinstalls Marketo REST modules.
   *
   * @Given I reinstall all Marketo REST modules
   */
  public function reinstallMarketoRESTModules() {
    $module_list = array('marketo_rest', 'marketo_rest_user', 'marketo_rest_webform');

    $this->uninstallMarketoRESTModules();
    module_enable($module_list);
    drupal_flush_all_caches();

    foreach ($module_list as $module) {
      if (!module_exists($module)) {
        $message = sprintf('Module "%s" could not be enabled.', $module);
        throw new \Exception($message);
      }
    }
  }

  /**
   * Uninstalls all Marketo REST modules.
   *
   * @Given I uninstall all Marketo REST modules
   */
  public function uninstallMarketoRESTModules() {
    $module_list = array('marketo_rest', 'marketo_rest_user', 'marketo_rest_webform');

    module_disable($module_list);
    drupal_uninstall_modules($module_list);
    drupal_flush_all_caches();

    foreach ($module_list as $module) {
      if (module_exists($module)) {
        $message = sprintf('Module "%s" could not be uninstalled.', $module);
        throw new \Exception($message);
      }
    }
  }

  /**
   * Reinstalls the given modules and asserts that they are enabled.
   *
   * @Given the :modules module(s) is/are clean
   */
  public function assertModulesClean($modules) {
    $this->assertModulesUninstalled($modules);
    $this->assertModulesEnabled($modules);
  }

  /**
   * Asserts that the given modules are enabled
   *
   * @Given the :modules module(s) is/are enabled
   */
  public function assertModulesEnabled($modules) {
    $module_list = preg_split("/,\s*/", $modules);
    module_enable($module_list, TRUE);
    foreach ($module_list as $module) {
      if (!module_exists($module)) {
        $this->drushContext->assertDrushCommandWithArgument("pm-list", '--package="Marketo"');
        echo $this->drushContext->readDrushOutput();
        $message = sprintf('Module "%s" is not enabled.', $module);
        throw new \Exception($message);
      }
    }
  }

  /**
   * Asserts that the given modules are disabled
   *
   * @Given the :modules module(s) is/are disabled
   */
  public function assertModulesDisabled($modules) {
    $module_list = preg_split("/,\s*/", $modules);
    module_disable($module_list, TRUE);
    foreach ($module_list as $module) {
      if (module_exists($module)) {
        $this->drushContext->assertDrushCommandWithArgument("pm-list", '--package="Marketo"');
        echo $this->drushContext->readDrushOutput();
        $message = sprintf('Module "%s" is not disabled.', $module);
        throw new \Exception($message);
      }
    }
  }

  /**
   * Asserts that the given modules are uninstalled
   *
   * @Given the :modules module(s) is/are uninstalled
   */
  public function assertModulesUninstalled($modules) {
    $module_list = preg_split("/,\s*/", $modules);
    $this->assertModulesDisabled($modules);
    drupal_uninstall_modules($module_list, TRUE);
    foreach ($module_list as $module) {
      if (module_exists($module)) {
        $this->drushContext->assertDrushCommandWithArgument("pm-list", '--package="Marketo"');
        echo $this->drushContext->readDrushOutput();
        $message = sprintf('Module "%s" could not be uninstalled.', $module);
        throw new \Exception($message);
      }
    }
  }

  /**
   * Creates content of the given type and navigates to a path belonging to it.
   *
   * @Given I am accessing :path belonging to a/an :type (content) with the title :title
   */
  public function accessNodePath($path, $type, $title) {
    // @todo make this easily extensible.
    $node = (object) array(
      'title' => $title,
      'type' => $type,
      'body' => $this->getRandom()->string(255),
    );
    $saved = $this->nodeCreate($node);
    // Set internal page on the new node.
    $this->getSession()->visit($this->locatePath('/node/' . $saved->nid . $path));
  }

  /**
   * @Given Marketo REST is configured using settings from :config
   */
  public function marketoMaIsConfiguredUsingSettingsFrom($config) {
    $this->assertModulesClean("marketo_rest, marketo_rest_user, marketo_rest_webform");

    $settings = array_merge($this->params['marketo_default_settings'], $this->params[$config]);
    foreach ($settings as $key => $value) {
      variable_set($key, $value);
    }
  }

  /**
   * @Given I populate the Marketo REST config using :config
   */
  public function iPopulateConfigFromBehatYml($config) {
    $settings = array_merge($this->params['marketo_default_settings'], $this->params[$config]);
    foreach ($settings as $key => $value) {
      variable_set($key, $value);
    }
  }

  /**
   * Creates fields for the given entity type.
   * | bundle | entity | field_name    | field_type | widget_type |
   * | user   | user   | field_company | text       | text_field  |
   * | ...    | ...    | ...           | ...        | ...         |
   *
   * @Given fields:
   */
  public function createCustomUserFields(TableNode $fieldTable) {
    foreach ($fieldTable->getHash() as $fieldHash) {
      $field = (object) $fieldHash;
      array_push($this->fields, $field->field_name);
      $this->drushContext->assertDrushCommandWithArgument("field-create", "$field->bundle $field->field_name,$field->field_type,$field->widget_type --entity_type=$field->entity");
    }
  }

  /**
   * @Then Munchkin tracking should be enabled
   */
  public function assertMunchkinTrackingEnabled() {
    $enabled = $this->getSession()->evaluateScript("return (Drupal.settings.marketo_rest === undefined) ? false : Drupal.settings.marketo_rest.track;");
    if ($enabled !== TRUE) {
      throw new Exception("Munchkin tracking is excpected to be ON but is currently OFF");
    }
  }

  /**
   * @Then Munchkin tracking should not be enabled
   * @Then Munchkin tracking should be disabled
   */
  public function assertMunchkinTrackingNotEnabled() {
    $enabled = $this->getSession()->evaluateScript("return (Drupal.settings.marketo_rest === undefined) ? false : Drupal.settings.marketo_rest.track;");
    if ($enabled !== FALSE) {
      throw new Exception("Munchkin tracking is expected to be OFF but is currently ON");
    }
  }

  /**
   * @Then Munchkin associateLead action should send data
   */
  public function assertMunchkinAssociateLeadSendData(TableNode $fields) {
    $actions = $this->getSession()->evaluateScript("return Drupal.settings.marketo_rest.actions");
    if ((isset($actions[0]['action']) && $actions[0]['action'] == 'associateLead') == FALSE) {
      throw new \Exception("Munchkin associateLead did not fire as expected");
    }
    foreach ($fields->getHash() as $row) {
      if ($actions[0]['data'][$row['field']] != $row['value']) {
        $message = sprintf('Field "%s" was expected to be "%s" but was "%s".', $row['field'], $row['value'], $actions[0]['data'][$row['field']]);
        throw new \Exception($message);
      }
    }
  }

  /**
   * @Given I evaluate script:
   */
  public function iEvaluateScript(PyStringNode $script) {
    $this->getSession()->evaluateScript($script->getRaw());
  }

  /**
   * @Given I execute script:
   */
  public function iExecuteScript(PyStringNode $script) {
    $this->getSession()->executeScript($script->getRaw());
  }

  /**
   * @Given given javascript variable :variable equals :value
   */
  public function givenJavascriptVariableEquals($variable, $value) {
    $result = $this->getSession()->evaluateScript("$variable == $value");
    if ($result === FALSE) {
      throw new \Exception(sprintf("The variable '%s' was expected to be '%s' but evaluated to %s", $variable, $value, $result));
    }
  }

  /**
   * @Given given javascript variable :variable does not equal :value
   */
  public function givenJavascriptVariableDoesNotEqual($variable, $value) {
    throw new PendingException();
  }

  /**
   * @Given I take a dump
   */
  public function iTakeADump() {
    var_dump($this->params);
  }

  /**
   * @Given I have instantiated the Marketo rest client using :config
   */
  public function iHaveInstantiatedTheMarketoRestClientUsing($config = 'marketo_default_settings') {
    module_load_include('inc', 'marketo_rest', 'includes/marketo_rest.rest');

    // Set default as the rest class.
    $clientClass = 'MarketoRestClient';

    // If we are not using the REST mock client.
    if(!empty($this->params[$config]['marketo_rest_mock'])) {
      $clientClass = 'MarketoMockClient';
      module_load_include('inc', 'marketo_rest', 'tests/includes/marketo_rest.mock');
    }

    // Instantiate our client class with our init values.
    try {
      $this->client = new $clientClass(
        $this->params[$config]['marketo_rest_client_id'],
        $this->params[$config]['marketo_rest_client_secret'],
        $this->params[$config]['marketo_rest_endpoint'],
        $this->params[$config]['marketo_rest_identity']
      );
    }
    catch (Exception $e) {
      throw new Exception('Could not instantiate a new "'. $clientClass . '"" class.');
    }
  }

  /**
   * @Given /^I request an access token$/
   */
  public function iRequestAnAccessToken() {
    try {
      $this->client->getAccessToken();
    }
    catch (Exception $e) {
      throw new Exception('Error requesting access token.');
    }
  }

  /**
   * @Then I should have generated a valid Access Token Request URL
   */
  public function iShouldHaveGeneratedAValidAccessTokenRequestURL() {
    $options = $this->client->getIdentityTokenOptions();
    $query = $this->client->buildQuery($options);
    $expected_value = $this->client->getIdentityEndpoint() . '/' . MARKETO_REST_IDENTITY_API . '?' . $query;
    try {
      $request = json_decode($this->client->getLastRequest());
      if ($request->url != $expected_value) {
        $message = sprintf('URL: "%s" was not expected: "%s"', $request['url'], $expected_value);
        throw new \Exception($message);
      }
      $this->response = $this->client->getLastResponse();
    }
    catch (Exception $e) {
      throw new Exception('Could not access previous request data.');
    }
  }

  /**
   * @Then the response should contain json:
   */
  public function theResponseShouldContainJson(PyStringNode $string) {
    try {
      $expected_value = $string->getRaw();
      $response = json_decode($this->response);
      $factory = new SimpleFactory();
      $matcher = $factory->createMatcher();
      // Use the pattern matcher to verify JSON.
      if(!$matcher->match($response->data, $expected_value)) {
        $message = sprintf('JSON mismatch: ', $matcher->getError());
        throw new \Exception($message);
      }
    }
    catch (Exception $e) {
      throw new Exception('Could not access previous request data.');
    }
  }

  /**
   * @Then I have stored the access token: :expected_token
   */
  public function iHaveStoredTheAccessToken($expected_token) {
    try {
      $token = $this->client->getStoredAccessToken();
      if ($token != $expected_token) {
        $message = sprintf('Token: "%s" was not expected: "%s"', $token, $expected_token);
        throw new \Exception($message);
      }
    }
    catch (Exception $e) {
      throw new Exception('Could not access token.');
    }
  }

  /**
   * @Then /^I have stored a valid token expiry timestamp/
   */
  public function iHaveStoredAValidTokenExpiryTimestamp() {
    try {
      $expiry = $this->client->getStoredAccessTokenExpiry();
      $now = time();
      if ($now >= $expiry) {
        $message = sprintf('Token Expired: current timestamp "%s" after token expired timestamp: "%s"', $now, $expiry);
        throw new \Exception($message);
      }
    }
    catch (Exception $e) {
      throw new Exception('Could not access token expiry.');
    }
  }

  /**
   * @Given I request all fields on the lead object
   */
  public function iRequestAllFieldsOnTheLeadObject() {
    try {
      $this->client->getFields();
      $this->response = $this->client->getLastResponse();
    }
    catch (Exception $e) {
      throw new Exception('Could not set token and expiry.');
    }
  }

  /**
   * @Then the response should have success :arg1 and element :arg2 containing json:
   *
   * @param $arg1
   * @param $arg2
   * @param \Behat\Gherkin\Node\PyStringNode $string
   * @throws \Exception
   */
  public function theResponseShouldHaveSuccessAndContainJson($arg1, $arg2, PyStringNode $string) {
    try {
      $expected_value = $string->getRaw();

      // Extract the elements we want to check.
      $data = json_decode($this->response);
      $element = json_encode($data->{$arg2}[0]);
      $success = $data->{'success'};

      $factory = new SimpleFactory();
      $matcher = $factory->createMatcher();
      // Use the pattern matcher to verify JSON.
      if($success == $arg1 && !$matcher->match($element, $expected_value)) {
        $message = sprintf('JSON mismatch: ', $matcher->getError());
        throw new \Exception($message);
      }
    }
    catch (Exception $e) {
      throw new Exception('Could not access previous request data. ' . $e->getMessage());
    }
  }

  /**
   * @Given /^I have the action \'([^\']*)\' and the lookupField: \'([^\']*)\'$/
   */
  public function iHaveTheActionAndTheLookupField($arg1, $arg2) {
    $this->data = (object) ['action' => $arg1, 'lookupField' => $arg2];
  }

  /**
   * @Given /^I have the input:$/
   */
  public function iHaveTheInput(TableNode $table) {
    $rows = $table->getRows();
    $header = array_shift($rows);
    $input = array();

    // Generate array of input objects.
    foreach ($rows as $num => $row) {
      $input[$num] = new stdClass();
      foreach ($row as $key => $field) {
        $input[$num]->{$header[$key]} = $field;
      }
    }
    $this->data->input = $input;
  }

  /**
   * @When /^I sync leads$/
   */
  public function iSyncLeads() {
    try {
      $this->response = $this->client->syncLead($this->data->input, $this->data->action, $this->data->lookupField);
    }
    catch (Exception $e) {
      throw new Exception($e->getMessage());
    }
  }

  /**
   * @Then /^the return value \'([^\']*)\' should be \'([^\']*)\'$/
   */
  public function theReturnValueShouldBe($arg1, $arg2) {
    // Use the pattern matcher to verify JSON.
    if(!$this->response[$arg1] == $arg2) {
      throw new \Exception($this->response['result']);
    }
  }

}
