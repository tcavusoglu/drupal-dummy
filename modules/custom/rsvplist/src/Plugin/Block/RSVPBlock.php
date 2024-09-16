<?php

/**
 * @file
 * Creates a block which displays the RSVPForm contained in RSVPForm.php
 */

namespace Drupal\rsvplist\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;

/**
 * Provides the RSVP main block.
 *
 */
#[Block(
  id: "rsvp_block",
  admin_label: new TranslatableMarkup("The RSVP Block"),
)]
class RSVPBlock extends BlockBase
{
  /**
   * {@inheritdoc}
   */
  public function build()
  {
    //return [
    //  '#type' => 'markup',
    //  '#markup' => t('My RSVP List Block.'),
    //];
    return \Drupal::formBuilder()->getForm('Drupal\rsvplist\Form\RSVPForm');
  }

  /**
   * {@inheritdoc}
   */
  public function blockAccess(AccountInterface $account)
  {
    $node = \Drupal::routeMatch()->getParameter('node');

    if (!is_null($node)) {
      $enabler = \Drupal::service('rsvplist.enabler');
      if ($enabler->isEnabled($node)) {
        return AccessResult::allowedIfHasPermission($account, 'view rsvplist');
      }
    }

    return AccessResult::forbidden();
  }
}
