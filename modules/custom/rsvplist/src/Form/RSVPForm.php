<?php

/**
 * @file
 * A form to collect an email address for RSVP details.
 */

namespace Drupal\rsvplist\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

class RSVPForm extends FormBase
{
  /**
   * {@inheritdoc}
   */
  public function getFormId()
  {
    return 'rsvplist_email_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state)
  {
    // Get the fully loaded node object from the viewed page and try to extract the node ID.
    $node = \Drupal::routeMatch()->getParameter('node');
    $nid = !is_null($node) ? $node->id() : 0;

    // The $form render array with email text field, submit button and a hidden field containing the node ID.
    $form['email'] = [
      '#type' => 'textfield',
      '#title' => t('Email address'),
      '#size' => 25,
      '#description' => t('We will send updates to the email address you provide.'),
      '#required' => TRUE,
    ];
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => t('RSVP'),
    ];
    $form['nid'] = [
      '#type' => 'hidden',
      '#value' => $nid,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state)
  {
    // Check if the entered value is a valid email address using Drupal's email validator service.
    $value = $form_state->getValue('email');
    if (!\Drupal::service('email.validator')->isValid($value)) {
      $form_state->setErrorByName('email', t('The email address %mail is not valid.', ['%mail' => $value]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    try {
      // Collect the parameters we are about to insert into the database.
      $uid = \Drupal::currentUser()->id();
      $nid = $form_state->getValue('nid');
      $email = $form_state->getValue('email');
      $current_time = \Drupal::time()->getRequestTime();

      // Get the database handler and build a query builder object.
      $query = \Drupal::database()->insert('rsvplist');
      $query->fields([
        'uid',
        'nid',
        'mail',
        'created',
      ]);
      $query->values([
        $uid,
        $nid,
        $email,
        $current_time,
      ]);
      $query->execute();

      \Drupal::messenger()->addMessage(t('Thank you for your RSVP, your are now on the list for the event!'));

    } catch (\Exception $e) {
      \Drupal::messenger()->addError(t('There was an error submitting your RSVP due to a database error, please try again.'));
    }
  }
}
