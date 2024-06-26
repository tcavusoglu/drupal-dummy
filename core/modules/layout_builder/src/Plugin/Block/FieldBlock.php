<?php

namespace Drupal\layout_builder\Plugin\Block;

use Drupal\Component\Plugin\Factory\DefaultFactory;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityDisplayBase;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FormatterInterface;
use Drupal\Core\Field\FormatterPluginManager;
use Drupal\Core\Form\EnforcedResponseException;
use Drupal\Core\Form\FormHelper;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\ContextAwarePluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\field\FieldConfigInterface;
use Drupal\layout_builder\Plugin\Derivative\FieldBlockDeriver;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\field\FieldLabelOptionsTrait;

/**
 * Provides a block that renders a field from an entity.
 *
 * @internal
 *   Plugin classes are internal.
 */
#[Block(
  id: "field_block",
  deriver: FieldBlockDeriver::class
)]
class FieldBlock extends BlockBase implements ContextAwarePluginInterface, ContainerFactoryPluginInterface {

  use FieldLabelOptionsTrait;
  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The formatter manager.
   *
   * @var \Drupal\Core\Field\FormatterPluginManager
   */
  protected $formatterManager;

  /**
   * The entity type ID.
   *
   * @var string
   */
  protected $entityTypeId;

  /**
   * The bundle ID.
   *
   * @var string
   */
  protected $bundle;

  /**
   * The field name.
   *
   * @var string
   */
  protected $fieldName;

  /**
   * The field definition.
   *
   * @var \Drupal\Core\Field\FieldDefinitionInterface
   */
  protected $fieldDefinition;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a new FieldBlock.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\Core\Field\FormatterPluginManager $formatter_manager
   *   The formatter manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityFieldManagerInterface $entity_field_manager, FormatterPluginManager $formatter_manager, ModuleHandlerInterface $module_handler, LoggerInterface $logger) {
    $this->entityFieldManager = $entity_field_manager;
    $this->formatterManager = $formatter_manager;
    $this->moduleHandler = $module_handler;
    $this->logger = $logger;

    // Get the entity type and field name from the plugin ID.
    [, $entity_type_id, $bundle, $field_name] = explode(static::DERIVATIVE_SEPARATOR, $plugin_id, 4);
    $this->entityTypeId = $entity_type_id;
    $this->bundle = $bundle;
    $this->fieldName = $field_name;

    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_field.manager'),
      $container->get('plugin.manager.field.formatter'),
      $container->get('module_handler'),
      $container->get('logger.channel.layout_builder')
    );
  }

  /**
   * Gets the entity that has the field.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface
   *   The entity.
   */
  protected function getEntity() {
    return $this->getContextValue('entity');
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $display_settings = $this->getConfiguration()['formatter'];
    $display_settings['third_party_settings']['layout_builder']['view_mode'] = $this->getContextValue('view_mode');
    $entity = $this->getEntity();
    try {
      $build = [];
      $view = $entity->get($this->fieldName)->view($display_settings);
      if ($view) {
        $build = [$view];
      }
    }
    // @todo Remove in https://www.drupal.org/project/drupal/issues/2367555.
    catch (EnforcedResponseException $e) {
      throw $e;
    }
    catch (\Exception $e) {
      $build = [];
      $this->logger->warning('The field "%field" failed to render with the error of "%error".', ['%field' => $this->fieldName, '%error' => $e->getMessage()]);
    }
    CacheableMetadata::createFromRenderArray($build)->addCacheableDependency($this)->applyTo($build);
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function getPreviewFallbackString() {
    return new TranslatableMarkup('"@field" field', ['@field' => $this->getFieldDefinition()->getLabel()]);
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account) {
    $entity = $this->getEntity();

    // First consult the entity.
    $access = $entity->access('view', $account, TRUE);
    if (!$access->isAllowed()) {
      return $access;
    }

    // Check that the entity in question has this field.
    if (!$entity instanceof FieldableEntityInterface || !$entity->hasField($this->fieldName)) {
      return $access->andIf(AccessResult::forbidden());
    }

    // Check field access.
    $field = $entity->get($this->fieldName);
    $access = $access->andIf($field->access('view', $account, TRUE));
    if (!$access->isAllowed()) {
      return $access;
    }

    // Check to see if the field has any values or a default value.
    if ($field->isEmpty() && !$this->entityFieldHasDefaultValue()) {
      return $access->andIf(AccessResult::forbidden());
    }
    return $access;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'label_display' => FALSE,
      'formatter' => [
        'label' => 'above',
        'type' => $this->pluginDefinition['default_formatter'],
        'settings' => [],
        'third_party_settings' => [],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $config = $this->getConfiguration();

    $form['formatter'] = [
      '#tree' => TRUE,
      '#process' => [
        [$this, 'formatterSettingsProcessCallback'],
      ],
    ];
    $form['formatter']['label'] = [
      '#type' => 'select',
      '#title' => $this->t('Label'),
      '#options' => $this->getFieldLabelOptions(),
      '#default_value' => $config['formatter']['label'],
    ];

    $form['formatter']['type'] = [
      '#type' => 'select',
      '#title' => $this->t('Formatter'),
      '#options' => $this->getApplicablePluginOptions($this->getFieldDefinition()),
      '#required' => TRUE,
      '#default_value' => $config['formatter']['type'],
      '#ajax' => [
        'callback' => [static::class, 'formatterSettingsAjaxCallback'],
        'wrapper' => 'formatter-settings-wrapper',
      ],
    ];

    // Add the formatter settings to the form via AJAX.
    $form['formatter']['settings_wrapper'] = [
      '#prefix' => '<div id="formatter-settings-wrapper">',
      '#suffix' => '</div>',
    ];

    return $form;
  }

  /**
   * Render API callback: builds the formatter settings elements.
   */
  public function formatterSettingsProcessCallback(array &$element, FormStateInterface $form_state, array &$complete_form) {
    if ($formatter = $this->getFormatter($element['#parents'], $form_state)) {
      $element['settings_wrapper']['settings'] = $formatter->settingsForm($complete_form, $form_state);
      $element['settings_wrapper']['settings']['#parents'] = array_merge($element['#parents'], ['settings']);
      $element['settings_wrapper']['third_party_settings'] = $this->thirdPartySettingsForm($formatter, $this->getFieldDefinition(), $complete_form, $form_state);
      $element['settings_wrapper']['third_party_settings']['#parents'] = array_merge($element['#parents'], ['third_party_settings']);
      FormHelper::rewriteStatesSelector($element['settings_wrapper'], "fields[$this->fieldName][settings_edit_form]", 'settings[formatter]');

      // Store the array parents for our element so that we can retrieve the
      // formatter settings in our AJAX callback.
      $form_state->set('field_block_array_parents', $element['#array_parents']);
    }
    return $element;
  }

  /**
   * Adds the formatter third party settings forms.
   *
   * @param \Drupal\Core\Field\FormatterInterface $plugin
   *   The formatter.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field definition.
   * @param array $form
   *   The (entire) configuration form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   The formatter third party settings form.
   */
  protected function thirdPartySettingsForm(FormatterInterface $plugin, FieldDefinitionInterface $field_definition, array $form, FormStateInterface $form_state) {
    $settings_form = [];
    // Invoke hook_field_formatter_third_party_settings_form(), keying resulting
    // subforms by module name.
    $this->moduleHandler->invokeAllWith(
      'field_formatter_third_party_settings_form',
      function (callable $hook, string $module) use (&$settings_form, $plugin, $field_definition, $form, $form_state) {
        $settings_form[$module] = $hook(
          $plugin,
          $field_definition,
          EntityDisplayBase::CUSTOM_MODE,
          $form,
          $form_state,
        );
      }
    );
    return $settings_form;
  }

  /**
   * Render API callback: gets the layout settings elements.
   */
  public static function formatterSettingsAjaxCallback(array $form, FormStateInterface $form_state) {
    $formatter_array_parents = $form_state->get('field_block_array_parents');
    return NestedArray::getValue($form, array_merge($formatter_array_parents, ['settings_wrapper']));
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    $this->configuration['formatter'] = $form_state->getValue('formatter');
  }

  /**
   * Gets the field definition.
   *
   * @return \Drupal\Core\Field\FieldDefinitionInterface
   *   The field definition.
   */
  protected function getFieldDefinition() {
    if (empty($this->fieldDefinition)) {
      $field_definitions = $this->entityFieldManager->getFieldDefinitions($this->entityTypeId, $this->bundle);
      $this->fieldDefinition = $field_definitions[$this->fieldName];
    }
    return $this->fieldDefinition;
  }

  /**
   * Returns an array of applicable formatter options for a field.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field definition.
   *
   * @return array
   *   An array of applicable formatter options.
   *
   * @see \Drupal\field_ui\Form\EntityDisplayFormBase::getApplicablePluginOptions()
   */
  protected function getApplicablePluginOptions(FieldDefinitionInterface $field_definition) {
    $options = $this->formatterManager->getOptions($field_definition->getType());
    $applicable_options = [];
    foreach ($options as $option => $label) {
      $plugin_class = DefaultFactory::getPluginClass($option, $this->formatterManager->getDefinition($option));
      if ($plugin_class::isApplicable($field_definition)) {
        $applicable_options[$option] = $label;
      }
    }
    return $applicable_options;
  }

  /**
   * Gets the formatter object.
   *
   * @param array $parents
   *   The #parents of the element representing the formatter.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return \Drupal\Core\Field\FormatterInterface
   *   The formatter object.
   */
  protected function getFormatter(array $parents, FormStateInterface $form_state) {
    // Use the processed values, if available.
    $configuration = NestedArray::getValue($form_state->getValues(), $parents);
    if (!$configuration) {
      // Next check the raw user input.
      $configuration = NestedArray::getValue($form_state->getUserInput(), $parents);
      if (!$configuration) {
        // If no user input exists, use the default values.
        $configuration = $this->getConfiguration()['formatter'];
      }
    }

    return $this->formatterManager->getInstance([
      'configuration' => $configuration,
      'field_definition' => $this->getFieldDefinition(),
      'view_mode' => EntityDisplayBase::CUSTOM_MODE,
      'prepare' => TRUE,
    ]);
  }

  /**
   * Checks whether there is a default value set on the field.
   *
   * @return bool
   *   TRUE if default value set, FALSE otherwise.
   */
  protected function entityFieldHasDefaultValue(): bool {
    $entity = $this->getEntity();
    $field = $entity->get($this->fieldName);
    $definition = $field->getFieldDefinition();
    if ($definition->getDefaultValue($entity)) {
      return TRUE;
    }

    // @todo Remove special handling of image fields after
    // https://www.drupal.org/project/drupal/issues/3005528.
    if ($definition->getType() !== 'image') {
      return FALSE;
    }

    $default_image = $definition->getSetting('default_image');
    // If we are dealing with a configurable field, look in both instance-level
    // and field-level settings.
    if (empty($default_image['uuid']) && ($definition instanceof FieldConfigInterface)) {
      $default_image = $definition->getFieldStorageDefinition()->getSetting('default_image');
    }
    return !empty($default_image['uuid']);
  }

}
