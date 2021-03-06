<?php
namespace Drupal\DKANExtension\Context;

use Drupal\DrupalExtension\Context\RawDrupalContext;
use Drupal\DrupalExtension\Context\DrupalContext;
use Behat\Behat\Context\SnippetAcceptingContext;
use Behat\Mink\Exception\UnsupportedDriverActionException as UnsupportedDriverActionException;
use Behat\Gherkin\Node\TableNode;
use Behat\Mink\Exception\DriverException;
use Behat\Behat\Tester\Exception\PendingException;
use \stdClass;

/**
 * Defines application features from the specific context.
 */
class DKANContext extends RawDrupalContext implements SnippetAcceptingContext {

  // Store pages to be referenced in an array.
  protected $pages = array();
  protected $groups = array();

  /**
   * Initializes context.
   *
   * Every scenario gets its own context instance.
   * You can also pass arbitrary arguments to the
   * context constructor through behat.yml.
   */
  public function __construct() {
    // Set the default timezone to NY
    date_default_timezone_set('America/New_York');
  }

  /**
   * @BeforeScenario @mail
   */
  public function beforeMail()
  {
    // Store the original system to restore after the scenario.
    echo("Setting Testing Mail System\n");
    $this->originalMailSystem = variable_get('mail_system', array('default-system' => 'DefaultMailSystem'));
    // Set the test system.
    variable_set('mail_system', array('default-system' => 'TestingMailSystem'));
    // Flush the email buffer.
    variable_set('drupal_test_email_collector', array());
  }

  /**
   * @AfterScenario @mail
   */
  public function afterMail()
  {
    echo("Restoring Mail System\n");
    // Restore the default system.
    variable_set('mail_system', $this->originalMailSystem);
    // Flush the email buffer.
    variable_set('drupal_test_email_collector', array());
  }

  /****************************
   * HELPER FUNCTIONS
   ****************************/

  /**
   * Add page to context.
   *
   * @param $page
   */
  public function addPage($page) {
    $this->pages[$page['title']] = $page;
  }

  /**
   * Get Group by name
   *
   * @param $name
   * @return Group or FALSE
   */
  private function getGroupByName($name) {
    foreach($this->groups as $group) {
      if ($group->title == $name) {
        return $group;
      }
    }
    return FALSE;
  }

  /**
   * Get Group Role ID by name
   *
   * @param $name
   * @return Group Role ID or FALSE
   */
  private function getGroupRoleByName($name) {

    $group_roles = og_get_user_roles_name();

    return array_search($name, $group_roles);
  }

  /**
   * Get Membership Status Code by name
   *
   * @param $name
   * @return Membership status code or FALSE
   */
  private function getMembershipStatusByName($name) {
    switch($name) {
      case 'Active':
        return OG_STATE_ACTIVE;
        break;
      case 'Pending':
        return OG_STATE_PENDING;
        break;
      case 'Blocked':
        return OG_STATE_BLOCKED;
        break;
      default:
        break;
    }

    return FALSE;
  }

  /**
   * Explode a comma separated string in a standard way.
   *
   */
  function explode_list($string) {
    $array = explode(',', $string);
    $array = array_map('trim', $array);
    return is_array($array) ? $array : array();
  }

  /**
   * Get dataset nid by title from context.
   *
   * @param $nodeTitle title of the node.
   * @param $type type of nodo look for.
   *
   * @return Node ID or FALSE
   */
  private function getNidByTitle($nodeTitle, $type)
  {
    $context = array();
    switch($type) {
      case 'dataset':
        $context = $this->datasets;
        break;
      case 'resource':
        $context = $this->resources;
    }

    foreach($context as $key => $node) {
      if($node->title == $nodeTitle) {
        return $key;
      }
    }
    return FALSE;
  }

  /*****************************
   * CUSTOM STEPS
   *****************************/

  /**
   * @Given pages:
   */
  public function addPages(TableNode $pagesTable) {
    foreach ($pagesTable as $pageHash) {
      // @todo Add some validation.
      $this->addPage($pageHash);
    }
  }

  /**
   * @Given I am on (the) :page page
   */
  public function iAmOnPage($page_title)
  {
    if (isset($this->pages[$page_title])) {
      $session = $this->getSession();
      $url = $this->pages[$page_title]['url'];
      $session->visit($this->locatePath($url));
      try {
        $code = $session->getStatusCode();
        if ($code < 200 || $code >= 300) {
          throw new Exception("Page $page_title ($url) visited, but it returned a non-2XX response code of $code.");
        }
      }
      catch (UnsupportedDriverActionException $e) {
        // Some drivers don't support status codes, namely Selenium2Driver so
        // just drive on.
      }
    }
    else {
      throw new Exception("Page $page_title not found in the pages array, was it added?");
    }

  }

  /**
   * @When I search for :term
   */
  public function iSearchFor($term) {
    $session = $this->getSession();
    $search_form_id = '#dkan-sitewide-dataset-search-form--2';
    $search_form = $session->getPage()->findAll('css', $search_form_id);
    if (count($search_form) == 1) {
      $search_form = array_pop($search_form);
      $search_form->fillField("search", $term);
      $search_form->pressButton("edit-submit--2");
      $results = $session->getPage()->find("css", ".view-dkan-datasets");
      if (!isset($results)) {
        throw new Exception("Search results region not found on the page.");
      }
    }
    else if(count($search_form) > 1) {
      throw new Exception("More than one search form found on the page.");
    }
    else if(count($search_form) < 1) {
      throw new Exception("No search form with the id of found on the page.");
    }
  }

  /**
   * @Then I should see a dataset called :text
   *
   * @throws \Exception
   *   If region or text within it cannot be found.
   */
  public function iShouldSeeADatasetCalled($text)
  {
    $session = $this->getSession();
    $page = $session->getPage();
    $search_region = $page->find('css', '.view-dkan-datasets');
    $search_results = $search_region->findAll('css', '.views-row');

    $found = false;
    foreach( $search_results as $search_result ) {

      $title = $search_result->find('css', 'h2');

      if ($title->getText() === $text) {
        $found = true;
      }
    }

    if (!$found) {
      throw new \Exception(sprintf("The text '%s' was not found", $text));
    }
  }

  /**
   * @Given groups:
   */
  public function addGroups(TableNode $groupsTable)
  {
    // Map readable field names to drupal field names.
    $field_map = array(
      'author' => 'author',
      'title' => 'title',
      'published' => 'published'
    );

    foreach ($groupsTable as $groupHash) {
      $node = new stdClass;
      $node->type = 'group';
      foreach($groupHash as $field => $value) {
        if(isset($field_map[$field])) {
          $drupal_field = $field_map[$field];
          $node->$drupal_field = $value;
        }
        else {
          throw new Exception(sprintf("Group field %s doesn't exist, or hasn't been mapped. See FeatureContext::addGroups for mappings.", $field));
        }
      }
      $created_node = $this->getDriver()->createNode($node);

      // Add the created node to the groups array.
      $this->groups[$created_node->nid] = $created_node;

      // Add the url to the page array for easy navigation.
      $this->addPage(array(
        'title' => $created_node->title,
        'url' => '/node/' . $created_node->nid
      ));
    }
  }

  /**
   * Creates multiple group memberships.
   *
   * Provide group membership data in the following format:
   *
   * | user  | group     | role on group        | membership status |
   * | Foo   | The Group | administrator member | Active            |
   *
   * @Given group memberships:
   */
  public function addGroupMemberships(TableNode $groupMembershipsTable)
  {
    foreach ($groupMembershipsTable->getHash() as $groupMembershipHash) {

      if (isset($groupMembershipHash['group']) && isset($groupMembershipHash['user'])) {

        $group = $this->getGroupByName($groupMembershipHash['group']);
        $user = user_load_by_name($groupMembershipHash['user']);

        // Add user to group with the proper group permissions and status
        if ($group && $user) {

          // Add the user to the group
          og_group("node", $group->nid, array(
            "entity type" => "user",
            "entity" => $user,
            "membership type" => OG_MEMBERSHIP_TYPE_DEFAULT,
            "state" => $this->getMembershipStatusByName($groupMembershipHash['membership status'])
          ));

          // Grant user roles
          $group_role = $this->getGroupRoleByName($groupMembershipHash['role on group']);
          og_role_grant("node", $group->nid, $user->uid, $group_role);

        } else {
          if (!$group) {
            throw new Exception(sprintf("No group was found with name %s.", $groupMembershipHash['group']));
          }
          if (!$user) {
            throw new Exception(sprintf("No user was found with name %s.", $groupMembershipHash['user']));
          }
        }
      } else {
        throw new Exception(sprintf("The group and user information is required."));
      }
    }
  }

  /**
   * @Given datasets:
   */
  public function addDatasets(TableNode $datasetsTable) {
    // Map readable field names to drupal field names.
    $field_map = array(
      'author' => 'author',
      'title' => 'title',
      'author' => 'uid',
      'description' => 'body',
      'language' => 'language',
      'tags' => 'field_tags',
      'publisher' => 'og_group_ref',
      'moderation' => 'workbench_moderation',
      'date' => 'created',
    );

    // Default to draft moderation state.
    $workbench_moderation_state = 'draft';

    foreach ($datasetsTable as $datasetHash) {
      $node = new stdClass();

      // Defaults
      $node->type = 'dataset';
      $node->language = LANGUAGE_NONE;
      $node->is_new = TRUE;

      foreach ($datasetHash as $key => $value) {
        if (!isset($field_map[$key])) {
          throw new Exception(sprintf("Dataset's field %s doesn't exist, or hasn't been mapped. See FeatureContext::addDatasets for mappings.", $key));
        }
        else {
          if ($key == 'author') {
            $user = user_load_by_name($value);
            if ($user) {
              $drupal_field = $field_map[$key];
              $node->$drupal_field = $user->uid;
            }

          }
          else {
            if ($key == 'tags' || $key == 'publisher') {
              $value = $this->explode_list($value);
              $drupal_field = $field_map[$key];
              $node->$drupal_field = $value;

            }
            else {
              if ($key == 'moderation') {
                $workbench_moderation_state = $value;

              }
              else {
                // Defalut behavior, map stait to field map.
                $drupal_field = $field_map[$key];
                $node->$drupal_field = $value;
              }
            }
          }
        }
      }

      $created_node = $this->getDriver()->createNode($node);

      // Make the node author as the revision author.
      // This is needed for workbench views filtering.
      $created_node->log = $created_node->uid;
      $created_node->revision_uid = $created_node->uid;
      db_update('node_revision')
        ->fields(array(
          'uid' => $created_node->uid,
        ))
        ->condition('nid', $created_node->nid, '=')
        ->execute();

      // Manage moderation state.
      // Requires this patch https://www.drupal.org/node/2393771
      workbench_moderation_moderate($created_node, $workbench_moderation_state, $created_node->uid);

      // Add the created node to the datasets array.
      $this->datasets[$created_node->nid] = $created_node;

      // Add the url to the page array for easy navigation.
      $this->addPage(array(
        'title' => $created_node->title,
        'url' => '/node/' . $created_node->nid
      ));
    }
  }

  /**
   * @Given resources:
   */
  public function addResources(TableNode $resourcesTable)
  {
    // Map readable field names to drupal field names.
    $field_map = array(
      'title' => 'title',
      'description' => 'body',
      'author' => 'uid',
      'language' => 'language',
      'format' => 'field_format',
      'dataset' => 'field_dataset_ref',
      'date' => 'created',
      'moderation' => 'workbench_moderation',
    );

    // Default to draft moderation state.
    $workbench_moderation_state = 'draft';

    foreach ($resourcesTable as $resourceHash) {
      $node = new stdClass();
      $node->type = 'resource';

      // Defaults
      $node->language = LANGUAGE_NONE;

      foreach($resourceHash as $key => $value) {
        $drupal_field = $field_map[$key];

        if(!isset($field_map[$key])) {
          throw new Exception(sprintf("Resource's field %s doesn't exist, or hasn't been mapped. See FeatureContext::addDatasets for mappings.", $key));

        } else if($key == 'author') {
          $user = user_load_by_name($value);
          if(!isset($user)) {
            $value = $user->uid;
          }
          $drupal_field = $field_map[$key];
          $node->$drupal_field = $value;

        } elseif ($key == 'format') {
          $value = $this->explode_list($value);
          $node->{$drupal_field} = $value;

        } elseif ($key == 'dataset') {
          if( $nid = $this->getNidByTitle($value, 'dataset')) {
            $node->{$drupal_field}['und'][0]['target_id'] = $nid;
          }else {
            throw new Exception(sprintf("Dataset node not found."));
          }

        } else if($key == 'moderation') {
          // No need to define 'Draft' state as it is used as default.
          $workbench_moderation_state = $value;

        } else {
          // Default behavior.
          // PHP 5.4 supported notation.
          $node->{$drupal_field} = $value;
        }
      }

      $created_node = $this->getDriver()->createNode($node);

      // Make the node author as the revision author.
      // This is needed for workbench views filtering.
      $created_node->log = $created_node->uid;
      $created_node->revision_uid = $created_node->uid;
      db_update('node_revision')
        ->fields(array(
          'uid' => $created_node->uid,
        ))
        ->condition('nid', $created_node->nid, '=')
        ->execute();

      // Manage moderation state.
      workbench_moderation_moderate($created_node, $workbench_moderation_state);

      // Add the created node to the datasets array.
      $this->resources[$created_node->nid] = $created_node;

      // Add the url to the page array for easy navigation.
      $this->addPage(array(
        'title' => $created_node->title,
        'url' => '/node/' . $created_node->nid
      ));
    }
  }
}
