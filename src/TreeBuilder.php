<?php

namespace Drupal\arbo;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;

class TreeBuilder {
  /**
   * @var EntityStorageInterface
   */
  protected $entityStorage;

  public function __construct(EntityStorageInterface $entityStorage) {
    $this->entityStorage = $entityStorage;
  }

  public function getEntityTrail($ids) {
    $parentFieldName = static::getParentField($this->getEntityTypeId());
    $entities = $ids ? array_values($this->entityStorage->loadMultiple($ids)) : [];

    if (!$entities) {
      return [];
    }

    $trail = [];
    foreach ($entities as $entity) {
      if (!($entity instanceof FieldableEntityInterface) || empty($parentFieldName) || !$entity->hasField($parentFieldName)) {
        return [$entity];
      }

      $parentField = $entity->get($parentFieldName);
      if (!($parentField instanceof EntityReferenceFieldItemListInterface)) {
        return [$entity];
      }

      if (empty($trail)) {
        if ($this->isRootParentField($parentField)) {
          $trail[] = $entity;
        } else {
          $trail = $this->constructEntityTrail($entity);
          if (!$trail) {
            return [];
          }
        }
      } else {
        $parentEntities = $parentField->referencedEntities();
        foreach ($parentEntities as $parentEntity) {
          if ($parentEntity->id() == $trail[count($trail) - 1]->id()) {
            $trail[] = $entity;
            continue 2;
          }
        }

        break;
      }
    }

    return $trail;
  }

  public function constructEntityTrail(FieldableEntityInterface $entity) {
    $parentFieldName = static::getParentField($this->getEntityTypeId());
    if (empty($parentFieldName)) {
      return FALSE;
    }

    $trail = [$entity];
    $ids = [$entity->id()];

    $current = $entity;
    while ($current) {
      if (!($current instanceof FieldableEntityInterface) || !$current->hasField($parentFieldName)) {
        return FALSE;
      }

      $parentField = $current->get($parentFieldName);
      if (!($parentField instanceof EntityReferenceFieldItemListInterface)) {
        return FALSE;
      }

      if ($this->isRootParentField($parentField)) {
        return $trail;
      }

      $parentEntities = $parentField->referencedEntities();
      foreach ($parentEntities as $parentEntity) {
        if (in_array($parentEntity->id(), $ids)) {
          continue;
        }

        array_unshift($trail, $parentEntity);
        $ids[] = $parentEntity->id();
        $current = $parentEntity;

        continue 2;
      }

      return FALSE;
    }

    return FALSE;
  }

  /**
   * @param FieldableEntityInterface|NULL $parent
   * @return \Drupal\Core\Entity\FieldableEntityInterface[]
   */
  public function getChildren(FieldableEntityInterface $parent = NULL, $bundles = []) {
    $keys = $this->entityStorage->getEntityType()->getKeys();
    $parentFieldName = static::getParentField($this->getEntityTypeId());

    $query = $this->entityStorage->getQuery()
      ->condition($parentFieldName, $parent ? $parent->id() : 0);

    if ($bundles) {
      $query->condition($keys['bundle'], $bundles);
    }

    $term_ids = $query->execute();

    return $term_ids ? $this->entityStorage->loadMultiple($term_ids) : [];
  }

  public function isRootParentField(EntityReferenceFieldItemListInterface $parentField) {
    if ($parentField->isEmpty()) {
      return TRUE;
    }

    foreach ($parentField as $item) {
      if (intval($item->target_id) === 0) {
        return TRUE;
      }
    }

    return FALSE;
  }

  public static function getParentField($entity_type) {
    switch ($entity_type) {
      case 'taxonomy_term':
        return 'parent';
    }
  }

  public static function getWeightField($entity_type) {
    switch ($entity_type) {
      case 'taxonomy_term':
        return 'weight';
    }
  }

  public function getEntityTypeId() {
    return $this->entityStorage->getEntityTypeId();
  }
}