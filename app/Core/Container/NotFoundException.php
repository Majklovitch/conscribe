<?php

namespace App\Core\Container;

use Psr\Container\NotFoundExceptionInterface;

/**
 * Nothing is registered under this identifier and it is not a class the
 * container could build on its own.
 */
class NotFoundException extends ContainerException implements NotFoundExceptionInterface {
}
