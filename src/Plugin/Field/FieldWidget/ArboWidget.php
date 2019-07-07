<?php

namespace Drupal\arbo\Plugin\Field\FieldWidget;

use Drupal\arbo\TreeBuilder;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use DrupalCodeGenerator\Command\Drupal_8\Field;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'arbo' widget.
 *
 * @FieldWidget(
 *   id = "arbo",
 *   label = @Translation("Arbo"),
 *   field_types = {
 *     "entity_reference"
 *   },
 *   multiple_values = TRUE
 * )
 */
class ArboWidget extends WidgetBase implements ContainerFactoryPluginInterface {
  /**
   * @var EntityStorageInterface
   */
  protected $entityStorage;

  /**
   * @var TreeBuilder
   */
  protected $treeBuilder;

  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, array $third_party_settings, EntityStorageInterface $entityStorage) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $third_party_settings);

    $this->entityStorage = $entityStorage;
    $this->treeBuilder = new TreeBuilder($entityStorage);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['third_party_settings'],
      $container->get('entity_type.manager')->getStorage($configuration['field_definition']->getSetting('target_type'))
    );
  }

  public static function getFormStateKey(FieldItemListInterface $items) {
    return $items->getEntity()->uuid() . ':' . $items->getFieldDefinition()->getName();
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $entities = $this->formElementEntities($items, $element, $form_state);

    $ids = array_map(
      function (EntityInterface $entity) {
        return $entity->id();
      },
      $entities
    );

    $form_state->set(['arbo_widget', static::getFormStateKey($items)], $ids);

    $fieldset_id = Html::getUniqueId('edit-' . $this->fieldDefinition->getName());

    $element += [
      '#id' => $fieldset_id,
      '#type' => 'fieldset'
    ];

    $target_bundles = $this->getSelectionHandlerSetting('target_bundles');

    for ($delta = 0; $delta <= count($entities); $delta++) {
      $optionEntities = $this->treeBuilder->getChildren($delta > 0 ? $entities[$delta - 1] : NULL, $target_bundles);
      $options = [];
      foreach ($optionEntities as $optionEntity) {
        $options[$optionEntity->id()] = $optionEntity->label();
      }

      if (empty($options)) {
        break;
      }

      $element[$delta] = ['target_id' => [
        '#type' => 'select',
        '#default_value' => !empty($entities[$delta]) ? $entities[$delta]->id() : '_none',
        '#options' => $options,
        '#ajax' => [
          'callback' => [get_class($this), 'updateWidgetCallback'],
          'wrapper' => $fieldset_id,
          'event' => 'change',
        ],
        '#form_state_key' => static::getFormStateKey($items),
      ]];

      if (!$this->fieldDefinition->isRequired() || $delta > 0 || empty($entities[$delta])) {
        $element[$delta]['target_id'] += [
          '#empty_value' => '_none',
        ];
      }
    }

    return $element;
  }

  public static function updateWidgetCallback(array &$form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();

    $element_parents = array_slice($trigger['#array_parents'], 0, -2);
    $element = NestedArray::getValue($form, $element_parents);
    return $element;
  }

  protected function formElementEntities(FieldItemListInterface $items, array $element, FormStateInterface $form_state) {
    if ($form_state->has([
      'arbo_widget',
      static::getFormStateKey($items),
    ])) {
      $ids = $form_state->get([
        'arbo_widget',
        static::getFormStateKey($items),
      ]);
    } else {
      $ids = [];
      foreach ($items as $item) {
        if (!empty($item->target_id)) {
          $ids[] = $item->target_id;
        }
      }
    }

    if (($trigger = $form_state->getTriggeringElement())) {
      if (!empty($trigger['#form_state_key']) && $trigger['#form_state_key'] == static::getFormStateKey($items)) {
        $delta = intval($trigger['#array_parents'][count($trigger['#array_parents']) - 2]);
        if ($delta <= count($ids)) {
          $value = $form_state->getValue($trigger['#parents']);
          $value = $value == '_none' ? NULL : intval($value);

          if ($value) {
            $ids = array_merge(array_slice($ids, 0, $delta), [$value]);
          } else {
            $ids = array_slice($ids, 0, $delta);
          }
        }
      }
    }

    return $this->treeBuilder->getEntityTrail($ids);
  }

  protected function getSelectionHandlerSetting($setting_name) {
    $settings = $this->getFieldSetting('handler_settings');
    return isset($settings[$setting_name]) ? $settings[$setting_name] : NULL;
  }

  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    $result = [];

    foreach ($values as $item) {
      if (empty($item['target_id']) || $item['target_id'] == '_none') {
        continue;
      }

      $result[] = $item;
    }

    return $result;
  }

}
