<?php

/**
 * @file
 * Generates markup to be displayed. Functionality in this Controller is wired to Drupal in dummy_module.routing.yml.
 */

namespace Drupal\dummy_module\Controller;

use Drupal\Core\Controller\ControllerBase;

class DummyController extends ControllerBase
{
  public function simpleContent()
  {
    return [
      '#type' => 'markup',
      '#markup' => t('This is some Dummy text.
                            And this is a new line after the first line.'),
    ];
  }

  public function variableContent(string $name_1, string $name_2)
  {
    return [
      '#type' => 'markup',
      '#markup' => t('@name1 and @name2 have something to say to you: Hello!',
        ['@name1' => $name_1, '@name2' => $name_2]),
    ];
  }
}
