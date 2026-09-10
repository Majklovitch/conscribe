<?php

namespace App\Core\Container;

use Psr\Container\ContainerExceptionInterface;
use RuntimeException;

/**
 * Something went wrong while building an entry: a parameter that cannot be
 * resolved, a circular dependency, a factory that threw.
 */
class ContainerException extends RuntimeException implements ContainerExceptionInterface {
}
