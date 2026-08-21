<?php

declare(strict_types=1);

namespace PhpFirebase\Database\Firestore;

use PhpFirebase\Database\Condition;
use PhpFirebase\Database\Criteria;
use PhpFirebase\Database\Direction;
use PhpFirebase\Database\Operator;
use PhpFirebase\Database\Ordering;

/**
 * Turns a {@see Criteria} into a Firestore structured query.
 *
 * Firestore can express everything Criteria describes, so nothing is refused
 * here. It does need an index for compound queries, which it will tell you
 * about at runtime with a link that creates it.
 *
 * @internal
 */
readonly class QueryTranslator
{
    /**
     * Firestore names the document id field this way in filters and sorts.
     */
    private const string DOCUMENT_ID = '__name__';

    public function __construct(private ValueEncoder $encoder = new ValueEncoder())
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function translate(string $collection, Criteria $criteria): array
    {
        $query = ['from' => [['collectionId' => $collection]]];

        $filters = array_map($this->fieldFilter(...), $criteria->conditions);

        if (count($filters) === 1) {
            $query['where'] = $filters[0];
        } elseif (count($filters) > 1) {
            $query['where'] = ['compositeFilter' => ['op' => 'AND', 'filters' => $filters]];
        }

        if ($criteria->orderings !== []) {
            $query['orderBy'] = array_map($this->order(...), $criteria->orderings);
        }

        if ($criteria->limit !== null) {
            $query['limit'] = $criteria->limit;
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function fieldFilter(Condition $condition): array
    {
        return [
            'fieldFilter' => [
                'field' => ['fieldPath' => $this->fieldPath($condition->field)],
                'op' => $this->operator($condition->operator),
                'value' => $this->encoder->encode($condition->value),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function order(Ordering $ordering): array
    {
        return [
            'field' => ['fieldPath' => $this->fieldPath($ordering->field)],
            'direction' => $ordering->direction === Direction::Descending ? 'DESCENDING' : 'ASCENDING',
        ];
    }

    private function fieldPath(string $field): string
    {
        return $field === Ordering::KEY ? self::DOCUMENT_ID : $field;
    }

    private function operator(Operator $operator): string
    {
        return match ($operator) {
            Operator::Equal => 'EQUAL',
            Operator::LessThan => 'LESS_THAN',
            Operator::LessThanOrEqual => 'LESS_THAN_OR_EQUAL',
            Operator::GreaterThan => 'GREATER_THAN',
            Operator::GreaterThanOrEqual => 'GREATER_THAN_OR_EQUAL',
            Operator::In => 'IN',
            Operator::Contains => 'ARRAY_CONTAINS',
        };
    }
}
