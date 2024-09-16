<?php

/**
 * @file
 * Contains the RSVP Enabler service.
 */

namespace Drupal\rsvplist;

use Drupal\Core\Database\Connection;
use Drupal\node\Entity\Node;

class EnablerService
{

  protected $db_connection;

  public function __construct(Connection $connection)
  {
    $this->db_connection = $connection;
  }

  /**
   * Checks if an individual node is RSVP enabled.
   *
   * @param Node $node
   * @return bool|null
   *  whether or not the node is enabled for the RSVP functionality.
   */
  public function isEnabled(Node &$node)
  {
    if ($node->isNew()) {
      return FALSE;
    }

    try {
      $select = $this->db_connection->select('rsvplist_enabled', 're');
      $select->fields('re', ['nid']);
      $select->condition('nid', $node->id());
      $results = $select->execute();

      return !empty($results->fetchCol());

    } catch (\Exception $e) {
      \Drupal::messenger()->addError(t('Unable to determine RSVP settings at this time. Please try again.'));
      return NULL;
    }
  }

  /**
   * Sets an individual node to be RSVP enabled.
   *
   * @param Node $node
   * @return void
   * @throws \Exception
   */
  public function setEnabled(Node $node)
  {
    try {
      if (!$this->isEnabled($node)) {
        $insert = $this->db_connection->insert('rsvplist_enabled');
        $insert->fields(['nid']);
        $insert->values([$node->id()]);
        $insert->execute();
      }
    } catch (\Exception $s) {
      \Drupal::messenger()->addError(t('Unable to save RSVP settings at this time. Please try again.'));
    }
  }

  /**
   * Deletes RSVP enabled settings for an individual node.
   *
   * @param Node $node
   * @return void
   * @throws \Exception
   */
  public function deleteEnabled(Node $node)
  {
    try {
      $delete = $this->db_connection->delete('rsvplist_enabled');
      $delete->condition('nid', $node->id());
      $delete->execute();
    } catch (\Exception $s) {
      \Drupal::messenger()->addError(t('Unable to delete RSVP settings at this time. Please try again.'));
    }
  }
}
