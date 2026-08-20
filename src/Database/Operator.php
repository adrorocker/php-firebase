<?php

declare(strict_types=1);

namespace PhpFirebase\Database;

/**
 * The comparisons a {@see Condition} can express.
 *
 * Every backend supports equality and the four range operators. The remaining
 * cases are Firestore features; a connection that cannot serve one throws
 * {@see \PhpFirebase\Exception\UnsupportedQuery} rather than quietly returning
 * the wrong rows.
 */
enum Operator: string
{
    case Equal = '=';
    case LessThan = '<';
    case LessThanOrEqual = '<=';
    case GreaterThan = '>';
    case GreaterThanOrEqual = '>=';
    case In = 'in';
    case Contains = 'contains';
}
